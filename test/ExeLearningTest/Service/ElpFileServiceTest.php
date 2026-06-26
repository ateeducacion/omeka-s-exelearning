<?php

declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\ElpFileService;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Manager as ApiManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZipArchive;

/**
 * Unit tests for ElpFileService.
 *
 * @covers \ExeLearning\Service\ElpFileService
 */
class ElpFileServiceTest extends TestCase
{
    private string $testDir;
    private string $filesPath;
    private ElpFileService $service;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/exelearning-test-' . uniqid();
        $this->filesPath = $this->testDir . '/files';

        mkdir($this->testDir, 0755, true);
        mkdir($this->filesPath . '/original', 0755, true);

        // Create service with stub dependencies
        $this->service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $this->testDir . '/exelearning',
            $this->filesPath
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createTestZip(string $path, array $files): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    // =========================================================================
    // validateElpFile() tests
    // =========================================================================

    public function testValidateElpFileWithContentV3Xml(): void
    {
        $zipPath = $this->testDir . '/test.elpx';
        $this->createTestZip($zipPath, [
            'contentv3.xml' => '<?xml version="1.0"?><content></content>',
            'index.html' => '<html></html>',
        ]);

        $this->assertTrue($this->service->validateElpFile($zipPath));
    }

    public function testValidateElpFileWithContentXml(): void
    {
        $zipPath = $this->testDir . '/test.elpx';
        $this->createTestZip($zipPath, [
            'content.xml' => '<?xml version="1.0"?><content></content>',
        ]);

        $this->assertTrue($this->service->validateElpFile($zipPath));
    }

    public function testValidateElpFileWithIndexHtmlOnly(): void
    {
        $zipPath = $this->testDir . '/test.elpx';
        $this->createTestZip($zipPath, [
            'index.html' => '<html><body>Test</body></html>',
        ]);

        $this->assertTrue($this->service->validateElpFile($zipPath));
    }

    public function testValidateElpFileReturnsFalseForEmptyZip(): void
    {
        $zipPath = $this->testDir . '/empty.zip';
        $this->createTestZip($zipPath, [
            'random.txt' => 'Some random content',
        ]);

        $this->assertFalse($this->service->validateElpFile($zipPath));
    }

    public function testValidateElpFileReturnsFalseForNonExistentFile(): void
    {
        $this->assertFalse($this->service->validateElpFile('/non/existent/file.elpx'));
    }

    public function testValidateElpFileReturnsFalseForInvalidZip(): void
    {
        $path = $this->testDir . '/invalid.zip';
        file_put_contents($path, 'This is not a zip file');

        $this->assertFalse($this->service->validateElpFile($path));
    }

    // =========================================================================
    // getMediaHash() tests
    // =========================================================================

    public function testGetMediaHashReturnsHash(): void
    {
        $expectedHash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_extracted_hash' => $expectedHash]
        );

