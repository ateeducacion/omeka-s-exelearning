<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\PreviewSessionController;
use ExeLearning\Service\PreviewFixedResources;
use ExeLearning\Service\PreviewSessionStore;
use Laminas\View\Model\JsonModel;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the authenticated preview-session management controller
 * (serving contract v2). Auth guards and CSRF live in CsrfValidationTrait
 * (covered by ApiControllerTest); here the CSRF check is stubbed to isolate the
 * management flows, except the dedicated missing-token test which runs the real
 * trait.
 *
 * @covers \ExeLearning\Controller\PreviewSessionController
 */
class PreviewSessionControllerTest extends TestCase
{
    private const OWNER = 42;
    private const PHOTO_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';

    private string $base;
    private string $distRoot;
    private PreviewSessionStore $store;
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/exe-mgmt-' . uniqid();
        $this->distRoot = sys_get_temp_dir() . '/exe-mgmt-dist-' . uniqid();
        mkdir($this->distRoot . '/bundles', 0755, true);
        mkdir($this->distRoot . '/libs/jquery', 0755, true);
        file_put_contents($this->distRoot . '/libs/jquery/jquery.min.js', 'window.jQuery=function(){};');
        file_put_contents(
            $this->distRoot . '/bundles/preview-fixed-resources.json',
            json_encode(['schemaVersion' => 1, 'buildVersion' => '4.0.0', 'resources' => [
                'libs/jquery/jquery.min.js' => ['path' => 'libs/jquery/jquery.min.js', 'size' => 26],
            ]])
        );
        $this->store = new PreviewSessionStore($this->base, new PreviewFixedResources($this->distRoot));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->base);
        $this->removeDir($this->distRoot);
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
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

    // =========================================================================
    // createAction
    // =========================================================================

    public function testCreateReturns201WithProtocolAndLimits(): void
    {
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());

        $result = $controller->createAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertSame(201, $controller->getResponse()->getStatusCode());
        $vars = $result->getVariables();
        $this->assertMatchesRegularExpression(PreviewSessionStore::PREVIEW_ID_RE, $vars['previewId']);
        $this->assertSame(2, $vars['protocolVersion']);
        $this->assertSame(0, $vars['revision']);
        $this->assertSame(5000, $vars['limits']['maxFilesPerSession']);
    }

    public function testCreateRejectsNonPostWith405(): void
    {
        $controller = $this->controller();
        $controller->setRequest($this->request(['isPost' => false]));
        $controller->setIdentity($this->identity());

        $controller->createAction();

        $this->assertSame(405, $controller->getResponse()->getStatusCode());
    }

    public function testCreateRejectsAnonymousWith401(): void
    {
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity(null);

        $controller->createAction();

        $this->assertSame(401, $controller->getResponse()->getStatusCode());
    }

    public function testCreateRejectsInvalidCsrfWith403(): void
    {
        $controller = $this->controller(false);
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());

        $result = $controller->createAction();

        $this->assertSame(403, $controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('CSRF', $result->getVariables()['error']);
    }

    // =========================================================================
    // assetsAction
    // =========================================================================

    public function testAssetsStoresUploadedFiles(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['assets' => json_encode([['key' => self::PHOTO_KEY, 'size' => 5]])],
            'files' => [$this->upload('PHOTO')],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->assetsAction();

        $vars = $result->getVariables();
        $this->assertSame([self::PHOTO_KEY], $vars['stored']);
        $this->assertSame([], $vars['alreadyStored']);
        $this->assertSame([], $vars['rejected']);
    }

    public function testAssetsRejectsMalformedAssetsFieldWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request(['post' => ['assets' => '{"not":"an-array"}']]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->assetsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testAssetsRejectsMisalignedFilesWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['assets' => json_encode([['key' => self::PHOTO_KEY, 'size' => 5]])],
            'files' => [], // no files, but one entry declared
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->assetsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testAssetsReturns404ForUnknownSession(): void
    {
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['assets' => json_encode([])],
            'files' => [],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => '11111111-2222-4333-8444-555555555555']);

        $result = $controller->assetsAction();

        $this->assertSame(404, $controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('not found', strtolower($result->getVariables()['error']));
    }

    public function testAssetsReturns403ForWrongOwner(): void
    {
        $id = $this->store->createSession(999)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['assets' => json_encode([])],
            'files' => [],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->assetsAction();

        $this->assertSame(403, $controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('denied', strtolower($result->getVariables()['error']));
    }

    public function testAssetsAcceptsPreParsedAssetsField(): void
    {
        // Some multipart parsers deliver the JSON field pre-parsed to an array.
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['assets' => [['key' => self::PHOTO_KEY, 'size' => 5]]],
            'files' => [$this->upload('PHOTO')],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->assetsAction();

        $this->assertSame([self::PHOTO_KEY], $result->getVariables()['stored']);
    }

    public function testAssetsAcceptsRawMultiFileUploadShape(): void
    {
        // Raw $_FILES multi-file shape: files => ['tmp_name' => [...], ...].
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $upload = $this->upload('PHOTO');
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['assets' => json_encode([['key' => self::PHOTO_KEY, 'size' => 5]])],
            'files' => ['tmp_name' => [$upload['tmp_name']], 'error' => [UPLOAD_ERR_OK]],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->assetsAction();

        $this->assertSame([self::PHOTO_KEY], $result->getVariables()['stored']);
    }

    public function testAssetsRejectsDeclaredOverBudgetBeforeBuffering(): void
    {
        // The declared-size total alone exceeds the session budget, so the
        // controller must 413 BEFORE reading any uploaded part into memory.
        $store = new PreviewSessionStore(
            $this->base,
            new PreviewFixedResources($this->distRoot),
            ['maxBytesPerSession' => 5]
        );
        $id = $store->createSession(self::OWNER)['previewId'];
        $controller = new class ($store) extends PreviewSessionController {
            protected function validateCsrf($request): bool
            {
                return true;
            }
        };
        $controller->setRequest($this->request([
            'post' => ['assets' => json_encode([['key' => self::PHOTO_KEY, 'size' => 100]])],
            'files' => [], // no parts buffered: the pre-check fires first
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->assetsAction();

        $this->assertSame(413, $controller->getResponse()->getStatusCode());
    }

    public function testAssetsRejectsMissingAssetsFieldWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request(['post' => []]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->assetsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testAssetsRejectsUploadErrorWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['assets' => json_encode([['key' => self::PHOTO_KEY, 'size' => 5]])],
            'files' => [['tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_INI_SIZE]],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->assetsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testAssetsWithNoFilesFieldStoresNothing(): void
    {
        // A request object without getFiles() (an empty batch) is tolerated.
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $request = new class {
            public function isPost(): bool
            {
                return true;
            }
            public function getPost($key = null, $default = null)
            {
                return $key === 'assets' ? json_encode([]) : $default;
            }
            public function getQuery($key = null, $default = null)
            {
                return $default;
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
        $controller = $this->controller();
        $controller->setRequest($request);
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->assetsAction();

        $this->assertSame([], $result->getVariables()['stored']);
    }

    // =========================================================================
    // revisionsAction
    // =========================================================================

    public function testRevisionPublishesAndReturnsActive(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $this->store->storeAssets($id, self::OWNER, [
            ['key' => self::PHOTO_KEY, 'size' => 5, 'bytes' => 'PHOTO'],
        ]);
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 0,
                'nextRevision' => 1,
                'writes' => ['index.html'],
                'deletes' => [],
                'assetRefs' => ['content/resources/photo.png' => self::PHOTO_KEY],
                'fixedRefs' => ['libs/jquery/jquery.min.js' => 'libs/jquery/jquery.min.js'],
            ])],
            'files' => [$this->upload('<html>hi</html>')],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->revisionsAction();

        $vars = $result->getVariables();
        $this->assertSame(1, $vars['revision']);
        $this->assertTrue($vars['active']);
    }

    public function testRevisionRejectsMalformedMetaWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request(['post' => ['revision' => '"not-an-object"']]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->revisionsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testRevisionRejectsNonIntegerRevisionNumbersWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 'zero', 'nextRevision' => 1, 'writes' => [], 'deletes' => [],
                'assetRefs' => [], 'fixedRefs' => [],
            ])],
            'files' => [],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->revisionsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testRevisionRejectsNonStringWritesWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html', 123],
                'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
            ])],
            'files' => [],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->revisionsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testRevisionRejectsNonStringDeletesWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
                'deletes' => [42], 'assetRefs' => [], 'fixedRefs' => [],
            ])],
            'files' => [],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->revisionsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testRevisionRejectsNonStringRefMapValuesWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
                'deletes' => [], 'assetRefs' => ['content/x.png' => 999], 'fixedRefs' => [],
            ])],
            'files' => [],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->revisionsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testRevisionRejectsMisalignedFilesWith400(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html', 'b.html'],
                'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
            ])],
            'files' => [$this->upload('only-one')],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->revisionsAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testRevisionConflictReturns409WithCurrentRevision(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $this->store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['index.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['<p>v1</p>']);

        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['index.html'],
                'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
            ])],
            'files' => [$this->upload('stale')],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->revisionsAction();

        $this->assertSame(409, $controller->getResponse()->getStatusCode());
        $vars = $result->getVariables();
        $this->assertSame('revision-conflict', $vars['reason']);
        $this->assertSame(1, $vars['currentRevision']);
    }

    public function testRevisionMissingAssetReturns422(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $ghost = '99999999-9999-4999-8999-999999999999@deadbeef';
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
                'deletes' => [], 'assetRefs' => ['content/x.png' => $ghost], 'fixedRefs' => [],
            ])],
            'files' => [],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->revisionsAction();

        $this->assertSame(422, $controller->getResponse()->getStatusCode());
        $vars = $result->getVariables();
        $this->assertSame('missing-assets', $vars['reason']);
        $this->assertSame([$ghost], $vars['missing']);
    }

    public function testRevisionUnknownFixedResourceReturns422(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
                'deletes' => [], 'assetRefs' => [], 'fixedRefs' => ['x.js' => 'libs/absent.js'],
            ])],
            'files' => [],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->revisionsAction();

        $this->assertSame(422, $controller->getResponse()->getStatusCode());
        $vars = $result->getVariables();
        $this->assertSame('unknown-fixed-resources', $vars['reason']);
        $this->assertSame(['libs/absent.js'], $vars['resources']);
    }

    public function testRevisionOverBudgetReturns413(): void
    {
        $store = new PreviewSessionStore(
            $this->base,
            new PreviewFixedResources($this->distRoot),
            ['maxBytesPerSession' => 4]
        );
        $id = $store->createSession(self::OWNER)['previewId'];
        $controller = new class ($store) extends PreviewSessionController {
            protected function validateCsrf($request): bool
            {
                return true;
            }
        };
        $controller->setRequest($this->request([
            'post' => ['revision' => json_encode([
                'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html'],
                'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
            ])],
            'files' => [$this->upload('way-too-many-bytes')],
        ]));
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $controller->revisionsAction();

        $this->assertSame(413, $controller->getResponse()->getStatusCode());
    }

    // =========================================================================
    // deleteAction
    // =========================================================================

    public function testDeleteRemovesSession(): void
    {
        $id = $this->store->createSession(self::OWNER)['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->deleteAction();

        $this->assertTrue($result->getVariables()['success']);
        $this->assertDirectoryDoesNotExist($this->base . '/' . $id);
    }

    public function testDeleteRejectsAnonymousWith401(): void
    {
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity(null);

        $controller->deleteAction();

        $this->assertSame(401, $controller->getResponse()->getStatusCode());
    }

    public function testDeleteRejectsInvalidCsrfWith403(): void
    {
        $controller = $this->controller(false);
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => '11111111-2222-4333-8444-555555555555']);

        $controller->deleteAction();

        $this->assertSame(403, $controller->getResponse()->getStatusCode());
    }

    public function testDeleteReturns404ForUnknownSession(): void
    {
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => '11111111-2222-4333-8444-555555555555']);

        $controller->deleteAction();

        $this->assertSame(404, $controller->getResponse()->getStatusCode());
    }

    // =========================================================================
    // Real CSRF trait (no stub): a request without a token is rejected
    // =========================================================================

    public function testCreateRejectsMissingCsrfTokenWithRealTrait(): void
    {
        $controller = new PreviewSessionController($this->store);
        $controller->setRequest($this->request()); // getPost/header/query all null
        $controller->setIdentity($this->identity());

        $result = $controller->createAction();

        $this->assertSame(403, $controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('CSRF', $result->getVariables()['error']);
    }

    // =========================================================================
    // helpers
    // =========================================================================

    private function controller(bool $csrfValid = true): PreviewSessionController
    {
        return new class ($this->store, $csrfValid) extends PreviewSessionController {
            private bool $csrfValid;

            public function __construct($store, bool $csrfValid)
            {
                parent::__construct($store);
                $this->csrfValid = $csrfValid;
            }

            protected function validateCsrf($request): bool
            {
                return $this->csrfValid;
            }
        };
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
            public function getName(): string
            {
                return 'Author';
            }
        };
    }

    /**
     * @param array{isPost?: bool, post?: array, files?: array} $opts
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
                return $this->opts['isPost'] ?? true;
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
     * Write an uploaded-file part to disk and return its normalized descriptor.
     *
     * @return array{tmp_name: string, error: int, size: int}
     */
    private function upload(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'exe-upload-');
        file_put_contents($tmp, $bytes);
        $this->tmpFiles[] = $tmp;
        return ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }
}
