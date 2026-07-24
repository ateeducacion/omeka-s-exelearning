<?php
declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\EditorBundle;
use PHPUnit\Framework\TestCase;

/**
 * Test double pointing the bundle helper at a fixture directory.
 */
final class EditorBundleFixture extends EditorBundle
{
    public static string $moduleDir = '';

    protected static function getModuleDir(): string
    {
        return self::$moduleDir;
    }
}

/**
 * @covers \ExeLearning\Service\EditorBundle
 */
final class EditorBundleTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir() . '/exelearning-bundle-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureDir, 0755, true);
        EditorBundleFixture::$moduleDir = $this->fixtureDir;
    }

    protected function tearDown(): void
    {
        EditorBundleFixture::$moduleDir = '';
        $this->recursiveDelete($this->fixtureDir);
        parent::tearDown();
    }

    public function testValidBundleIsAvailable(): void
    {
        $this->makeBundle(['app']);

        $this->assertTrue(EditorBundleFixture::isAvailable());
    }

    public function testEachAssetDirectorySatisfiesValidation(): void
    {
        foreach (EditorBundle::ASSET_DIRS as $dir) {
            $this->recursiveDelete($this->fixtureDir . '/dist');
            $this->makeBundle([$dir]);
            $this->assertTrue(EditorBundleFixture::isAvailable(), "Bundle with only {$dir}/ should be valid.");
        }
    }

    public function testAbsentBundleIsUnavailable(): void
    {
        $this->assertFalse(EditorBundleFixture::isAvailable());
    }

    public function testInvalidBundleWithoutAssetDirsIsRejected(): void
    {
        $this->makeBundle([]);

        $this->assertFalse(EditorBundleFixture::isAvailable());
    }

    public function testBundleWithoutIndexIsRejected(): void
    {
        mkdir($this->fixtureDir . '/dist/static/app', 0755, true);

        $this->assertFalse(EditorBundleFixture::isAvailable());
    }

    public function testGetPathPointsAtDistStatic(): void
    {
        $this->assertSame($this->fixtureDir . '/dist/static', EditorBundleFixture::getPath());
        $this->assertStringEndsWith('/dist/static', EditorBundle::getPath());
    }

    private function makeBundle(array $assetDirs): void
    {
        $static = $this->fixtureDir . '/dist/static';
        mkdir($static, 0755, true);
        file_put_contents($static . '/index.html', '<html></html>');
        foreach ($assetDirs as $dir) {
            mkdir($static . '/' . $dir, 0755, true);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
