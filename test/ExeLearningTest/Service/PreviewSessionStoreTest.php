<?php

declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\PreviewFixedResources;
use ExeLearning\Service\PreviewSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the file-backed preview session store (serving contract v2).
 *
 * @covers \ExeLearning\Service\PreviewSessionStore
 */
class PreviewSessionStoreTest extends TestCase
{
    private const OWNER = 42;
    private const PHOTO_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';
    private const CLIP_KEY = '12345678-90ab-4cde-8f01-234567890abc@00112233';

    private string $base;
    private string $distRoot;
    private int $time = 1000000;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/exe-store-' . uniqid();
        $this->distRoot = sys_get_temp_dir() . '/exe-store-dist-' . uniqid();
        mkdir($this->distRoot . '/bundles', 0755, true);
        mkdir($this->distRoot . '/libs/jquery', 0755, true);
        mkdir($this->distRoot . '/files/perm/themes/base/base', 0755, true);
        file_put_contents($this->distRoot . '/libs/jquery/jquery.min.js', 'window.jQuery=function(){};');
        file_put_contents(
            $this->distRoot . '/files/perm/themes/base/base/icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );
        file_put_contents(
            $this->distRoot . '/bundles/preview-fixed-resources.json',
            json_encode(['schemaVersion' => 1, 'buildVersion' => '4.0.0', 'resources' => [
                'libs/jquery/jquery.min.js' => ['path' => 'libs/jquery/jquery.min.js', 'size' => 26],
                'theme:base/icon.svg' => ['path' => 'files/perm/themes/base/base/icon.svg', 'size' => 70],
            ]])
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->base);
        $this->removeDir($this->distRoot);
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

    private function store(array $limits = []): PreviewSessionStore
    {
        $now = function (): int {
            return $this->time;
        };
        return new PreviewSessionStore(
            $this->base,
            new PreviewFixedResources($this->distRoot),
            $limits,
            $now
        );
    }

    /** @return array{key: string, size: int, bytes: string} */
    private function asset(string $key, string $bytes): array
    {
        return ['key' => $key, 'size' => strlen($bytes), 'bytes' => $bytes];
    }

    /**
     * A store whose allSessions() scans are counted, to assert the global-budget
     * check scans the session tree once per batch (not once per asset).
     *
     * @param array<string, int> $limits
     */
    private function countingStore(array $limits = []): PreviewSessionStore
    {
        $now = function (): int {
            return $this->time;
        };
        return new class ($this->base, new PreviewFixedResources($this->distRoot), $limits, $now) extends PreviewSessionStore {
            public int $scans = 0;

            protected function allSessions(): array
            {
                $this->scans++;
                return parent::allSessions();
            }
        };
    }

    /** A distinct, well-formed assetKey ({36-char id}@{hex hash}) per index. */
    private function uniqueKey(int $n): string
    {
        return sprintf('%08d-0000-4000-8000-%012d@%08x', $n, $n, $n);
    }

    // =========================================================================
    // createSession
    // =========================================================================

    public function testCreateSessionReturnsUuidAndLimits(): void
    {
        $store = $this->store();
        $result = $store->createSession(self::OWNER);

        $this->assertMatchesRegularExpression(PreviewSessionStore::PREVIEW_ID_RE, $result['previewId']);
        $this->assertSame(5000, $result['limits']['maxFilesPerSession']);
        $this->assertSame(209715200, $result['limits']['maxBytesPerSession']);
        $this->assertSame(134217728, $result['limits']['maxAssetBytes']);
        $this->assertSame(67108864, $result['limits']['recommendedBatchBytes']);
        $this->assertDirectoryExists($this->base . '/' . $result['previewId']);
    }

    public function testCreateSessionWritesWebDenyGuardIntoTheBaseDir(): void
    {
        // The store lives under the Omeka files/ tree, which Apache serves
        // directly. A deny guard must exist so a direct GET can never reach a
        // materialized document (which would serve author HTML same-origin
        // WITHOUT the sandbox CSP, a second un-sandboxed serving path).
        $store = $this->store();
        $store->createSession(self::OWNER);

        $htaccess = $this->base . '/.htaccess';
        $this->assertFileExists($htaccess);
        $content = file_get_contents($htaccess);
        $this->assertStringContainsString('Require all denied', $content); // Apache 2.4
        $this->assertStringContainsString('Deny from all', $content);       // Apache 2.2

        $index = $this->base . '/index.php';
        $this->assertFileExists($index);
        $this->assertStringContainsString('Silence is golden', file_get_contents($index));
    }

    public function testShippedNginxSamplesDenyDirectAccessToThePreviewStore(): void
    {
        // Apache reads the store's .htaccess deny guard; nginx does not. The
        // shipped nginx samples MUST therefore deny /files/exelearning-preview/
        // directly, or a raw GET would serve untrusted author HTML same-origin
        // without the sandbox CSP (the -preview/ prefix is NOT covered by the
        // pre-existing /files/exelearning/ rule).
        $repoRoot = dirname(__DIR__, 3);
        foreach (['/data/nginx-exelearning.conf', '/docker/nginx-exelearning.conf'] as $rel) {
            $this->assertFileExists($repoRoot . $rel);
            $conf = (string) file_get_contents($repoRoot . $rel);
            $this->assertStringContainsString(
                '/files/exelearning-preview/',
                $conf,
                $rel . ' must deny direct access to the preview session store'
            );
        }
    }

    public function testAccessGuardIsWrittenIdempotentlyAndNotClobbered(): void
    {
        $store = $this->store();
        $store->createSession(self::OWNER);
        // A guard already present (e.g. a stricter admin override) must survive a
        // later session creation.
        file_put_contents($this->base . '/.htaccess', "Require all denied\n# admin-override");
        $store->createSession(self::OWNER);

        $this->assertStringContainsString('# admin-override', file_get_contents($this->base . '/.htaccess'));
    }

    public function testCreateSessionEvictsOldestOwnedWhenAtPerUserCap(): void
    {
        $store = $this->store(['maxSessionsPerUser' => 2]);
        $first = $store->createSession(self::OWNER)['previewId'];
        $this->time += 10;
        $second = $store->createSession(self::OWNER)['previewId'];
        $this->time += 10;
        $third = $store->createSession(self::OWNER)['previewId'];

        // The oldest (first) is evicted; the two most recent survive.
        $this->assertDirectoryDoesNotExist($this->base . '/' . $first);
        $this->assertDirectoryExists($this->base . '/' . $second);
        $this->assertDirectoryExists($this->base . '/' . $third);
    }

    // =========================================================================
    // storeAssets
    // =========================================================================

    public function testStoreAssetsStoresValidEntries(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];

        $result = $store->storeAssets($id, self::OWNER, [
            $this->asset(self::PHOTO_KEY, 'PHOTO-BYTES-v1'),
            $this->asset(self::CLIP_KEY, '0123456789'),
        ]);

        $this->assertSame([self::PHOTO_KEY, self::CLIP_KEY], $result['stored']);
        $this->assertSame([], $result['alreadyStored']);
        $this->assertSame([], $result['rejected']);
    }

