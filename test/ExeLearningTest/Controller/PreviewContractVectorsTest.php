<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\PreviewController;
use ExeLearning\Controller\PreviewSessionController;
use ExeLearning\Service\PreviewFixedResources;
use ExeLearning\Service\PreviewSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Replays the shared preview serving contract v2 conformance vectors
 * (test/fixtures/preview-contract/vectors.json, vendored verbatim from eXe core)
 * against this host's store + controllers. Ports the interpretation of the
 * reference consumer (src/routes/preview-session.conformance.spec.ts): every
 * step runs in order against ONE session, management steps as an authenticated
 * owner and serving steps with no credentials.
 *
 * This keeps protocol semantics — not just the CSP string — aligned with core.
 *
 * @covers \ExeLearning\Controller\PreviewController
 * @covers \ExeLearning\Controller\PreviewSessionController
 * @covers \ExeLearning\Service\PreviewSessionStore
 */
class PreviewContractVectorsTest extends TestCase
{
    private const OWNER = 7;

    private string $base;
    private string $distRoot;
    private PreviewSessionStore $store;
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/exe-vec-' . uniqid();
        $this->distRoot = sys_get_temp_dir() . '/exe-vec-dist-' . uniqid();
        mkdir($this->distRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->base);
        $this->removeDir($this->distRoot);
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    public function testReplaysEveryConformanceVector(): void
    {
        $vectors = json_decode(
            (string) file_get_contents(__DIR__ . '/../../fixtures/preview-contract/vectors.json'),
            true
        );
        $this->assertSame(2, $vectors['protocolVersion']);

        $this->materializeFixedResources($vectors['fixedResources']);
        $this->store = new PreviewSessionStore($this->base, new PreviewFixedResources($this->distRoot));

        $previewId = '';
        foreach ($vectors['steps'] as $step) {
            $previewId = $this->replayStep($step, $previewId);
        }
    }

