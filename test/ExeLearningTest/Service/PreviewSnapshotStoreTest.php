<?php

declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\PreviewSnapshotStore;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Unit tests for the opaque editor-preview snapshot store.
 *
 * @covers \ExeLearning\Service\PreviewSnapshotStore
 */
class PreviewSnapshotStoreTest extends TestCase
{
    private const OWNER = 42;

    private string $base;
    private int $time = 1000000;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/exe-snapshot-' . uniqid();
        mkdir($this->base, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    private function store(int $maxFiles = 50000, int $maxBytes = 1073741824): PreviewSnapshotStore
    {
        return new PreviewSnapshotStore(
            $this->base,
            function (): int {
                return $this->time;
            },
            $maxFiles,
            $maxBytes
        );
    }

    /**
     * Build a ZIP from a path => contents map.
     */
    private function zip(array $entries): string
    {
        $path = $this->base . '/upload-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    public function testReplaceStoresTheSnapshot(): void
    {
        $result = $this->store()->replace(self::OWNER, $this->zip([
            'index.html' => 'hello',
            'assets/app.js' => 'run()',
        ]));

        $this->assertArrayHasKey('previewId', $result);
        $dir = $this->store()->contentDir($result['previewId']);
        $this->assertNotNull($dir);
        $this->assertSame('hello', file_get_contents($dir . '/index.html'));
        $this->assertSame('run()', file_get_contents($dir . '/assets/app.js'));
    }

    public function testReplaceSwapsTheWholeTree(): void
    {
        $store = $this->store();
        $id = $store->replace(self::OWNER, $this->zip([
            'index.html' => 'first',
            'stale.html' => 'gone next time',
        ]))['previewId'];

        $second = $store->replace(self::OWNER, $this->zip(['index.html' => 'second']), $id);

        $this->assertSame($id, $second['previewId']);
        $dir = $store->contentDir($id);
        $this->assertSame('second', file_get_contents($dir . '/index.html'));
        $this->assertFileDoesNotExist($dir . '/stale.html');
    }

    public function testReplaceRefusesAnotherOwner(): void
    {
        $store = $this->store();
        $id = $store->replace(self::OWNER, $this->zip(['index.html' => 'ok']))['previewId'];

        $other = $store->replace(self::OWNER + 1, $this->zip(['index.html' => 'no']), $id);

        $this->assertSame(403, $other['status']);
        $this->assertSame('ok', file_get_contents($store->contentDir($id) . '/index.html'));
    }

    public function testReplaceRefusesAnUnknownCapability(): void
    {
        $result = $this->store()->replace(
            self::OWNER,
            $this->zip(['index.html' => 'ok']),
            'ffffffff-ffff-4fff-bfff-ffffffffffff'
        );

        $this->assertSame(404, $result['status']);
    }

    /**
     * Both verbs share one verdict, so delete reports the same statuses publish
     * does for the same conditions.
     */
    public function testDeleteReportsTheSameVerdictAsPublish(): void
    {
        $store = $this->store();

        $this->assertSame(404, $store->deleteOwned('11111111-2222-4333-8444-555555555555', self::OWNER)['status']);
        $this->assertSame(400, $store->deleteOwned('not-a-uuid', self::OWNER)['status']);
    }

    public function testDeleteIsOwnerScoped(): void
    {
        $store = $this->store();
        $id = $store->replace(self::OWNER, $this->zip(['index.html' => 'ok']))['previewId'];

        $this->assertSame(403, $store->deleteOwned($id, self::OWNER + 1)['status']);
        $this->assertNotNull($store->contentDir($id));

        $this->assertNull($store->deleteOwned($id, self::OWNER));
        $this->assertNull($store->contentDir($id));
    }

    public function testIdleSnapshotsExpireAndAreSwept(): void
    {
        $store = $this->store();
        $id = $store->replace(self::OWNER, $this->zip(['index.html' => 'ok']))['previewId'];

        $this->time += PreviewSnapshotStore::TTL_SECONDS + 60;

        $this->assertNull($store->contentDir($id));
        $this->assertSame(1, $store->sweepExpired());
        $this->assertDirectoryDoesNotExist($this->base . '/' . $id);
    }

    public function testServingPushesTheIdleClockBack(): void
    {
        $store = $this->store();
        $id = $store->replace(self::OWNER, $this->zip(['index.html' => 'ok']))['previewId'];

        // Just short of the TTL: resolving must keep it alive past the original
        // deadline, so a preview in use never expires under the author.
        $this->time += PreviewSnapshotStore::TTL_SECONDS - 60;
        $this->assertNotNull($store->contentDir($id));

        $this->time += 120;
        $this->assertNotNull($store->contentDir($id));
    }

    public function testArchiveMustCarryAnIndex(): void
    {
        $result = $this->store()->replace(self::OWNER, $this->zip(['page.html' => 'orphan']));

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('index.html', $result['error']);
    }

    public function testTraversalIsRefusedBeforeAnythingIsWritten(): void
    {
        $result = $this->store()->replace(self::OWNER, $this->zip([
            'index.html' => 'ok',
            '../escape.html' => 'nope',
        ]));

        $this->assertSame(400, $result['status']);
        $this->assertFileDoesNotExist(dirname($this->base) . '/escape.html');
    }

    public function testForbiddenEntriesRejectTheWholeArchive(): void
    {
        // ZipSafety's deny-list: a PHP-capable name must never reach disk, even
        // alongside a perfectly good index.
        $result = $this->store()->replace(self::OWNER, $this->zip([
            'index.html' => 'ok',
            'shell.php' => '<?php echo 1;',
        ]));

        $this->assertSame(400, $result['status']);
    }

    public function testTheEntryCountGuardFailsClosed(): void
    {
        $result = $this->store(1)->replace(self::OWNER, $this->zip([
            'index.html' => 'a',
            'b.html' => 'b',
        ]));

        $this->assertSame(400, $result['status']);
    }

    public function testTheByteGuardMeasuresRealDecompressedBytes(): void
    {
        $result = $this->store(50000, 8)->replace(self::OWNER, $this->zip([
            'index.html' => str_repeat('x', 64),
        ]));

        $this->assertSame(400, $result['status']);
    }

    public function testARejectedUploadLeavesNoStagingBehind(): void
    {
        $this->store()->replace(self::OWNER, $this->zip(['page.html' => 'no index']));

        $leftovers = array_filter((array) scandir($this->base), static function ($entry): bool {
            return is_string($entry) && strpos($entry, '.staging-') === 0;
        });
        $this->assertSame([], array_values($leftovers));
    }
}
