<?php
declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Service\PreviewSnapshotStore;
use Laminas\Http\Headers;
use Laminas\Http\Request;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** @covers \ExeLearning\Controller\PreviewController */
class PreviewControllerTest extends TestCase
{
    /** @var string */
    private $root;
    /** @var PreviewSnapshotStore */
    private $store;
    /** @var TestablePreviewController */
    private $controller;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/omeka-preview-controller-' . bin2hex(random_bytes(6));
        $this->store = new PreviewSnapshotStore($this->root);
        $this->controller = new TestablePreviewController($this->store);
        $this->controller->setIdentity(new class {
            public function getId(): int
            {
                return 7;
            }
        });
        $this->controller->addMedia(42, new \stdClass());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
        }
    }

    public function testManagementRequiresAuthenticationAndCsrf(): void
    {
        $this->controller->setIdentity(null);
        $this->controller->setRequest($this->request(false));
        $result = $this->controller->manageAction();
        $this->assertSame(401, $this->controller->getResponse()->getStatusCode());
        $this->assertFalse($result->getVariable('success'));

        $controller = new TestablePreviewController($this->store);
        $controller->setIdentity(new class {
            public function getId(): int
            {
                return 7;
            }
        });
        $controller->setRequest($this->request(false));
        $result = $controller->manageAction();
        $this->assertSame(403, $controller->getResponse()->getStatusCode());
        $this->assertFalse($result->getVariable('success'));
    }

    public function testCreatesServesAndDeletesSnapshotWithoutServingAuthentication(): void
    {
        $zipPath = $this->zip(['index.html' => '<script>window.active=true</script>']);
        $this->controller->setRequest($this->request(true, $zipPath));
        $this->controller->setRouteParams(['id' => 42]);
        $created = $this->controller->manageAction();
        $id = $created->getVariable('previewId');
        $this->assertNotEmpty($id);
        $this->assertStringEndsWith('/' . $id . '/index.html', $created->getVariable('previewUrl'));

        $public = new TestablePreviewController($this->store);
        $public->setIdentity(null);
        $public->setRouteParams(['previewId' => $id, 'file' => 'index.html']);
        $response = $public->serveAction();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<script>window.active=true</script>', $response->getContent());
        $this->assertStringContainsString(
            'sandbox allow-scripts allow-popups allow-forms allow-downloads allow-presentation',
            $response->getHeaders()->get('Content-Security-Policy')->getFieldValue()
        );
        $this->assertStringNotContainsString(
            'allow-same-origin',
            $response->getHeaders()->get('Content-Security-Policy')->getFieldValue()
        );
        $this->assertSame(
            'nosniff',
            $response->getHeaders()->get('X-Content-Type-Options')->getFieldValue()
        );

        $this->controller->setRequest($this->request(true, null, true));
        $this->controller->setRouteParams(['id' => 42, 'previewId' => $id]);
        $deleted = $this->controller->manageAction();
        $this->assertTrue($deleted->getVariable('success'));
        $this->assertSame(404, $public->serveAction()->getStatusCode());
        unlink($zipPath);
    }

    public function testMalformedCapabilityAndTraversalReturnHardened404(): void
    {
        $this->controller->setIdentity(null);
        $this->controller->setRouteParams([
            'previewId' => 'not-a-capability',
            'file' => '../index.html',
        ]);
        $response = $this->controller->serveAction();
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaders()->get('Cache-Control')->getFieldValue());
        $this->assertNull($response->getHeaders()->get('Content-Security-Policy'));
    }

    public function testManagementRejectsWrongMethodMissingUploadAndUneditableMedia(): void
    {
        $this->controller->setRequest($this->request(true, null, false, false));
        $this->controller->setRouteParams(['id' => 42]);
        $this->controller->manageAction();
        $this->assertSame(405, $this->controller->getResponse()->getStatusCode());

        $controller = $this->newController();
        $controller->setRequest($this->request(true));
        $controller->setRouteParams(['id' => 42]);
        $controller->manageAction();
        $this->assertSame(400, $controller->getResponse()->getStatusCode());

        $controller = $this->newController();
        $controller->setUserAllowed(false);
        $controller->setRequest($this->request(true));
        $controller->setRouteParams(['id' => 42]);
        $controller->manageAction();
        $this->assertSame(403, $controller->getResponse()->getStatusCode());

        $controller = $this->newController();
        $controller->setRequest($this->request(true));
        $controller->setRouteParams(['id' => 999]);
        $controller->manageAction();
        $this->assertSame(403, $controller->getResponse()->getStatusCode());
    }

    public function testManagementMapsInvalidMissingAndCrossOwnerSnapshotsToSafeErrors(): void
    {
        $invalidZip = tempnam(sys_get_temp_dir(), 'omeka-invalid-preview-');
        file_put_contents($invalidZip, 'not a zip');
        $controller = $this->newController();
        $controller->setRequest($this->request(true, $invalidZip));
        $controller->setRouteParams(['id' => 42]);
        $controller->manageAction();
        $this->assertSame(400, $controller->getResponse()->getStatusCode());
        unlink($invalidZip);

        $zipPath = $this->zip(['index.html' => 'safe']);
        $missingId = '018f47e2-65b2-4b4a-8f7a-934b42e10f99';
        $controller = $this->newController();
        $controller->setRequest($this->request(true, $zipPath, false, true, $missingId));
        $controller->setRouteParams(['id' => 42]);
        $controller->manageAction();
        $this->assertSame(409, $controller->getResponse()->getStatusCode());

        $otherOwnerId = $this->store->replace(8, 42, $zipPath);
        $controller = $this->newController();
        $controller->setRequest($this->request(true, $zipPath, false, true, $otherOwnerId));
        $controller->setRouteParams(['id' => 42]);
        $controller->manageAction();
        $this->assertSame(403, $controller->getResponse()->getStatusCode());

        $controller = $this->newController();
        $controller->setRequest($this->request(true, null, true));
        $controller->setRouteParams(['id' => 42, 'previewId' => $otherOwnerId]);
        $controller->manageAction();
        $this->assertSame(403, $controller->getResponse()->getStatusCode());

        $controller->setRouteParams([
            'id' => 42,
            'previewId' => '018f47e2-65b2-4b4a-8f7a-934b42e10f98',
        ]);
        $controller->manageAction();
        $this->assertSame(404, $controller->getResponse()->getStatusCode());
        unlink($zipPath);
    }

    public function testScriptableMimeGetsCspWhileOrdinaryAssetDoesNot(): void
    {
        $zipPath = $this->zip([
            'index.html' => 'safe',
            'image.svg' => '<svg/>',
            'style.css' => 'body{}',
        ]);
        $id = $this->store->replace(7, 42, $zipPath);
        $this->controller->setIdentity(null);
        $this->controller->setRouteParams(['previewId' => $id, 'file' => 'image.svg']);
        $svg = $this->controller->serveAction();
        $this->assertNotNull($svg->getHeaders()->get('Content-Security-Policy'));

        $this->controller->setRouteParams(['previewId' => $id, 'file' => 'style.css']);
        $css = $this->controller->serveAction();
        $this->assertSame('text/css; charset=utf-8', $css->getHeaders()->get('Content-Type')->getFieldValue());
        $this->assertNull($css->getHeaders()->get('Content-Security-Policy'));
        unlink($zipPath);
    }

    private function newController(): TestablePreviewController
    {
        $controller = new TestablePreviewController($this->store);
        $controller->setIdentity(new class {
            public function getId(): int
            {
                return 7;
            }
        });
        $controller->addMedia(42, new \stdClass());
        return $controller;
    }

    private function request(
        bool $validCsrf,
        ?string $zipPath = null,
        bool $delete = false,
        bool $post = true,
        string $previewId = ''
    ): Request {
        return new class($validCsrf, $zipPath, $delete, $post, $previewId) extends Request {
            /** @var bool */
            private $validCsrf;
            /** @var string|null */
            private $zipPath;
            /** @var bool */
            private $delete;
            /** @var bool */
            private $post;
            /** @var string */
            private $previewId;

            public function __construct(
                bool $validCsrf,
                ?string $zipPath,
                bool $delete,
                bool $post,
                string $previewId
            ) {
                parent::__construct();
                $this->validCsrf = $validCsrf;
                $this->zipPath = $zipPath;
                $this->delete = $delete;
                $this->post = $post;
                $this->previewId = $previewId;
            }

            public function isPost(): bool
            {
                return $this->post && !$this->delete;
            }

            public function isDelete(): bool
            {
                return $this->delete;
            }

            public function getHeaders(): Headers
            {
                $headers = new Headers();
                if ($this->validCsrf) {
                    $headers->addHeaderLine('X-CSRF-Token', 'valid');
                }
                return $headers;
            }

            public function getPost(string $name, $default = null)
            {
                if ($name === 'previewId') {
                    return $this->previewId;
                }
                return $default;
            }

            public function getFiles(): array
            {
                return $this->zipPath === null ? [] : [
                    'snapshot' => [
                        'error' => UPLOAD_ERR_OK,
                        'tmp_name' => $this->zipPath,
                    ],
                ];
            }
        };
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'omeka-preview-controller-zip-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }
}