    /**
     * Write each fixed resource under the distribution root and build the host's
     * manifest copy (id => {path, size}).
     *
     * @param array<string, array{path: string, content: string}> $fixedResources
     */
    private function materializeFixedResources(array $fixedResources): void
    {
        mkdir($this->distRoot . '/bundles', 0755, true);
        $manifest = [];
        foreach ($fixedResources as $id => $entry) {
            $target = $this->distRoot . '/' . $entry['path'];
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target, $entry['content']);
            $manifest[$id] = ['path' => $entry['path'], 'size' => strlen($entry['content'])];
        }
        file_put_contents(
            $this->distRoot . '/bundles/preview-fixed-resources.json',
            json_encode(['schemaVersion' => 1, 'buildVersion' => '4.0.0', 'resources' => $manifest])
        );
    }

    /**
     * @param array $step
     * @param string $previewId
     * @return string The (possibly newly captured) previewId.
     */
    private function replayStep(array $step, string $previewId): string
    {
        $method = $step['request']['method'];
        $path = str_replace('{previewId}', $previewId, $step['request']['path']);
        $id = $step['id'];

        if ($method === 'POST' && $path === '/api/preview-session') {
            $vars = $this->drive(fn($c) => $c->createAction(), $this->managementController());
            $this->assertExpect($step, $vars['status'], $vars['body']);
            return (string) $vars['body']['previewId'];
        }

        if ($method === 'POST' && $this->endsWith($path, '/assets')) {
            $vars = $this->runAssets($step['request']['body'], $previewId);
            $this->assertExpect($step, $vars['status'], $vars['body']);
            return $previewId;
        }

        if ($method === 'POST' && $this->endsWith($path, '/revisions')) {
            $vars = $this->runRevision($step['request']['body'], $previewId);
            $this->assertExpect($step, $vars['status'], $vars['body']);
            return $previewId;
        }

        if ($method === 'DELETE') {
            $controller = $this->managementController();
            $controller->setRouteParams(['id' => $previewId]);
            $vars = $this->drive(fn($c) => $c->deleteAction(), $controller);
            $this->assertExpect($step, $vars['status'], $vars['body']);
            return $previewId;
        }

        // GET serving request (no credentials).
        $this->replayServe($step, $path, $previewId);
        return $previewId;
    }

    /**
     * @param array $body The vector `assets` body ({ kind, entries }).
     * @return array{status: int, body: array}
     */
    private function runAssets(array $body, string $previewId): array
    {
        $entries = [];
        $files = [];
        foreach ($body['entries'] as $entry) {
            $entries[] = ['key' => $entry['key'], 'size' => strlen($entry['content'])];
            $files[] = $this->upload($entry['content']);
        }
        $controller = $this->managementController();
        $controller->setRequest($this->request([
            'post' => ['assets' => json_encode($entries)],
            'files' => $files,
        ]));
        $controller->setRouteParams(['id' => $previewId]);
        return $this->drive(fn($c) => $c->assetsAction(), $controller);
    }

    /**
     * @param array $body The vector `revision` body ({ kind, meta, writes }).
     * @return array{status: int, body: array}
     */
    private function runRevision(array $body, string $previewId): array
    {
        $meta = $body['meta'];
        $files = [];
        $writes = [];
        foreach ($body['writes'] ?? [] as $write) {
            $writes[] = $write['path'];
            $files[] = $this->upload($write['content']);
        }
        $meta['writes'] = $writes;
        $controller = $this->managementController();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode($meta)],
            'files' => $files,
        ]));
        $controller->setRouteParams(['id' => $previewId]);
        return $this->drive(fn($c) => $c->revisionsAction(), $controller);
    }

    private function replayServe(array $step, string $path, string $previewId): void
    {
        [$rawPath, $query] = array_pad(explode('?', $path, 2), 2, '');
        $prefix = '/preview/' . $previewId . '/';
        $file = $this->startsWith($rawPath, $prefix) ? substr($rawPath, strlen($prefix)) : '';

        $controller = new PreviewController($this->store);
        $controller->setRouteParams(['previewId' => $previewId, 'file' => $file]);
        // Always drive the controller with a request carrying the real URI path
        // AND any headers: the bare-capability-URL redirect (§4) reads the path,
        // not the route's file=index.html default.
        // TODO(re-vendor): the shared vectors.json will gain bare-root (302) and
        // malformed/multi/non-bytes Range (200-full) steps once core re-vendors
        // the §4 deltas; this harness already routes the path + query so those
        // steps replay without forking the JSON.
        $controller->setRequest($this->serveRequest($rawPath, $query, $step['request']['headers'] ?? []));
        $response = $controller->serveAction();

        $this->assertSame($step['expect']['status'], $response->getStatusCode(), 'status for ' . $step['id']);
        if (isset($step['expect']['bodyText'])) {
            $this->assertSame($step['expect']['bodyText'], $response->getContent(), 'bodyText for ' . $step['id']);
        }
        $this->assertHeaders($step, $response->getHeaders());
    }

    /**
     * Assert a management response's status + subset body.
     *
     * @param array $step
     * @param int $status
     * @param array $body
     */
    private function assertExpect(array $step, int $status, array $body): void
    {
        $this->assertSame($step['expect']['status'], $status, 'status for ' . $step['id']);
        if (isset($step['expect']['body'])) {
            $this->assertSubset($step['expect']['body'], $body, $step['id']);
        }
    }

    /**
     * Recursive subset match: lists must match exactly, objects may carry extra
     * keys (per the vector harness semantics).
     *
     * @param mixed $expected
     * @param mixed $actual
     * @param string $context
     */
    private function assertSubset($expected, $actual, string $context): void
    {
        if (is_array($expected) && array_is_list($expected)) {
            $this->assertSame($expected, $actual, 'list ' . $context);
            return;
        }
        if (is_array($expected)) {
            $this->assertIsArray($actual, 'object ' . $context);
            foreach ($expected as $key => $value) {
                $this->assertArrayHasKey($key, $actual, "$context.$key");
                $this->assertSubset($value, $actual[$key], "$context.$key");
            }
            return;
        }
        $this->assertSame($expected, $actual, 'scalar ' . $context);
    }

    /**
     * @param array $step
     * @param \Laminas\Http\Headers $headers
     */
    private function assertHeaders(array $step, $headers): void
    {
        foreach ($step['expect']['headers'] ?? [] as $name => $matcher) {
            $header = $headers->get($name);
            if (is_array($matcher) && ($matcher['absent'] ?? false) === true) {
                $this->assertNull($header, "$name must be absent in " . $step['id']);
                continue;
            }
            $this->assertNotNull($header, "$name must be present in " . $step['id']);
            $value = $header->getFieldValue();
            if (is_string($matcher)) {
                $this->assertSame($matcher, $value, "$name in " . $step['id']);
            } elseif (isset($matcher['startsWith'])) {
                $this->assertStringStartsWith($matcher['startsWith'], $value, "$name in " . $step['id']);
            } elseif (isset($matcher['contains'])) {
                $this->assertStringContainsString($matcher['contains'], $value, "$name in " . $step['id']);
            }
        }
    }

    // =========================================================================
    // helpers
    // =========================================================================

    /**
     * Run a management action and capture its status + JSON body.
     *
     * @param callable $action
     * @param PreviewSessionController $controller
     * @return array{status: int, body: array}
     */
    private function drive(callable $action, PreviewSessionController $controller): array
    {
        $result = $action($controller);
        return ['status' => $controller->getResponse()->getStatusCode(), 'body' => $result->getVariables()];
    }

    private function managementController(): PreviewSessionController
    {
        $store = $this->store;
        $controller = new class ($store) extends PreviewSessionController {
            protected function validateCsrf($request): bool
            {
                return true;
            }
        };
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());
        return $controller;
    }

    private function identity(): object
    {
        $ownerId = self::OWNER;
        return new class ($ownerId) {
            private int $ownerId;
            public function __construct(int $ownerId)
            {
                $this->ownerId = $ownerId;
            }
            public function getId(): int
            {
                return $this->ownerId;
            }
        };
    }

    /**
     * @param array{post?: array, files?: array} $opts
     */
    private function request(array $opts = []): object
    {
        return new class ($opts) {
            private array $opts;
            public function __construct(array $opts)
            {
                $this->opts = $opts;
            }
            public function isPost(): bool
            {
                return true;
            }
            public function getPost($key = null, $default = null)
            {
                return $this->opts['post'][$key] ?? $default;
            }
            public function getQuery($key = null, $default = null)
            {
                return $default;
            }
            public function getFiles()
            {
                return ['files' => $this->opts['files'] ?? []];
            }
            public function getHeaders()
            {
                return new class {
                    public function get($name)
                    {
                        return null;
                    }
                };
            }
        };
    }

    /**
     * A serving-request stub exposing getUri()->getPath()/getQuery() (for the
     * bare-URL redirect decision) and a case-insensitive getHeaders()->get()
     * (for Range / If-None-Match).
     *
     * @param string $path
     * @param string $query
     * @param array<string, string> $headers
     */
    private function serveRequest(string $path, string $query, array $headers): object
    {
        return new class ($path, $query, $headers) {
            private string $path;
            private string $query;
            private array $headers;

            public function __construct(string $path, string $query, array $headers)
            {
                $this->path = $path;
                $this->query = $query;
                $this->headers = $headers;
            }

            public function getUri()
            {
                return new class ($this->path, $this->query) {
                    private string $path;
                    private string $query;
                    public function __construct(string $path, string $query)
                    {
                        $this->path = $path;
                        $this->query = $query;
                    }
                    public function getPath(): string
                    {
                        return $this->path;
                    }
                    public function getQuery(): string
                    {
                        return $this->query;
                    }
                };
            }

            public function getHeaders()
            {
                $headers = $this->headers;
                return new class ($headers) {
                    private array $headers;
                    public function __construct(array $headers)
                    {
                        $this->headers = $headers;
                    }
                    public function get($name)
                    {
                        foreach ($this->headers as $key => $value) {
                            if (strcasecmp($key, $name) === 0) {
                                return new class ($value) {
                                    private string $value;
                                    public function __construct(string $value)
                                    {
                                        $this->value = $value;
                                    }
                                    public function getFieldValue(): string
                                    {
                                        return $this->value;
                                    }
                                };
                            }
                        }
                        return null;
                    }
                };
            }
        };
    }

    /**
     * @return array{tmp_name: string, error: int, size: int}
     */
    private function upload(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'exe-vec-upload-');
        file_put_contents($tmp, $bytes);
        $this->tmpFiles[] = $tmp;
        return ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        return substr($haystack, -strlen($needle)) === $needle;
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
