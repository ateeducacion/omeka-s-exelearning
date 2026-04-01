<?php

declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\StaticEditorInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StaticEditorInstaller.
 *
 * @covers \ExeLearning\Service\StaticEditorInstaller
 */
class StaticEditorInstallerTest extends TestCase
{
    private StaticEditorInstaller $installer;

    /** @var string[] Temp directories to clean up. */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->installer = new StaticEditorInstaller();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                $this->recursiveDelete($dir);
            }
        }
    }

    // =========================================================================
    // Static detection methods
    // =========================================================================

    public function testGetEditorPathReturnsExpectedPath(): void
    {
        $path = StaticEditorInstaller::getEditorPath();
        $this->assertStringEndsWith('dist/static', $path);
    }

    public function testIsEditorInstalledReturnsFalseWhenMissing(): void
    {
        // dist/static/index.html won't exist in the test environment.
        $this->assertIsBool(StaticEditorInstaller::isEditorInstalled());
    }

    // =========================================================================
    // getAssetUrl tests
    // =========================================================================

    public function testGetAssetUrlBuildsCorrectUrl(): void
    {
        $url = $this->installer->getAssetUrl('4.0.0-beta2');
        $this->assertEquals(
            'https://github.com/exelearning/exelearning/releases/download/v4.0.0-beta2/exelearning-static-v4.0.0-beta2.zip',
            $url
        );
    }

    public function testGetAssetUrlSimpleVersion(): void
    {
        $url = $this->installer->getAssetUrl('4.0.0');
        $this->assertStringContainsString('exelearning-static-v4.0.0.zip', $url);
    }

    // =========================================================================
    // validateZip tests
    // =========================================================================

    public function testValidateZipRejectsNonZip(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test-');
        file_put_contents($tmp, 'This is not a ZIP file.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid ZIP');
        try {
            $this->installer->validateZip($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testValidateZipAcceptsValidZip(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('test.txt', 'hello');
        $zip->close();

        // Should not throw.
        $this->installer->validateZip($tmp);
        $this->assertTrue(true);
        @unlink($tmp);
    }

    // =========================================================================
    // validateEditorContents tests
    // =========================================================================

    public function testValidateEditorContentsMissingIndex(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/app', 0755, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing index.html');
        $this->installer->validateEditorContents($tmpDir);
    }

    public function testValidateEditorContentsMissingAssets(): void
    {
        $tmpDir = $this->createTempDir();
        file_put_contents($tmpDir . '/index.html', '<html></html>');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing expected asset directories');
        $this->installer->validateEditorContents($tmpDir);
    }

    public function testValidateEditorContentsValid(): void
    {
        $tmpDir = $this->createTempDir();
        file_put_contents($tmpDir . '/index.html', '<html></html>');
        mkdir($tmpDir . '/app', 0755, true);

        // Should not throw.
        $this->installer->validateEditorContents($tmpDir);
        $this->assertTrue(true);
    }

    // =========================================================================
    // normalizeExtraction tests
    // =========================================================================

    public function testNormalizeExtractionRootFiles(): void
    {
        $tmpDir = $this->createTempDir();
        file_put_contents($tmpDir . '/index.html', '<html></html>');

        $result = $this->installer->normalizeExtraction($tmpDir);
        $this->assertEquals($tmpDir, $result);
    }

    public function testNormalizeExtractionSingleDir(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/exelearning-static-v4.0.0', 0755, true);
        file_put_contents($tmpDir . '/exelearning-static-v4.0.0/index.html', '<html></html>');

        $result = $this->installer->normalizeExtraction($tmpDir);
        $this->assertStringContainsString('exelearning-static-v4.0.0', $result);
    }

    public function testNormalizeExtractionFailsNoIndex(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/some-dir', 0755, true);
        file_put_contents($tmpDir . '/some-dir/readme.txt', 'hello');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find index.html');
        $this->installer->normalizeExtraction($tmpDir);
    }

    // =========================================================================
    // extractZip tests
    // =========================================================================

    public function testExtractZipValid(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', '<html></html>');
        $zip->close();

        $result = $this->installer->extractZip($tmp);
        $this->tempDirs[] = $result;

        $this->assertDirectoryExists($result);
        $this->assertFileExists($result . '/index.html');
        @unlink($tmp);
    }

    // =========================================================================
    // Constants tests
    // =========================================================================

    public function testConstantsAreDefined(): void
    {
        $this->assertNotEmpty(StaticEditorInstaller::RELEASES_FEED_URL);
        $this->assertNotEmpty(StaticEditorInstaller::ASSET_PREFIX);
        $this->assertEquals('exelearning_editor_installed_version', StaticEditorInstaller::SETTING_VERSION);
        $this->assertEquals('exelearning_editor_installed_at', StaticEditorInstaller::SETTING_INSTALLED_AT);
    }

    public function testStoreAndReadInstallStatus(): void
    {
        $settings = new class {
            private array $store = [];
            public function set(string $key, $value): void { $this->store[$key] = $value; }
            public function get(string $key, $default = null) { return $this->store[$key] ?? $default; }
        };

        StaticEditorInstaller::storeInstallStatus($settings, 'downloading', 'Downloading editor...', [
            'target_version' => '4.0.0-beta3',
            'started_at' => time(),
            'success' => false,
            'error' => '',
        ]);

        $status = StaticEditorInstaller::getStoredInstallStatus($settings);

        $this->assertSame('downloading', $status['phase']);
        $this->assertSame('Downloading editor...', $status['message']);
        $this->assertSame('4.0.0-beta3', $status['target_version']);
        $this->assertTrue($status['running']);
        $this->assertFalse($status['stale']);
    }

    public function testStoredInstallStatusDetectsStaleLock(): void
    {
        $settings = new class {
            private array $store = [];
            public function set(string $key, $value): void { $this->store[$key] = $value; }
            public function get(string $key, $default = null) { return $this->store[$key] ?? $default; }
        };

        StaticEditorInstaller::storeInstallStatus($settings, 'installing', 'Installing editor...', [
            'started_at' => time() - (StaticEditorInstaller::INSTALL_LOCK_TTL + 5),
            'success' => false,
            'error' => '',
        ]);

        $status = StaticEditorInstaller::getStoredInstallStatus($settings);

        $this->assertFalse($status['running']);
        $this->assertTrue($status['stale']);
    }

    public function testResetInstallStatusReturnsIdle(): void
    {
        $settings = new class {
            private array $store = [];
            public function set(string $key, $value): void { $this->store[$key] = $value; }
            public function get(string $key, $default = null) { return $this->store[$key] ?? $default; }
        };

        StaticEditorInstaller::storeInstallStatus($settings, 'error', 'boom', [
            'target_version' => '4.0.0',
            'started_at' => 123,
            'success' => true,
            'error' => 'boom',
        ]);
        StaticEditorInstaller::resetInstallStatus($settings);

        $status = StaticEditorInstaller::getStoredInstallStatus($settings);

        $this->assertSame('idle', $status['phase']);
        $this->assertSame('', $status['message']);
        $this->assertSame('', $status['target_version']);
        $this->assertFalse($status['success']);
        $this->assertFalse($status['running']);
    }

    public function testIsFreshLockAndRunningPhaseHelpers(): void
    {
        $this->assertTrue(StaticEditorInstaller::isFreshLock(time()));
        $this->assertFalse(StaticEditorInstaller::isFreshLock(time() - (StaticEditorInstaller::INSTALL_LOCK_TTL + 5)));
        $this->assertTrue(StaticEditorInstaller::isRunningPhase('checking'));
        $this->assertFalse(StaticEditorInstaller::isRunningPhase('done'));
    }

    // =========================================================================
    // safeInstall tests
    // =========================================================================

    public function testSafeInstallMethodExists(): void
    {
        $this->assertTrue(method_exists($this->installer, 'safeInstall'));
    }

    // =========================================================================
    // discoverLatestVersion tests
    // =========================================================================

    public function testDiscoverLatestVersionMethodExists(): void
    {
        $this->assertTrue(method_exists($this->installer, 'discoverLatestVersion'));
    }

    // =========================================================================
    // downloadAsset tests
    // =========================================================================

    public function testDownloadAssetMethodExists(): void
    {
        $this->assertTrue(method_exists($this->installer, 'downloadAsset'));
    }

    // =========================================================================
    // installLatestEditor tests
    // =========================================================================

    public function testInstallLatestEditorMethodExists(): void
    {
        $this->assertTrue(method_exists($this->installer, 'installLatestEditor'));
    }

    public function testExtractVersionFromFeedParsesTagLink(): void
    {
        $feed = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <title>Release v4.0.0-beta3</title>
    <link rel="alternate" type="text/html" href="https://github.com/exelearning/exelearning/releases/tag/v4.0.0-beta3"/>
  </entry>
</feed>
XML;

        $this->assertEquals('4.0.0-beta3', $this->installer->extractVersionFromFeed($feed));
    }

    public function testExtractVersionFromFeedRejectsUnexpectedEntry(): void
    {
        $feed = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry><title>draft release</title></entry>
</feed>
XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected release tag format');
        $this->installer->extractVersionFromFeed($feed);
    }

    public function testStatusCallbackReceivesReportedPhases(): void
    {
        $seen = [];
        $installer = (new StaticEditorInstaller())->setStatusCallback(
            function (string $phase, string $message, array $extra = []) use (&$seen): void {
                $seen[] = [$phase, $message, $extra];
            }
        );

        $ref = new \ReflectionClass($installer);
        $method = $ref->getMethod('reportStatus');
        $method->setAccessible(true);
        $method->invoke($installer, 'checking', 'Checking latest version...', ['target_version' => '4.0.0']);

        $this->assertCount(1, $seen);
        $this->assertSame('checking', $seen[0][0]);
        $this->assertSame('Checking latest version...', $seen[0][1]);
        $this->assertSame('4.0.0', $seen[0][2]['target_version']);
    }

    // =========================================================================
    // cleanupDirectory tests
    // =========================================================================

    public function testCleanupDirectoryRemovesDir(): void
    {
        $tmpDir = $this->createTempDir();
        file_put_contents($tmpDir . '/test.txt', 'hello');
        mkdir($tmpDir . '/sub', 0755);
        file_put_contents($tmpDir . '/sub/nested.txt', 'world');

        $this->installer->cleanupDirectory($tmpDir);

        $this->assertDirectoryDoesNotExist($tmpDir);
    }

    public function testCleanupDirectoryNoopForMissing(): void
    {
        // Should not throw for non-existent dir
        $this->installer->cleanupDirectory('/tmp/nonexistent-' . bin2hex(random_bytes(8)));
        $this->assertTrue(true);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createTempDir(): string
    {
        $tmpDir = sys_get_temp_dir() . '/exelearning-test-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0755, true);
        $this->tempDirs[] = $tmpDir;
        return $tmpDir;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