    public function testStoreAssetsIsImmutablePerKey(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $store->storeAssets($id, self::OWNER, [$this->asset(self::PHOTO_KEY, 'PHOTO-BYTES-v1')]);

        // Re-upload the same key with DIFFERENT bytes.
        $result = $store->storeAssets($id, self::OWNER, [
            $this->asset(self::PHOTO_KEY, 'PHOTO-BYTES-v2-DIFFERENT'),
        ]);

        $this->assertSame([], $result['stored']);
        $this->assertSame([self::PHOTO_KEY], $result['alreadyStored']);
        // Publish + serve to prove the ORIGINAL bytes are still served.
        $this->publishPhotoRevision($store, $id);
        $file = $store->resolveForServing($id, 'content/resources/photo.png');
        $this->assertSame('PHOTO-BYTES-v1', $file['bytes']);
    }

    public function testStoreAssetsRejectsInvalidKey(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];

        $result = $store->storeAssets($id, self::OWNER, [$this->asset('not-a-valid-key', 'x')]);

        $this->assertSame([], $result['stored']);
        $this->assertSame([['key' => 'not-a-valid-key', 'reason' => 'invalid-key']], $result['rejected']);
    }

    public function testStoreAssetsRejectsSizeMismatch(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];

        $result = $store->storeAssets($id, self::OWNER, [
            ['key' => self::PHOTO_KEY, 'size' => 999, 'bytes' => 'short'],
        ]);

        $this->assertSame([['key' => self::PHOTO_KEY, 'reason' => 'size-mismatch']], $result['rejected']);
    }

    public function testStoreAssetsRejectsAssetOverPerAssetCap(): void
    {
        $store = $this->store(['maxAssetBytes' => 4]);
        $id = $store->createSession(self::OWNER)['previewId'];

        $result = $store->storeAssets($id, self::OWNER, [$this->asset(self::PHOTO_KEY, 'toolong')]);

        $this->assertSame([['key' => self::PHOTO_KEY, 'reason' => 'asset-too-large']], $result['rejected']);
    }

    public function testStoreAssetsRejectsWhenSessionBudgetExceeded(): void
    {
        $store = $this->store(['maxBytesPerSession' => 5]);
        $id = $store->createSession(self::OWNER)['previewId'];

        $result = $store->storeAssets($id, self::OWNER, [$this->asset(self::PHOTO_KEY, 'sixbytes')]);

        $this->assertSame([['key' => self::PHOTO_KEY, 'reason' => 'session-budget-exceeded']], $result['rejected']);
    }

    public function testStoreAssetsRejectsWhenGlobalBudgetExceeded(): void
    {
        $store = $this->store(['globalMaxBytes' => 4]);
        $id = $store->createSession(self::OWNER)['previewId'];

        $result = $store->storeAssets($id, self::OWNER, [$this->asset(self::PHOTO_KEY, 'toolong')]);

        $this->assertSame([['key' => self::PHOTO_KEY, 'reason' => 'global-budget-exceeded']], $result['rejected']);
    }

    public function testStoreAssetsGlobalEvictionRemovesLruOtherSession(): void
    {
        $store = $this->store(['globalMaxBytes' => 30]);
        $a = $store->createSession(1)['previewId'];
        $store->storeAssets($a, 1, [$this->asset(self::PHOTO_KEY, str_repeat('a', 20))]);

        $this->time += 100;
        $b = $store->createSession(2)['previewId'];
        $result = $store->storeAssets($b, 2, [$this->asset(self::CLIP_KEY, str_repeat('b', 20))]);

        $this->assertSame([self::CLIP_KEY], $result['stored']);
        // The LRU other session (A) was evicted to make room.
        $this->assertDirectoryDoesNotExist($this->base . '/' . $a);
        $this->assertDirectoryExists($this->base . '/' . $b);
    }

    public function testStoreAssetsScansSessionTreeOncePerBatch(): void
    {
        // The global-budget check must amortize its session-tree scan across the
        // whole batch: ONE allSessions() pass per request, not one per asset
        // (the former O(M·N) behavior). A spy subclass counts the scans while a
        // tight global budget forces at least one eviction during the batch.
        $store = $this->countingStore(['globalMaxBytes' => 22]);

        // Seed several OTHER sessions on disk (the N in O(M·N)).
        for ($i = 1; $i <= 4; $i++) {
            $other = $store->createSession(100 + $i)['previewId'];
            $store->storeAssets($other, 100 + $i, [$this->asset($this->uniqueKey($i), 'xxxxx')]);
        }

        $target = $store->createSession(self::OWNER)['previewId'];
        $store->scans = 0; // count only the batch under test

        // A batch of M assets, each subject to the global-budget check.
        $entries = [];
        for ($i = 0; $i < 4; $i++) {
            $entries[] = $this->asset($this->uniqueKey(200 + $i), 'yyyyy');
        }
        $result = $store->storeAssets($target, self::OWNER, $entries);

        $this->assertSame(1, $store->scans, 'the session tree must be scanned once per batch, not per asset');
        // Semantics unchanged: every accepted entry is stored, none spuriously
        // rejected, and the tight budget evicted at least one other session.
        $this->assertCount(4, $result['stored']);
        $this->assertSame([], $result['rejected']);
        $survivors = array_filter(
            array_diff(scandir($this->base), ['.', '..', '.htaccess', 'index.php', '.last_sweep']),
            fn($e) => is_dir($this->base . '/' . $e)
        );
        // 4 seeded + 1 target = 5 created; at least one seeded session was evicted.
        $this->assertLessThan(5, count($survivors));
    }

    public function testStoreAssetsSkipsGlobalScanWhenNoEntryReachesIt(): void
    {
        // When every entry fails a cheap pre-check (here: invalid key), no entry
        // reaches the global-budget check, so the session tree is never scanned.
        $store = $this->countingStore();
        $id = $store->createSession(self::OWNER)['previewId'];
        $store->scans = 0;

        $result = $store->storeAssets($id, self::OWNER, [
            $this->asset('not-a-valid-key', 'x'),
            $this->asset('also-invalid', 'y'),
        ]);

        $this->assertSame(0, $store->scans, 'a batch with no admissible entry must not scan the session tree');
        $this->assertSame([], $result['stored']);
        $this->assertCount(2, $result['rejected']);
    }

    public function testStoreAssetsReportsWriteFailedAndDoesNotIndexIt(): void
    {
        // Contract §5: a failed write must be reported `write-failed` and NEVER
        // indexed. Force a durable write failure by replacing the assets/ dir
        // with a file, so atomicWrite cannot create the parent.
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $assets = $this->base . '/' . $id . '/assets';
        rmdir($assets);
        file_put_contents($assets, 'not-a-dir');

        $result = $store->storeAssets($id, self::OWNER, [$this->asset(self::PHOTO_KEY, 'PHOTO')]);

        $this->assertSame([], $result['stored']);
        $this->assertSame([['key' => self::PHOTO_KEY, 'reason' => 'write-failed']], $result['rejected']);

        // Proof it was not indexed: a revision referencing the key is a missing
        // asset (the failed write never entered the store).
        unlink($assets); // let the revision path create its own dirs
        $rev = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
            'deletes' => [], 'assetRefs' => ['content/x.png' => self::PHOTO_KEY], 'fixedRefs' => [],
        ], []);
        $this->assertSame(422, $rev['status']);
        $this->assertSame('missing-assets', $rev['reason']);
    }

    public function testPublishAbortsBeforePointerSwapWhenDocWriteFails(): void
    {
        // Contract §5 atomicity: a document-write failure inside the REAL
        // materializeRevision aborts the publish BEFORE the pointer swap — the
        // old revision stays active and the partial rev is discarded.
        $now = function (): int {
            return $this->time;
        };
        $store = new class ($this->base, new PreviewFixedResources($this->distRoot), [], $now) extends PreviewSessionStore {
            public bool $failDocWrites = false;

            protected function atomicWrite(string $path, string $bytes): bool
            {
                if ($this->failDocWrites && strpos($path, '/documents/') !== false) {
                    return false;
                }
                return parent::atomicWrite($path, $bytes);
            }
        };
        $id = $store->createSession(self::OWNER)['previewId'];
        $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['index.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['<p>rev1</p>']);

        $store->failDocWrites = true;
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 1, 'nextRevision' => 2, 'writes' => ['index.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['<p>rev2</p>']);

        $this->assertSame(500, $result['status']);
        // The pointer never moved: revision 1 is still served, partial rev/2 gone.
        $this->assertSame('<p>rev1</p>', $store->resolveForServing($id, 'index.html')['bytes']);
        $this->assertDirectoryDoesNotExist($this->base . '/' . $id . '/rev/2');
    }

    public function testAtomicWriteReportsSuccessAndRenameFailure(): void
    {
        $store = new class ($this->base, new PreviewFixedResources($this->distRoot)) extends PreviewSessionStore {
            public function exposeAtomicWrite(string $path, string $bytes): bool
            {
                return $this->atomicWrite($path, $bytes);
            }
        };

        // Success creates parents and writes the exact bytes.
        $ok = $this->base . '/aw/file.bin';
        $this->assertTrue($store->exposeAtomicWrite($ok, 'hello'));
        $this->assertSame('hello', file_get_contents($ok));

        // Target that is a directory → the rename can never succeed → false.
        $dirTarget = $this->base . '/aw-dir';
        mkdir($dirTarget, 0700, true);
        $this->assertFalse($store->exposeAtomicWrite($dirTarget, 'x'));
    }

    public function testStoreAssetsReturns404ForUnknownSession(): void
    {
        $store = $this->store();
        $result = $store->storeAssets('11111111-2222-4333-8444-555555555555', self::OWNER, []);
        $this->assertSame(['status' => 404], $result);
    }

    public function testStoreAssetsReturns403ForWrongOwner(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->storeAssets($id, 999, []);
        $this->assertSame(['status' => 403], $result);
    }

    public function testStoreAssetsReturns404ForNonUuid(): void
    {
        $store = $this->store();
        $result = $store->storeAssets('not-a-uuid', self::OWNER, []);
        $this->assertSame(['status' => 404], $result);
    }

    public function testRemainingSessionBudgetReflectsStoredAssets(): void
    {
        $store = $this->store(['maxBytesPerSession' => 1000]);
        $id = $store->createSession(self::OWNER)['previewId'];
        $this->assertSame(1000, $store->remainingSessionBudget($id, self::OWNER));

        $store->storeAssets($id, self::OWNER, [$this->asset(self::PHOTO_KEY, str_repeat('x', 40))]);
        $this->assertSame(960, $store->remainingSessionBudget($id, self::OWNER));

        // Unknown session or wrong owner -> null (the caller must not leak status).
        $this->assertNull($store->remainingSessionBudget($id, 999));
        $this->assertNull($store->remainingSessionBudget('11111111-2222-4333-8444-555555555555', self::OWNER));
    }

    // =========================================================================
    // applyRevision + resolveForServing (three-layer)
    // =========================================================================

    public function testApplyRevisionPublishesAndServesAllThreeLayers(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $store->storeAssets($id, self::OWNER, [$this->asset(self::PHOTO_KEY, 'PHOTO')]);

        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0,
            'nextRevision' => 1,
            'writes' => ['index.html'],
            'deletes' => [],
            'assetRefs' => ['content/resources/photo.png' => self::PHOTO_KEY],
            'fixedRefs' => ['libs/jquery/jquery.min.js' => 'libs/jquery/jquery.min.js'],
        ], ['<html>hi</html>']);

        $this->assertSame(['revision' => 1, 'active' => true], $result);

        $doc = $store->resolveForServing($id, 'index.html');
        $this->assertSame('document', $doc['kind']);
        $this->assertSame('<html>hi</html>', $doc['bytes']);

        $asset = $store->resolveForServing($id, 'content/resources/photo.png');
        $this->assertSame('asset', $asset['kind']);
        $this->assertSame('PHOTO', $asset['bytes']);
        $this->assertSame(self::PHOTO_KEY, $asset['etag']);

        $fixed = $store->resolveForServing($id, 'libs/jquery/jquery.min.js');
        $this->assertSame('fixed', $fixed['kind']);
        $this->assertSame('window.jQuery=function(){};', $fixed['bytes']);

        $this->assertNull($store->resolveForServing($id, 'missing.css'));
    }

    public function testResolveForServingReturnsNullBeforeFirstRevision(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $this->assertNull($store->resolveForServing($id, 'index.html'));
    }

    public function testResolveForServingReturnsNullForNonUuid(): void
    {
        $store = $this->store();
        $this->assertNull($store->resolveForServing('not-a-uuid', 'index.html'));
    }

    public function testResolveForServingReturnsNullForUnknownSession(): void
    {
        $store = $this->store();
        $this->assertNull($store->resolveForServing('11111111-2222-4333-8444-555555555555', 'index.html'));
    }

    public function testIncrementalRevisionAppliesDocumentDeltas(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1,
            'writes' => ['index.html', 'a.html'], 'deletes' => [],
            'assetRefs' => [], 'fixedRefs' => [],
        ], ['<p>index</p>', '<p>a</p>']);

        $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 1, 'nextRevision' => 2,
            'writes' => ['b.html'], 'deletes' => ['a.html'],
            'assetRefs' => [], 'fixedRefs' => [],
        ], ['<p>b</p>']);

        $this->assertSame('<p>index</p>', $store->resolveForServing($id, 'index.html')['bytes']);
        $this->assertSame('<p>b</p>', $store->resolveForServing($id, 'b.html')['bytes']);
        $this->assertNull($store->resolveForServing($id, 'a.html'));
    }

    public function testApplyRevisionRejectsStaleBaseWith409(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['index.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['<p>v1</p>']);

        // Client still believes the active revision is 0.
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['index.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['stale']);

        $this->assertSame(409, $result['status']);
        $this->assertSame('revision-conflict', $result['reason']);
        $this->assertSame(1, $result['currentRevision']);
    }

    public function testApplyRevisionRejectsUnsafeWritePathWith400(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['../escape.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['x']);

        $this->assertSame(400, $result['status']);
    }

    public function testApplyRevisionRejectsUnsafeDeletePathWith400(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
            'deletes' => ['../escape.html'], 'assetRefs' => [], 'fixedRefs' => [],
        ], []);

        $this->assertSame(400, $result['status']);
    }

    public function testApplyRevisionRejectsUnsafeAssetRefPathWith400(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
            'deletes' => [], 'assetRefs' => ['../x' => self::PHOTO_KEY], 'fixedRefs' => [],
        ], []);

        $this->assertSame(400, $result['status']);
    }

    public function testApplyRevisionRejectsUnsafeFixedRefPathWith400(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => ['../x' => 'libs/jquery/jquery.min.js'],
        ], []);

        $this->assertSame(400, $result['status']);
    }

    public function testApplyRevisionRejectsOverGlobalBudgetWith413(): void
    {
        // Session budget is generous but the global cap is tiny and there is no
        // other session to evict, so the document delta cannot fit.
        $store = $this->store(['maxBytesPerSession' => 1000000, 'globalMaxBytes' => 4]);
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['way-too-many-bytes']);

        $this->assertSame(413, $result['status']);
    }

    public function testApplyRevisionRejectsMisalignedWritesWith400(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html', 'b.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['only-one']);

        $this->assertSame(400, $result['status']);
    }

    public function testApplyRevisionRejectsMissingAssetWith422(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $ghost = '99999999-9999-4999-8999-999999999999@deadbeef';
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
            'deletes' => [], 'assetRefs' => ['content/resources/ghost.png' => $ghost], 'fixedRefs' => [],
        ], []);

        $this->assertSame(422, $result['status']);
        $this->assertSame('missing-assets', $result['reason']);
        $this->assertSame([$ghost], $result['missing']);
    }

    public function testApplyRevisionRejectsUnknownFixedResourceWith422(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => ['x.js' => 'libs/not-in-manifest.js'],
        ], []);

        $this->assertSame(422, $result['status']);
        $this->assertSame('unknown-fixed-resources', $result['reason']);
        $this->assertSame(['libs/not-in-manifest.js'], $result['resources']);
    }

    public function testApplyRevisionRejectsTooManyFilesWith413(): void
    {
        $store = $this->store(['maxFilesPerSession' => 1]);
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html', 'b.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['a', 'b']);

        $this->assertSame(413, $result['status']);
    }

    public function testApplyRevisionRejectsOverByteBudgetWith413(): void
    {
        $store = $this->store(['maxBytesPerSession' => 4]);
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['way-too-many-bytes']);

        $this->assertSame(413, $result['status']);
    }

    public function testApplyRevisionReturns403ForWrongOwner(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $result = $store->applyRevision($id, 999, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], []);
        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // deleteSession
    // =========================================================================

    public function testDeleteSessionRemovesTheCapabilityUrl(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['index.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['<p>x</p>']);

        $this->assertSame(['success' => true], $store->deleteSession($id, self::OWNER));
        $this->assertDirectoryDoesNotExist($this->base . '/' . $id);
        $this->assertNull($store->resolveForServing($id, 'index.html'));
    }

    public function testApplyRevisionReturns404ForCorruptSessionMetadata(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        // Corrupt the session by removing its metadata file.
        unlink($this->base . '/' . $id . '/session.json');

        $result = $store->storeAssets($id, self::OWNER, []);
        $this->assertSame(['status' => 404], $result);
    }

    public function testOldRevisionsArePrunedAfterPublishing(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        foreach ([[0, 1], [1, 2], [2, 3]] as [$base, $next]) {
            $store->applyRevision($id, self::OWNER, [
                'baseRevision' => $base, 'nextRevision' => $next, 'writes' => ['index.html'],
                'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
            ], ['<p>rev' . $next . '</p>']);
        }

        // The store keeps the active revision and the immediately previous one.
        $this->assertDirectoryDoesNotExist($this->base . '/' . $id . '/rev/1');
        $this->assertDirectoryExists($this->base . '/' . $id . '/rev/2');
        $this->assertDirectoryExists($this->base . '/' . $id . '/rev/3');
        $this->assertSame('<p>rev3</p>', $store->resolveForServing($id, 'index.html')['bytes']);
    }

    public function testDeleteSessionReturns404ForUnknown(): void
    {
        $store = $this->store();
        $result = $store->deleteSession('11111111-2222-4333-8444-555555555555', self::OWNER);
        $this->assertSame(['status' => 404], $result);
    }

    public function testDeleteSessionReturns403ForWrongOwner(): void
    {
        $store = $this->store();
        $id = $store->createSession(self::OWNER)['previewId'];
        $this->assertSame(['status' => 403], $store->deleteSession($id, 999));
    }

    // =========================================================================
    // Idle TTL
    // =========================================================================

    public function testExpiredSessionIsUnreachableAndSwept(): void
    {
        $store = $this->store(['ttlSeconds' => 100, 'sweepIntervalSeconds' => 0]);
        $id = $store->createSession(self::OWNER)['previewId'];
        $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['index.html'],
            'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
        ], ['<p>x</p>']);

        // Advance past the idle TTL.
        $this->time += 500;

        $this->assertNull($store->resolveForServing($id, 'index.html'));
        $this->assertDirectoryDoesNotExist($this->base . '/' . $id);
    }

    public function testExpiredSessionIsNotFoundByManagementApi(): void
    {
        $store = $this->store(['ttlSeconds' => 100]);
        $id = $store->createSession(self::OWNER)['previewId'];
        $this->time += 500;
        $this->assertSame(['status' => 404], $store->storeAssets($id, self::OWNER, []));
    }

    public function testSweepReclaimsAnUntouchedExpiredSession(): void
    {
        $store = $this->store(['ttlSeconds' => 100, 'sweepIntervalSeconds' => 0]);
        $stale = $store->createSession(self::OWNER)['previewId'];
        $this->time += 500;
        // A fresh action on ANOTHER session triggers the opportunistic sweep.
        $store->createSession(self::OWNER);

        $this->assertDirectoryDoesNotExist($this->base . '/' . $stale);
    }

    // =========================================================================
    // normalizePath (static, shared with PreviewController)
    // =========================================================================

    public function testNormalizePathDefaultsToIndexHtml(): void
    {
        $this->assertSame('index.html', PreviewSessionStore::normalizePath(''));
    }

    public function testNormalizePathRejectsTraversal(): void
    {
        $this->assertNull(PreviewSessionStore::normalizePath('../../etc/passwd'));
        $this->assertNull(PreviewSessionStore::normalizePath('css/../../secret'));
    }

    public function testNormalizePathRejectsSingleEncodedTraversal(): void
    {
        $this->assertNull(PreviewSessionStore::normalizePath('%2e%2e%2fsecret'));
    }

    public function testNormalizePathTreatsDoubleEncodedTraversalAsLiteralSegment(): void
    {
        // A double-encoded ../ survives ONE decode as a literal %2e%2e segment —
        // never a real traversal — so it is a safe (non-existent) exact key.
        $this->assertSame('%2e%2e%2fsecret', PreviewSessionStore::normalizePath('%252e%252e%252fsecret'));
    }

    public function testNormalizePathNormalizesNestedAndBackslashes(): void
    {
        $this->assertSame('css/styles.css', PreviewSessionStore::normalizePath('./css//styles.css'));
        $this->assertSame('a/b/c.js', PreviewSessionStore::normalizePath('a\\b\\c.js'));
    }

    // =========================================================================
    // helpers
    // =========================================================================

    private function publishPhotoRevision(PreviewSessionStore $store, string $id): void
    {
        $store->applyRevision($id, self::OWNER, [
            'baseRevision' => 0,
            'nextRevision' => 1,
            'writes' => ['index.html'],
            'deletes' => [],
            'assetRefs' => ['content/resources/photo.png' => self::PHOTO_KEY],
            'fixedRefs' => [],
        ], ['<html></html>']);
    }
}
