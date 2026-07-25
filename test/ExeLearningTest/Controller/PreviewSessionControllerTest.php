<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\PreviewSessionController;
use ExeLearning\Service\PreviewSnapshotStore;
use Laminas\View\Model\JsonModel;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Unit tests for the authenticated snapshot management API.
 *
 * @covers \ExeLearning\Controller\PreviewSessionController
 */
class PreviewSessionControllerTest extends TestCase
{
    private const OWNER = 42;

    private string $base;
    private PreviewSnapshotStore $store;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/exe-snapctl-' . uniqid();
        mkdir($this->base, 0700, true);
        $this->store = new PreviewSnapshotStore($this->base);
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        $this->removeDir($this->base);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeDir($dir . '/' . $entry);
        }
        @rmdir($dir);
    }

    /**
     * Build a snapshot ZIP on disk.
     */
    private function zipPath(array $entries): string
    {
        $path = $this->base . '/zip-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    /**
     * Put a snapshot ZIP in $_FILES the way PHP would after a multipart POST.
     */
    private function uploadSnapshot(array $entries = ['index.html' => 'hello']): void
    {
        $path = $this->zipPath($entries);
        $_FILES = ['snapshot' => [
            'name' => 'preview.zip',
            'type' => 'application/zip',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
        ]];
    }

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
     * @param array{isPost?: bool, post?: array} $opts
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
                return [];
            }

            public function getHeaders()
            {
                return null;
            }
        };
    }

    // =========================================================================
    // createAction — publish a whole-project snapshot
    // =========================================================================

    public function testCreateMintsACapabilityForTheUploadedSnapshot(): void
    {
        $this->uploadSnapshot(['index.html' => 'hello', 'a/b.js' => 'x']);
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());

        $result = $controller->createAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $vars = $result->getVariables();
        $this->assertTrue($vars['success']);
        $this->assertMatchesRegularExpression(PreviewSnapshotStore::UUID_RE, $vars['previewId']);
        $this->assertSame(
            'hello',
            file_get_contents($this->store->contentDir($vars['previewId']) . '/index.html')
        );
    }

    public function testCreateReplacesInPlaceWhenGivenAPreviewId(): void
    {
        $this->uploadSnapshot(['index.html' => 'first']);
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());
        $id = $controller->createAction()->getVariables()['previewId'];

        $this->uploadSnapshot(['index.html' => 'second']);
        $controller = $this->controller();
        $controller->setRequest($this->request(['post' => ['previewId' => $id]]));
        $controller->setIdentity($this->identity());
        $again = $controller->createAction()->getVariables();

        $this->assertSame($id, $again['previewId']);
        $this->assertSame('second', file_get_contents($this->store->contentDir($id) . '/index.html'));
    }

    public function testCreateWithoutAnUploadIs400(): void
    {
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());

        $controller->createAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testCreatePropagatesTheStoreRejection(): void
    {
        // ZipSafety refuses a PHP-capable entry outright, so the whole archive is
        // rejected even though it carries a valid index.
        $this->uploadSnapshot(['index.html' => 'ok', 'shell.php' => '<?php echo 1;']);
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());

        $controller->createAction();

        $this->assertSame(400, $controller->getResponse()->getStatusCode());
    }

    public function testCreateRefusesACapabilityOwnedByAnotherUser(): void
    {
        $foreign = $this->store->replace(
            self::OWNER + 1,
            $this->zipPath(['index.html' => 'theirs'])
        )['previewId'];

        $this->uploadSnapshot(['index.html' => 'mine']);
        $controller = $this->controller();
        $controller->setRequest($this->request(['post' => ['previewId' => $foreign]]));
        $controller->setIdentity($this->identity());

        $controller->createAction();

        $this->assertSame(403, $controller->getResponse()->getStatusCode());
        $this->assertSame('theirs', file_get_contents($this->store->contentDir($foreign) . '/index.html'));
    }

    public function testCreateRejectsNonPostWith405(): void
    {
        $this->uploadSnapshot();
        $controller = $this->controller();
        $controller->setRequest($this->request(['isPost' => false]));
        $controller->setIdentity($this->identity());

        $controller->createAction();

        $this->assertSame(405, $controller->getResponse()->getStatusCode());
    }

    public function testCreateRejectsAnonymousWith401(): void
    {
        $this->uploadSnapshot();
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity(null);

        $controller->createAction();

        $this->assertSame(401, $controller->getResponse()->getStatusCode());
    }

    public function testCreateRejectsInvalidCsrfWith403(): void
    {
        $this->uploadSnapshot();
        $controller = $this->controller(false);
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());

        $result = $controller->createAction();

        $this->assertSame(403, $controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('CSRF', $result->getVariables()['error']);
    }

    // =========================================================================
    // deleteAction
    // =========================================================================

    public function testDeleteRemovesTheSnapshot(): void
    {
        $id = $this->store->replace(self::OWNER, $this->zipPath(['index.html' => 'ok']))['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $id]);

        $result = $controller->deleteAction();

        $this->assertTrue($result->getVariables()['success']);
        $this->assertNull($this->store->contentDir($id));
    }

    public function testDeleteOfAnotherUsersCapabilityIs404(): void
    {
        $foreign = $this->store->replace(
            self::OWNER + 1,
            $this->zipPath(['index.html' => 'theirs'])
        )['previewId'];
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity($this->identity());
        $controller->setRouteParams(['id' => $foreign]);

        $controller->deleteAction();

        $this->assertSame(404, $controller->getResponse()->getStatusCode());
        $this->assertNotNull($this->store->contentDir($foreign));
    }

    public function testDeleteRejectsAnonymousWith401(): void
    {
        $controller = $this->controller();
        $controller->setRequest($this->request());
        $controller->setIdentity(null);
        $controller->setRouteParams(['id' => '11111111-2222-4333-8444-555555555555']);

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
}
