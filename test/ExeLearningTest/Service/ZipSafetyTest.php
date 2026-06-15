<?php

declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\ZipSafety;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Unit tests for ZipSafety.
 *
 * @covers \ExeLearning\Service\ZipSafety
 */
class ZipSafetyTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/exelearning-zipsafety-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpRoot);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->removeDirectory($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function makeZip(array $entries): string
    {
        $path = $this->tmpRoot . '/in-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    // ------------------------------------------------------------------
    // isUnsafeEntry()
    // ------------------------------------------------------------------

    /**
     * @dataProvider unsafeEntryProvider
     */
    public function testIsUnsafeEntryRejects(string $name): void
    {
        $this->assertTrue(ZipSafety::isUnsafeEntry($name));
    }

    public function unsafeEntryProvider(): array
    {
        return [
            'empty' => [''],
            'leading slash' => ['/etc/passwd'],
            'backslash' => ['a\\b.txt'],
            'parent traversal' => ['../evil.css'],
            'parent traversal txt' => ['../evil.txt'],
            'absolute path txt' => ['/absolute/path.txt'],
            'mid traversal' => ['a/../../evil'],
            'trailing dotdot' => ['a/..'],
            'stream wrapper' => ['php://filter/x'],
            'phar wrapper' => ['phar://evil'],
            'http wrapper' => ['http://evil/x'],
            'backslash folder' => ['folder\\evil.txt'],
        ];
    }

    /**
     * @dataProvider safeEntryProvider
     */
    public function testIsUnsafeEntryAllows(string $name): void
    {
        $this->assertFalse(ZipSafety::isUnsafeEntry($name));
    }

    public function safeEntryProvider(): array
    {
        return [
            'root file' => ['index.html'],
            'nested' => ['app/js/main.js'],
            'dir entry' => ['libs/'],
            'dotfile' => ['.htaccess'],
            'name with dots' => ['a.b.c.css'],
            'dotdot in filename' => ['my..file.txt'],
        ];
    }

    // ------------------------------------------------------------------
    // isForbiddenEntry()
    // ------------------------------------------------------------------

    /**
     * @dataProvider forbiddenEntryProvider
     */
    public function testIsForbiddenEntryRejects(string $name): void
    {
        $this->assertTrue(ZipSafety::isForbiddenEntry($name));
    }

    public function forbiddenEntryProvider(): array
    {
        return [
            // Server-configuration files.
            'htaccess' => ['.htaccess'],
            'nested htaccess' => ['folder/.htaccess'],
            'user ini' => ['.user.ini'],
            'php ini' => ['php.ini'],
            'web config' => ['web.config'],
            // Server-executable extensions (case-insensitive).
            'php script' => ['shell.php'],
            'phtml uppercase' => ['assets/shell.PHTML'],
            'phar payload' => ['assets/payload.phar'],
            'cgi script' => ['assets/run.cgi'],
        ];
    }

    /**
     * @dataProvider forbiddenSafeEntryProvider
     */
    public function testIsForbiddenEntryAllows(string $name): void
    {
        $this->assertFalse(ZipSafety::isForbiddenEntry($name));
    }

    public function forbiddenSafeEntryProvider(): array
    {
        return [
            'content xml' => ['content.xml'],
            'index html' => ['index.html'],
            'app js' => ['assets/app.js'],
            'style css' => ['assets/style.css'],
            'image svg' => ['assets/image.svg'],
            'readme txt' => ['assets/readme.txt'],
        ];
    }

    // ------------------------------------------------------------------
    // extractFile() / extract()
    // ------------------------------------------------------------------

    public function testExtractFileWritesAllEntries(): void
    {
        $zip = $this->makeZip([
            'index.html' => '<html></html>',
            'app/main.js' => 'console.log(1)',
            'libs/dir/' => '',
        ]);
        $dest = $this->tmpRoot . '/out';

        ZipSafety::extractFile($zip, $dest);

        $this->assertFileExists($dest . '/index.html');
        $this->assertFileExists($dest . '/app/main.js');
        $this->assertSame('<html></html>', file_get_contents($dest . '/index.html'));
        $this->assertSame('console.log(1)', file_get_contents($dest . '/app/main.js'));
    }

    public function testExtractFileThrowsOnMissingArchive(): void
    {
        $this->expectException(\RuntimeException::class);
        ZipSafety::extractFile($this->tmpRoot . '/nope.zip', $this->tmpRoot . '/out');
    }

    public function testExtractFileThrowsOnNonZip(): void
    {
        $bogus = $this->tmpRoot . '/bogus.zip';
        file_put_contents($bogus, 'not a zip');
        $this->expectException(\RuntimeException::class);
        ZipSafety::extractFile($bogus, $this->tmpRoot . '/out');
    }

    public function testExtractRejectsTraversalEntry(): void
    {
        // addFromString preserves the literal name; extract() must refuse it.
        $zip = $this->makeZip(['../escape.txt' => 'pwn']);
        $dest = $this->tmpRoot . '/out';

        $this->expectException(\RuntimeException::class);
        try {
            ZipSafety::extractFile($zip, $dest);
        } finally {
            // The escaped file must never have been written outside dest.
            $this->assertFileDoesNotExist($this->tmpRoot . '/escape.txt');
        }
    }

    public function testExtractRejectsForbiddenEntry(): void
    {
        // Inert text contents only; no executable code is included.
        $zip = $this->makeZip([
            'content.xml' => '<package></package>',
            'index.html' => '<html><body>ok</body></html>',
            '.htaccess' => "AddType application/x-httpd-php .txt\n",
            'payload.txt' => 'inert text payload',
        ]);
        $dest = $this->tmpRoot . '/out';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/forbidden archive entry/');
        try {
            ZipSafety::extractFile($zip, $dest);
        } finally {
            // The forbidden entry and its companion payload must not be left behind.
            $this->assertFileDoesNotExist($dest . '/.htaccess');
            $this->assertFileDoesNotExist($dest . '/payload.txt');
        }
    }

    public function testExtractRejectsTooManyEntries(): void
    {
        $zip = $this->makeZip([
            'a.txt' => '1',
            'b.txt' => '2',
            'c.txt' => '3',
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/too many entries/');
        ZipSafety::extractFile($zip, $this->tmpRoot . '/out', 2);
    }

    public function testExtractEnforcesUncompressedSizeCap(): void
    {
        // A single entry larger than the cap (zip-bomb proxy). The cap is on
        // bytes actually written, so a highly-compressible payload still trips.
        $zip = $this->makeZip(['big.bin' => str_repeat('A', 5000)]);
        $dest = $this->tmpRoot . '/out';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/uncompressed size/');
        try {
            ZipSafety::extractFile($zip, $dest, ZipSafety::DEFAULT_MAX_FILES, 1000);
        } finally {
            // Partial output of the oversized entry must be cleaned up.
            $this->assertFileDoesNotExist($dest . '/big.bin');
        }
    }

    public function testExtractAllowsArchiveWithinCaps(): void
    {
        $zip = $this->makeZip(['small.txt' => str_repeat('x', 100)]);
        $dest = $this->tmpRoot . '/out';

        ZipSafety::extractFile($zip, $dest, 10, 1000);

        $this->assertFileExists($dest . '/small.txt');
        $this->assertSame(100, strlen((string) file_get_contents($dest . '/small.txt')));
    }

    public function testExtractCreatesNestedDirectories(): void
    {
        $zip = $this->makeZip(['a/b/c/deep.txt' => 'deep']);
        $dest = $this->tmpRoot . '/out';

        ZipSafety::extractFile($zip, $dest);

        $this->assertDirectoryExists($dest . '/a/b/c');
        $this->assertSame('deep', file_get_contents($dest . '/a/b/c/deep.txt'));
    }

    public function testExtractThrowsWhenDirectoryEntryCollidesWithExistingFile(): void
    {
        $dest = $this->tmpRoot . '/out';
        mkdir($dest, 0755, true);
        // 'a' already exists as a regular file, so creating dir 'a' must fail.
        file_put_contents($dest . '/a', 'i-am-a-file');
        $zip = $this->makeZip(['a/b.txt' => 'x']);

        $this->expectException(\RuntimeException::class);
        ZipSafety::extractFile($zip, $dest);
    }

    public function testExtractThrowsWhenTargetPathIsExistingDirectory(): void
    {
        $dest = $this->tmpRoot . '/out';
        // 'x' already exists as a directory, so opening it as a file must fail.
        mkdir($dest . '/x', 0755, true);
        $zip = $this->makeZip(['x' => 'file-content']);

        $this->expectException(\RuntimeException::class);
        ZipSafety::extractFile($zip, $dest);
    }
}
