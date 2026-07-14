<?php
declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\PreviewSnapshotStore;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** @covers \ExeLearning\Service\PreviewSnapshotStore */
class PreviewSnapshotStoreTest extends TestCase
{
    /** @var string */
    private $root;
    /** @var int */
    private $now;
    /** @var PreviewSnapshotStore */
    private $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/omeka-preview-test-' . bin2hex(random_bytes(6));
        $this->now = 1000;
        $this->store = new PreviewSnapshotStore($this->root, 30, function (): int {
            return $this->now;
        });
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCreatesServesAndAtomicallyReplacesCompleteSnapshot(): void
    {
        $first = $this->zip([
            'index.html' => '<script>window.previewRan=true</script>',
            'assets/app.js' => 'window.officialRuntimeRan=true',
        ], ['assets/']);
        $id = $this->store->replace(7, 42, $first);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
        $html = $this->store->get($id, 'index.html');
        $this->assertSame('<script>window.previewRan=true</script>', $html['bytes']);
        $this->assertSame('text/html; charset=utf-8', $html['mime']);

        $second = $this->zip(['index.html' => '<h1>Replacement</h1>']);
        $this->assertSame($id, $this->store->replace(7, 42, $second, $id));
        $this->assertSame('<h1>Replacement</h1>', $this->store->get($id, 'index.html')['bytes']);
        $this->assertNull($this->store->get($id, 'assets/app.js'));

        unlink($first);
        unlink($second);
    }

    public function testRejectsCrossOwnerReplacementAndDelete(): void
    {
        $zip = $this->zip(['index.html' => 'safe']);
        $id = $this->store->replace(7, 42, $zip);

        try {
            $this->store->replace(8, 42, $zip, $id);
            $this->fail('Cross-owner replacement must fail.');
        } catch (\UnexpectedValueException $error) {
            $this->assertStringContainsString('another media item', $error->getMessage());
        }

        $this->expectException(\UnexpectedValueException::class);
        try {
            $this->store->delete(7, 43, $id);
        } finally {
            unlink($zip);
        }
    }

    public function testRejectsUnsafeArchivesAndServingPaths(): void
    {
        $unsafe = $this->zip([
            'index.html' => 'safe',
            '../outside.html' => 'unsafe',
        ]);
        try {
            $this->store->replace(7, 42, $unsafe);
            $this->fail('Traversal archive must fail.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('Unsafe', $error->getMessage());
        }
        unlink($unsafe);

        $zip = $this->zip(['index.html' => 'safe', 'image.svg' => '<svg/>']);
        $id = $this->store->replace(7, 42, $zip);
        $this->assertNull($this->store->get($id, '../.metadata.json'));
        $this->assertNull($this->store->get($id, '.metadata.json'));
        $this->assertSame('image/svg+xml', $this->store->get($id, 'image.svg')['mime']);
        unlink($zip);
    }

    public function testRequiresRootIndexAndAnExistingCapabilityForReplacement(): void
    {
        $missingIndex = $this->zip(['page.html' => 'no root index']);
        try {
            $this->store->replace(7, 42, $missingIndex);
            $this->fail('A root index is required.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('index.html', $error->getMessage());
        }
        unlink($missingIndex);

        $zip = $this->zip(['index.html' => 'safe']);
        $this->expectException(\RuntimeException::class);
        try {
            $this->store->replace(7, 42, $zip, '018f47e2-65b2-4b4a-8f7a-934b42e10f99');
        } finally {
            unlink($zip);
        }
    }

    public function testExpiresIdleCapabilitiesAndDeletesOwnedCapabilities(): void
    {
        $zip = $this->zip(['index.html' => 'safe']);
        $expired = $this->store->replace(7, 42, $zip);
        $this->now = 1031;
        $this->assertNull($this->store->get($expired, 'index.html'));

        $current = $this->store->replace(7, 42, $zip);
        $this->assertTrue($this->store->delete(7, 42, $current));
        $this->assertFalse($this->store->delete(7, 42, $current));
        unlink($zip);
    }

    /**
     * @param array<string,string> $files
     * @param string[] $directories
     */
    private function zip(array $files, array $directories = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'omeka-preview-zip-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($directories as $directory) {
            $zip->addEmptyDir($directory);
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeTree($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