        $this->assertEquals($expectedHash, $this->service->getMediaHash($media));
    }

    public function testGetMediaHashReturnsNullWhenNoHash(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            []
        );

        $this->assertNull($this->service->getMediaHash($media));
    }

    // =========================================================================
    // hasPreview() tests
    // =========================================================================

    public function testHasPreviewReturnsTrue(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_has_preview' => '1']
        );

        $this->assertTrue($this->service->hasPreview($media));
    }

    public function testHasPreviewReturnsFalse(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_has_preview' => '0']
        );

        $this->assertFalse($this->service->hasPreview($media));
    }

    public function testHasPreviewReturnsFalseWhenNotSet(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            []
        );

        $this->assertFalse($this->service->hasPreview($media));
    }

    // =========================================================================
    // isProcessed() tests
    // =========================================================================

    public function testIsProcessedReturnsTrueWhenMarked(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_processed' => '1']
        );

        $this->assertTrue($this->service->isProcessed($media));
    }

    public function testIsProcessedReturnsFalseWhenNotMarked(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_has_preview' => '1']
        );

        $this->assertFalse($this->service->isProcessed($media));
    }

    public function testIsProcessedReturnsFalseWhenAbsent(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            []
        );

        $this->assertFalse($this->service->isProcessed($media));
    }

    // =========================================================================
    // getPreviewUrl() tests
    // =========================================================================

    public function testGetPreviewUrlReturnsUrl(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $baseUrl = 'http://example.com';
        $expectedUrl = 'http://example.com/files/exelearning/' . $hash . '/index.html';

        $this->assertEquals($expectedUrl, $this->service->getPreviewUrl($media, $baseUrl));
    }

    public function testGetPreviewUrlReturnsNullWithoutHash(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_has_preview' => '1']
        );

        $this->assertNull($this->service->getPreviewUrl($media, 'http://example.com'));
    }

    public function testGetPreviewUrlReturnsNullWithoutPreview(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'exelearning_has_preview' => '0',
            ]
        );

        $this->assertNull($this->service->getPreviewUrl($media, 'http://example.com'));
    }

    public function testGetPreviewUrlTrimsTrailingSlash(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $url = $this->service->getPreviewUrl($media, 'http://example.com/');
        $this->assertStringStartsWith('http://example.com/files', $url);
        $this->assertStringNotContainsString('//', substr($url, 7)); // after http://
    }

    // =========================================================================
    // getMediaFilePath() tests
    // =========================================================================

    public function testGetMediaFilePath(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'abc123.elpx',
            1
        );

        $expected = $this->filesPath . '/original/abc123.elpx';
        $this->assertEquals($expected, $this->service->getMediaFilePath($media));
    }

    // =========================================================================
    // generateHash() tests (protected method)
    // =========================================================================

    public function testGenerateHashReturns40CharHex(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('generateHash');
        $method->setAccessible(true);

        $hash = $method->invokeArgs($this->service, [$this->testDir . '/test.txt']);

        $this->assertEquals(40, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $hash);
    }

    public function testGenerateHashIsUnique(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('generateHash');
        $method->setAccessible(true);

        $hash1 = $method->invokeArgs($this->service, [$this->testDir . '/test1.txt']);
        $hash2 = $method->invokeArgs($this->service, [$this->testDir . '/test2.txt']);

        $this->assertNotEquals($hash1, $hash2);
    }

    // =========================================================================
    // deleteDirectory() tests (protected method)
    // =========================================================================

    public function testDeleteDirectoryRemovesDirectory(): void
    {
        $dir = $this->testDir . '/to-delete';
        mkdir($dir . '/subdir', 0755, true);
        file_put_contents($dir . '/file.txt', 'test');
        file_put_contents($dir . '/subdir/nested.txt', 'nested');

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('deleteDirectory');
        $method->setAccessible(true);
        $method->invokeArgs($this->service, [$dir]);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testDeleteDirectoryDoesNothingForNonExistent(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('deleteDirectory');
        $method->setAccessible(true);

        // Should not throw
        $method->invokeArgs($this->service, ['/non/existent/directory']);
        $this->assertTrue(true);
    }

    // =========================================================================
    // extractZip() tests (protected method)
    // =========================================================================

    public function testExtractZipExtractsFiles(): void
    {
        $zipPath = $this->testDir . '/test.zip';
        $extractPath = $this->testDir . '/extracted';

        $this->createTestZip($zipPath, [
            'index.html' => '<html></html>',
            'css/styles.css' => 'body { color: red; }',
        ]);

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('extractZip');
        $method->setAccessible(true);
        $method->invokeArgs($this->service, [$zipPath, $extractPath]);

        $this->assertFileExists($extractPath . '/index.html');
        $this->assertFileExists($extractPath . '/css/styles.css');
    }

    public function testExtractZipThrowsForInvalidZip(): void
    {
        $invalidPath = $this->testDir . '/invalid.zip';
        file_put_contents($invalidPath, 'not a zip');

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('extractZip');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to extract ZIP file');
        $method->invokeArgs($this->service, [$invalidPath, $this->testDir . '/out']);
    }

    // =========================================================================
    // log() tests (protected method)
    // =========================================================================

    public function testLogDoesNothingWithoutLogger(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('log');
        $method->setAccessible(true);

        // Should not throw - just does nothing without a logger
        $method->invokeArgs($this->service, ['info', 'Test message']);
        $this->assertTrue(true);
    }

    // =========================================================================
    // createSecurityHtaccess() tests (protected method)
    // =========================================================================

    public function testCreateSecurityHtaccess(): void
    {
        $basePath = $this->testDir . '/exelearning';
        mkdir($basePath, 0755, true);

        // Create service with this base path
        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('createSecurityHtaccess');
        $method->setAccessible(true);
        $method->invoke($service);

        $htaccessPath = $basePath . '/.htaccess';
        $this->assertFileExists($htaccessPath);

        $content = file_get_contents($htaccessPath);
        $this->assertStringContainsString('Deny from all', $content);
        $this->assertStringContainsString('Require all denied', $content);
    }

    // =========================================================================
    // cleanupMedia() tests
    // =========================================================================

    public function testCleanupMediaRemovesDirectory(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $basePath = $this->testDir . '/exelearning';
        $hashDir = $basePath . '/' . $hash;

        mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/index.html', '<html></html>');

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath
        );

        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_extracted_hash' => $hash]
        );

        $service->cleanupMedia($media);

        $this->assertDirectoryDoesNotExist($hashDir);
    }

    public function testCleanupMediaDoesNothingWithoutHash(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            []
        );

        // Should not throw
        $this->service->cleanupMedia($media);
        $this->assertTrue(true);
    }

    // =========================================================================
    // isTeacherModeVisible() tests
    // =========================================================================

    public function testIsTeacherModeVisibleReturnsFalseWhenNotSet(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            []
        );

        $this->assertFalse($this->service->isTeacherModeVisible($media));
    }

    public function testIsTeacherModeVisibleReturnsFalseForZero(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_teacher_mode_visible' => '0']
        );

        $this->assertFalse($this->service->isTeacherModeVisible($media));
    }

    public function testIsTeacherModeVisibleReturnsFalseForFalse(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_teacher_mode_visible' => 'false']
        );

        $this->assertFalse($this->service->isTeacherModeVisible($media));
    }

    public function testIsTeacherModeVisibleReturnsFalseForNo(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_teacher_mode_visible' => 'no']
        );

        $this->assertFalse($this->service->isTeacherModeVisible($media));
    }

    public function testIsTeacherModeVisibleReturnsTrueForOne(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_teacher_mode_visible' => '1']
        );

        $this->assertTrue($this->service->isTeacherModeVisible($media));
    }

    public function testIsTeacherModeVisibleReturnsTrueForYes(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_teacher_mode_visible' => 'yes']
        );

        $this->assertTrue($this->service->isTeacherModeVisible($media));
    }

    // =========================================================================
    // setTeacherModeVisible() tests
    // =========================================================================

    public function testSetTeacherModeVisibleCallsUpdateMediaData(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1
        );

        // Should not throw - updateMediaData handles entity not found gracefully
        $this->service->setTeacherModeVisible($media, true);
        $this->assertTrue(true);
    }

    public function testSetTeacherModeVisibleFalse(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1
        );

        // Should not throw
        $this->service->setTeacherModeVisible($media, false);
        $this->assertTrue(true);
    }

    // =========================================================================
    // Additional validateElpFile tests
    // =========================================================================

    public function testValidateElpFileWithAllContentTypes(): void
    {
        // Test with contentv3.xml, content.xml, and index.html together
        $zipPath = $this->testDir . '/complete.elpx';
        $this->createTestZip($zipPath, [
            'contentv3.xml' => '<?xml version="1.0"?><content></content>',
            'content.xml' => '<?xml version="1.0"?><content></content>',
            'index.html' => '<html><body>Test</body></html>',
            'css/styles.css' => 'body { color: red; }',
        ]);

        $this->assertTrue($this->service->validateElpFile($zipPath));
    }

    // =========================================================================
    // getMediaFilePath edge cases
    // =========================================================================

    public function testGetMediaFilePathWithSubdirectory(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/subdir/file.elpx',
            'Test File',
            'subdir/abc123.elpx',
            1
        );

        $expected = $this->filesPath . '/original/subdir/abc123.elpx';
        $this->assertEquals($expected, $this->service->getMediaFilePath($media));
    }

    // =========================================================================
    // getPreviewUrl edge cases
    // =========================================================================

    public function testGetPreviewUrlWithBaseUrlWithoutSlash(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $url = $this->service->getPreviewUrl($media, 'http://example.com');
        $this->assertEquals('http://example.com/files/exelearning/' . $hash . '/index.html', $url);
    }

    public function testGetPreviewUrlWithMultipleTrailingSlashes(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $url = $this->service->getPreviewUrl($media, 'http://example.com///');
        $this->assertStringContainsString('/files/exelearning/', $url);
    }

    // =========================================================================
    // processUploadedFile() tests
    // =========================================================================

    public function testProcessUploadedFileThrowsWhenFileNotExists(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'nonexistent.elpx',
            1
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Media file not found');
        $this->service->processUploadedFile($media);
    }

    public function testProcessUploadedFileExtractsContent(): void
    {
        // Create a test zip file in the files directory
        $zipPath = $this->filesPath . '/original/test.elpx';
        $this->createTestZip($zipPath, [
            'index.html' => '<html><body>Test</body></html>',
            'contentv3.xml' => '<?xml version="1.0"?><content></content>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/test.elpx',
            'Test File',
            'test.elpx',
            1
        );

        $result = $this->service->processUploadedFile($media);

        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('hasPreview', $result);
        $this->assertArrayHasKey('extractPath', $result);
        $this->assertTrue($result['hasPreview']);
        $this->assertDirectoryExists($result['extractPath']);
        $this->assertFileExists($result['extractPath'] . '/index.html');
    }

    public function testProcessUploadedFileWithoutIndexHtml(): void
    {
        $zipPath = $this->filesPath . '/original/no-index.elpx';
        $this->createTestZip($zipPath, [
            'contentv3.xml' => '<?xml version="1.0"?><content></content>',
            'content/page1.html' => '<html></html>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/no-index.elpx',
            'Test File',
            'no-index.elpx',
            1
        );

        $result = $this->service->processUploadedFile($media);

        $this->assertFalse($result['hasPreview']);
    }

    public function testProcessUploadedFileCreatesBasePathIfNotExists(): void
    {
        // Remove the base path if it exists
        $basePath = $this->testDir . '/exelearning-new';

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath
        );

        $zipPath = $this->filesPath . '/original/new-test.elpx';
        $this->createTestZip($zipPath, [
            'index.html' => '<html></html>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/new-test.elpx',
            'Test File',
            'new-test.elpx',
            1
        );

        $result = $service->processUploadedFile($media);

        $this->assertDirectoryExists($basePath);
        $this->assertFileExists($basePath . '/.htaccess');
    }

    public function testProcessUploadedFileCreatesHtaccessIfMissing(): void
    {
        // Create base path without .htaccess
        $basePath = $this->testDir . '/exelearning-htaccess';
        mkdir($basePath, 0755, true);
        // Ensure no .htaccess exists
        @unlink($basePath . '/.htaccess');

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath
        );

        $zipPath = $this->filesPath . '/original/htaccess-test.elpx';
        $this->createTestZip($zipPath, [
            'index.html' => '<html></html>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/htaccess-test.elpx',
            'Test File',
            'htaccess-test.elpx',
            1
        );

        $result = $service->processUploadedFile($media);

        $this->assertFileExists($basePath . '/.htaccess');
    }

    // =========================================================================
    // replaceFile() tests
    // =========================================================================

    public function testReplaceFileThrowsForInvalidFile(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1
        );

        // Create an invalid file (not a zip)
        $invalidPath = $this->testDir . '/invalid.txt';
        file_put_contents($invalidPath, 'This is not a zip');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid eXeLearning file');
        $this->service->replaceFile($media, $invalidPath);
    }

    public function testReplaceFileWithValidFile(): void
    {
        // Create original file
        $originalPath = $this->filesPath . '/original/replace-test.elpx';
        $this->createTestZip($originalPath, [
            'index.html' => '<html>Original</html>',
        ]);

        // Create new file to replace with
        $newPath = $this->testDir . '/new-file.elpx';
        $this->createTestZip($newPath, [
            'index.html' => '<html>New content</html>',
            'contentv3.xml' => '<?xml version="1.0"?><content></content>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/replace-test.elpx',
            'Test File',
            'replace-test.elpx',
            1
        );

        $result = $this->service->replaceFile($media, $newPath);

        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('hasPreview', $result);
        $this->assertTrue($result['hasPreview']);
    }

    public function testReplaceFileCleansUpOldContent(): void
    {
        // Create base path and old extracted content
        $basePath = $this->testDir . '/exelearning-cleanup';
        $oldHash = 'oldhash123456789012345678901234567890ab';
        $oldExtractPath = $basePath . '/' . $oldHash;
        mkdir($oldExtractPath, 0755, true);
        file_put_contents($oldExtractPath . '/old-file.html', 'old content');

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath
        );

        // Create original file
        $originalPath = $this->filesPath . '/original/cleanup-test.elpx';
        $this->createTestZip($originalPath, [
            'index.html' => '<html>Original</html>',
        ]);

        // Create new file
        $newPath = $this->testDir . '/new-cleanup.elpx';
        $this->createTestZip($newPath, [
            'index.html' => '<html>New</html>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/cleanup-test.elpx',
            'Test File',
            'cleanup-test.elpx',
            1,
            ['exelearning_extracted_hash' => $oldHash]
        );

        $result = $service->replaceFile($media, $newPath);

        // Old directory should be deleted
        $this->assertDirectoryDoesNotExist($oldExtractPath);
        // New directory should exist
        $this->assertDirectoryExists($result['extractPath']);
    }

    // =========================================================================
    // updateMediaData() tests (via EntityManager stub)
    // =========================================================================

    public function testUpdateMediaDataLogsError(): void
    {
        // Test that updateMediaData handles entity not found
        $basePath = $this->testDir . '/exelearning-update';

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath,
            null
        );

        // Create test file
        $originalPath = $this->filesPath . '/original/log-test.elpx';
        $this->createTestZip($originalPath, [
            'index.html' => '<html></html>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/log-test.elpx',
            'Test File',
            'log-test.elpx',
            999  // Non-existent ID
        );

        $result = $service->processUploadedFile($media);

        // Should complete without throwing
        $this->assertArrayHasKey('hash', $result);
    }

    // =========================================================================
    // Constructor and property tests
    // =========================================================================

    public function testServiceConstructorSetsProperties(): void
    {
        $api = new ApiManager();
        $entityManager = new EntityManager();
        $basePath = '/test/base/path';
        $filesPath = '/test/files/path';

        $service = new ElpFileService($api, $entityManager, $basePath, $filesPath);

        $reflection = new ReflectionClass($service);

        $basePathProp = $reflection->getProperty('basePath');
        $basePathProp->setAccessible(true);
        $this->assertEquals($basePath, $basePathProp->getValue($service));

        $filesPathProp = $reflection->getProperty('filesPath');
        $filesPathProp->setAccessible(true);
        $this->assertEquals($filesPath, $filesPathProp->getValue($service));
    }

    public function testServiceConstructorWithNullLogger(): void
    {
        $api = new ApiManager();
        $entityManager = new EntityManager();
        $basePath = '/test/base/path';
        $filesPath = '/test/files/path';

        $service = new ElpFileService($api, $entityManager, $basePath, $filesPath, null);

        $reflection = new ReflectionClass($service);
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $this->assertNull($loggerProp->getValue($service));
    }

    public function testServiceConstructorWithoutLogger(): void
    {
        $api = new ApiManager();
        $entityManager = new EntityManager();
        $basePath = '/test/base/path';
        $filesPath = '/test/files/path';

        // Call without logger argument
        $service = new ElpFileService($api, $entityManager, $basePath, $filesPath);

        $reflection = new ReflectionClass($service);
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $this->assertNull($loggerProp->getValue($service));
    }

    // =========================================================================
    // log() tests with actual logger
    // =========================================================================

    public function testLogCallsLoggerInfo(): void
    {
        $logger = new \Laminas\Log\Logger();

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $this->testDir . '/exelearning',
            $this->filesPath,
            $logger
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('log');
        $method->setAccessible(true);
        $method->invokeArgs($service, ['info', 'Test info message']);

        $messages = $logger->getMessages();
        $this->assertCount(1, $messages);
        $this->assertEquals('info', $messages[0]['level']);
        $this->assertStringContainsString('Test info message', $messages[0]['message']);
    }

    public function testLogCallsLoggerErr(): void
    {
        $logger = new \Laminas\Log\Logger();

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $this->testDir . '/exelearning',
            $this->filesPath,
            $logger
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('log');
        $method->setAccessible(true);
        $method->invokeArgs($service, ['err', 'Test error message']);

        $messages = $logger->getMessages();
        $this->assertCount(1, $messages);
        $this->assertEquals('err', $messages[0]['level']);
    }

    public function testLogCallsLoggerWarn(): void
    {
        $logger = new \Laminas\Log\Logger();

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $this->testDir . '/exelearning',
            $this->filesPath,
            $logger
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('log');
        $method->setAccessible(true);
        $method->invokeArgs($service, ['warn', 'Test warning message']);

        $messages = $logger->getMessages();
        $this->assertCount(1, $messages);
        $this->assertEquals('warn', $messages[0]['level']);
    }

    // =========================================================================
    // updateMediaData() tests with entity found
    // =========================================================================

    public function testUpdateMediaDataWithEntityFound(): void
    {
        $logger = new \Laminas\Log\Logger();

        // Create a mock entity manager that returns a media entity
        $mockMediaEntity = new class {
            private array $data = [];
            public function getData(): ?array { return $this->data; }
            public function setData(array $data): void { $this->data = $data; }
        };

        $mockEntityManager = new class($mockMediaEntity) extends EntityManager {
            private $entity;
            public function __construct($entity) { $this->entity = $entity; }
            public function find(string $class, $id) {
                return $this->entity;
            }
            public function persist($entity): void {}
            public function flush(): void {}
            public function refresh($entity): void {}
        };

        $basePath = $this->testDir . '/exelearning-update-entity';
        $service = new ElpFileService(
            new ApiManager(),
            $mockEntityManager,
            $basePath,
            $this->filesPath,
            $logger
        );

        // Create a test zip file
        $zipPath = $this->filesPath . '/original/update-entity-test.elpx';
        $this->createTestZip($zipPath, [
            'index.html' => '<html></html>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/update-entity-test.elpx',
            'Test File',
            'update-entity-test.elpx',
            1
        );

        $result = $service->processUploadedFile($media);

        $this->assertArrayHasKey('hash', $result);
        // Check that the entity data was set
        $entityData = $mockMediaEntity->getData();
        $this->assertArrayHasKey('exelearning_extracted_hash', $entityData);
        $this->assertArrayHasKey('exelearning_has_preview', $entityData);
    }

    // =========================================================================
    // Error handling edge cases
    // =========================================================================

    public function testProcessUploadedFileLogsDebugInfo(): void
    {
        $logger = new \Laminas\Log\Logger();

        $basePath = $this->testDir . '/exelearning-debug';
        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath,
            $logger
        );

        $zipPath = $this->filesPath . '/original/debug-test.elpx';
        $this->createTestZip($zipPath, [
            'index.html' => '<html></html>',
            'contentv3.xml' => '<?xml version="1.0"?>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/debug-test.elpx',
            'Test File',
            'debug-test.elpx',
            1
        );

        $service->processUploadedFile($media);

        // Should have logged multiple info messages
        $messages = $logger->getMessages();
        $infoMessages = array_filter($messages, fn($m) => $m['level'] === 'info');
        $this->assertGreaterThan(3, count($infoMessages));
    }

    // =========================================================================
    // Screenshot detection / URL tests
    // =========================================================================

    public function testHasScreenshotReturnsFalseWhenNotSet(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            []
        );

        $this->assertFalse($this->service->hasScreenshot($media));
    }

    public function testHasScreenshotReturnsTrueWhenFlagged(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_has_screenshot' => '1']
        );

        $this->assertTrue($this->service->hasScreenshot($media));
    }

    public function testHasScreenshotReturnsFalseForZeroFlag(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_has_screenshot' => '0']
        );

        $this->assertFalse($this->service->hasScreenshot($media));
    }

    public function testGetScreenshotPathReturnsNullWithoutHash(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_has_screenshot' => '1']
        );

        $this->assertNull($this->service->getScreenshotPath($media));
    }

    public function testGetScreenshotPathReturnsNullWithoutScreenshotFlag(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_extracted_hash' => $hash]
        );

        $this->assertNull($this->service->getScreenshotPath($media));
    }

    public function testGetScreenshotPathReturnsNullWhenFileMissing(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_screenshot' => '1',
            ]
        );

        // No screenshot.png on disk under basePath/{hash}/
        $this->assertNull($this->service->getScreenshotPath($media));
    }

    public function testGetScreenshotPathReturnsAbsolutePathWhenPresent(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $basePath = $this->testDir . '/exelearning';
        mkdir($basePath . '/' . $hash, 0755, true);
        $screenshotPath = $basePath . '/' . $hash . '/screenshot.png';
        file_put_contents($screenshotPath, 'fake png');

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath
        );

        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_screenshot' => '1',
            ]
        );

        $this->assertEquals($screenshotPath, $service->getScreenshotPath($media));
    }

    public function testGetScreenshotUrlReturnsProxiedUrl(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_screenshot' => '1',
            ]
        );

        $url = $this->service->getScreenshotUrl($media, 'http://example.com');
        $this->assertEquals(
            'http://example.com/exelearning/content/' . $hash . '/screenshot.png',
            $url
        );
    }

    public function testGetScreenshotUrlReturnsNullWithoutHash(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_has_screenshot' => '1']
        );

        $this->assertNull($this->service->getScreenshotUrl($media, 'http://example.com'));
    }

    public function testGetScreenshotUrlReturnsNullWithoutFlag(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            ['exelearning_extracted_hash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709']
        );

        $this->assertNull($this->service->getScreenshotUrl($media, 'http://example.com'));
    }

    public function testGetScreenshotUrlNeverPointsToFilesDirectory(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_screenshot' => '1',
            ]
        );

        $url = $this->service->getScreenshotUrl($media, 'http://example.com');
        $this->assertStringNotContainsString('/files/exelearning/', $url);
        $this->assertStringContainsString('/exelearning/content/', $url);
    }

    public function testGetScreenshotUrlTrimsTrailingSlash(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'file.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_screenshot' => '1',
            ]
        );

        $url = $this->service->getScreenshotUrl($media, 'http://example.com/');
        $this->assertEquals(
            'http://example.com/exelearning/content/' . $hash . '/screenshot.png',
            $url
        );
    }

    public function testProcessUploadedFileDetectsScreenshot(): void
    {
        $zipPath = $this->filesPath . '/original/with-screenshot.elpx';
        $this->createTestZip($zipPath, [
            'index.html' => '<html></html>',
            'contentv3.xml' => '<?xml version="1.0"?><c/>',
            'screenshot.png' => 'fake png bytes',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/with-screenshot.elpx',
            'With Screenshot',
            'with-screenshot.elpx',
            1
        );

        $result = $this->service->processUploadedFile($media);

        $this->assertArrayHasKey('hasScreenshot', $result);
        $this->assertTrue($result['hasScreenshot']);
        $this->assertFileExists($result['extractPath'] . '/screenshot.png');
    }

    public function testProcessUploadedFileWithoutScreenshot(): void
    {
        $zipPath = $this->filesPath . '/original/no-screenshot.elpx';
        $this->createTestZip($zipPath, [
            'index.html' => '<html></html>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/no-screenshot.elpx',
            'No Screenshot',
            'no-screenshot.elpx',
            1
        );

        $result = $this->service->processUploadedFile($media);

        $this->assertArrayHasKey('hasScreenshot', $result);
        $this->assertFalse($result['hasScreenshot']);
        $this->assertFileDoesNotExist($result['extractPath'] . '/screenshot.png');
    }

    public function testReplaceFileRefreshesScreenshotFlag(): void
    {
        $basePath = $this->testDir . '/exelearning-screenshot-replace';

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath
        );

        // Original .elpx with screenshot
        $originalPath = $this->filesPath . '/original/replace-screenshot.elpx';
        $this->createTestZip($originalPath, [
            'index.html' => '<html>old</html>',
            'screenshot.png' => 'old png',
        ]);

        // Replacement .elpx WITHOUT screenshot
        $newPath = $this->testDir . '/new-without-screenshot.elpx';
        $this->createTestZip($newPath, [
            'index.html' => '<html>new</html>',
        ]);

        $media = new MediaRepresentation(
            'http://example.com/replace-screenshot.elpx',
            'Replace Screenshot',
            'replace-screenshot.elpx',
            1
        );

        $result = $service->replaceFile($media, $newPath);

        $this->assertFalse($result['hasScreenshot']);
        $this->assertFileDoesNotExist($result['extractPath'] . '/screenshot.png');
    }

    public function testReplaceFileWithoutOldHash(): void
    {
        $basePath = $this->testDir . '/exelearning-no-hash';

        $service = new ElpFileService(
            new ApiManager(),
            new EntityManager(),
            $basePath,
            $this->filesPath
        );

        // Create original file
        $originalPath = $this->filesPath . '/original/no-hash-test.elpx';
        $this->createTestZip($originalPath, [
            'index.html' => '<html>Original</html>',
        ]);

        // Create new file
        $newPath = $this->testDir . '/new-no-hash.elpx';
        $this->createTestZip($newPath, [
            'index.html' => '<html>New</html>',
        ]);

        // Media without hash
        $media = new MediaRepresentation(
            'http://example.com/no-hash-test.elpx',
            'Test File',
            'no-hash-test.elpx',
            1,
            [] // No existing hash
        );

        $result = $service->replaceFile($media, $newPath);

        // Should complete successfully even without old hash
        $this->assertArrayHasKey('hash', $result);
    }
}
