<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Entity\Media;
use Doctrine\ORM\EntityManager;
use Laminas\Log\Logger;
use ZipArchive;

/**
 * Service for handling eXeLearning files.
 *
 * Provides methods to extract, validate, and manage .elpx files.
 */
class ElpFileService
{
    /**
     * Filename of the preview image bundled by eXeLearning at the root of
     * every .elpx package. When present, used as the media thumbnail.
     */
    const SCREENSHOT_FILENAME = 'screenshot.png';

    /** @var ApiManager */
    protected $api;

    /** @var EntityManager */
    protected $entityManager;

    /** @var string */
    protected $basePath;

    /** @var string */
    protected $filesPath;

    /** @var Logger|null */
    protected $logger;

    /** @var object|null Optional Omeka\File\TempFileFactory */
    protected $tempFileFactory;

    /**
     * @param ApiManager $api
     * @param EntityManager $entityManager
     * @param string $basePath Path to module's data/exelearning directory
     * @param string $filesPath Path to Omeka's files directory
     * @param Logger|null $logger
     * @param object|null $tempFileFactory Omeka\File\TempFileFactory (optional;
     *                                     required for thumbnail generation)
     */
    public function __construct(
        ApiManager $api,
        EntityManager $entityManager,
        string $basePath,
        string $filesPath,
        ?Logger $logger = null,
        $tempFileFactory = null
    ) {
        $this->api = $api;
        $this->entityManager = $entityManager;
        $this->basePath = $basePath;
        $this->filesPath = $filesPath;
        $this->logger = $logger;
        $this->tempFileFactory = $tempFileFactory;
    }

    /**
     * Log a message.
     *
     * @codeCoverageIgnore
     */
    protected function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->$level('[ExeLearning] ' . $message);
        }
    }

    /**
     * Process an uploaded eXeLearning file.
     *
     * @param MediaRepresentation $media
     * @return array Result with hash and hasPreview
     * @throws \Exception
     *
     * @codeCoverageIgnore
     */
    public function processUploadedFile(MediaRepresentation $media): array
    {
        $this->log('info', sprintf('Processing media %d', $media->id()));

        $filePath = $this->getMediaFilePath($media);
        $this->log('info', sprintf('File path: %s', $filePath));

        if (!file_exists($filePath)) {
            $this->log('err', sprintf('File not found: %s', $filePath));
            throw new \Exception('Media file not found: ' . $filePath);
        }

        $this->log('info', sprintf('File exists, size: %d bytes', filesize($filePath)));

        // Generate unique hash
        $hash = $this->generateHash($filePath);
        $this->log('info', sprintf('Generated hash: %s', $hash));

        // Ensure base path exists
        if (!is_dir($this->basePath)) {
            $this->log('info', sprintf('Creating base path: %s', $this->basePath));
            if (!@mkdir($this->basePath, 0755, true)) {
                $error = error_get_last();
                $this->log('err', sprintf('Failed to create base path: %s - Error: %s', $this->basePath, $error['message'] ?? 'unknown'));
                throw new \Exception('Failed to create base directory: ' . $this->basePath);
            }
            // Create security .htaccess to prevent direct access
            $this->createSecurityHtaccess();
        } else {
            $this->log('info', sprintf('Base path already exists: %s, writable: %s', $this->basePath, is_writable($this->basePath) ? 'yes' : 'no'));
            // Ensure .htaccess exists even if directory already exists
            if (!file_exists($this->basePath . '/.htaccess')) {
                $this->createSecurityHtaccess();
            }
        }

        // Extract to data directory
        $extractPath = $this->basePath . '/' . $hash;
        $this->log('info', sprintf('Extracting to: %s', $extractPath));
        $this->extractZip($filePath, $extractPath);

        // Check if index.html exists
        $hasPreview = file_exists($extractPath . '/index.html');
        $this->log('info', sprintf('Has preview (index.html): %s', $hasPreview ? 'yes' : 'no'));

        // Detect bundled screenshot.png (used as media thumbnail).
        $screenshotPath = $extractPath . '/' . self::SCREENSHOT_FILENAME;
        $hasScreenshot = file_exists($screenshotPath);
        $this->log('info', sprintf('Has screenshot.png: %s', $hasScreenshot ? 'yes' : 'no'));

        // List extracted files for debugging
        if (is_dir($extractPath)) {
            $files = scandir($extractPath);
            $this->log('info', sprintf('Extracted files: %s', implode(', ', array_slice($files, 0, 10))));
        }

        // Store metadata using Omeka's data system
        $this->log('info', 'Updating media data...');
        $this->updateMediaData($media, [
            'exelearning_extracted_hash' => $hash,
            'exelearning_has_preview' => $hasPreview ? '1' : '0',
            'exelearning_has_screenshot' => $hasScreenshot ? '1' : '0',
        ]);

        // Generate Omeka thumbnail derivatives from screenshot.png.
        // This is best-effort: failures must not break upload processing.
        if ($hasScreenshot) {
            $this->generateThumbnailsFromScreenshot($media, $screenshotPath);
        }

        $this->log('info', 'Processing complete');

        return [
            'hash' => $hash,
            'hasPreview' => $hasPreview,
            'hasScreenshot' => $hasScreenshot,
            'extractPath' => $extractPath,
        ];
    }

    /**
     * Replace an existing eXeLearning file.
     *
     * @param MediaRepresentation $media
     * @param string $newFilePath Path to the new file
     * @return array Result with new hash and previewUrl
     * @throws \Exception
     *
     * @codeCoverageIgnore
     */
    public function replaceFile(MediaRepresentation $media, string $newFilePath): array
    {
        // Get old hash to cleanup
        $oldHash = $this->getMediaHash($media);

        // Get the original file path
        $originalPath = $this->getMediaFilePath($media);

        // Validate the new file
        if (!$this->validateElpFile($newFilePath)) {
            throw new \Exception('Invalid eXeLearning file');
        }

        // Replace the file
        if (!copy($newFilePath, $originalPath)) {
            throw new \Exception('Failed to replace file');
        }

        // Clean up old extracted content
        if ($oldHash) {
            $this->deleteDirectory($this->basePath . '/' . $oldHash);
        }

        // Process the new file
        return $this->processUploadedFile($media);
    }

    /**
     * Clean up extracted content when media is deleted.
     *
     * @param MediaRepresentation $media
     */
    public function cleanupMedia(MediaRepresentation $media): void
    {
        $hash = $this->getMediaHash($media);
        if ($hash) {
            $this->deleteDirectory($this->basePath . '/' . $hash);
        }
    }

    /**
     * Validate an eXeLearning file.
     *
     * @param string $filePath
     * @return bool
     */
    public function validateElpFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $zip = new ZipArchive();
        $result = $zip->open($filePath);

        if ($result !== true) {
            return false;
        }

        // Check for common eXeLearning files
        $hasContent = $zip->locateName('contentv3.xml') !== false
            || $zip->locateName('content.xml') !== false
            || $zip->locateName('index.html') !== false;

        $zip->close();

        return $hasContent;
    }

    /**
     * Get the extracted hash for a media item.
     *
     * @param MediaRepresentation $media
     * @return string|null
     */
    public function getMediaHash(MediaRepresentation $media): ?string
    {
        $data = $media->mediaData();
        return $data['exelearning_extracted_hash'] ?? null;
    }

    /**
     * Check if media has a preview.
     *
     * @param MediaRepresentation $media
     * @return bool
     */
    public function hasPreview(MediaRepresentation $media): bool
    {
        $data = $media->mediaData();
        return ($data['exelearning_has_preview'] ?? '0') === '1';
    }

    /**
     * Check whether teacher mode toggler should be visible for this media.
     *
     * @param MediaRepresentation $media
     * @return bool
     */
    public function isTeacherModeVisible(MediaRepresentation $media): bool
    {
        $data = $media->mediaData();
        if (!isset($data['exelearning_teacher_mode_visible'])) {
            return true;
        }

        $value = $data['exelearning_teacher_mode_visible'];
        return !in_array((string) $value, ['0', 'false', 'no'], true);
    }

    /**
     * Persist teacher mode visibility setting for this media.
     *
     * @param MediaRepresentation $media
     * @param bool $visible
     */
    public function setTeacherModeVisible(MediaRepresentation $media, bool $visible): void
    {
        $this->updateMediaData($media, [
            'exelearning_teacher_mode_visible' => $visible ? '1' : '0',
        ]);
    }

    /**
     * Get the preview URL for a media item.
     *
     * @param MediaRepresentation $media
     * @param string $baseUrl
     * @return string|null
     */
    public function getPreviewUrl(MediaRepresentation $media, string $baseUrl): ?string
    {
        $hash = $this->getMediaHash($media);
        if (!$hash || !$this->hasPreview($media)) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/files/exelearning/' . $hash . '/index.html';
    }

    /**
     * Whether the .elpx package bundled a screenshot.png at its root.
     *
     * @param MediaRepresentation $media
     * @return bool
     */
    public function hasScreenshot(MediaRepresentation $media): bool
    {
        $data = $media->mediaData();
        return ($data['exelearning_has_screenshot'] ?? '0') === '1';
    }

    /**
     * Absolute filesystem path to the bundled screenshot.png, or null if
     * the media has no screenshot or has not been extracted.
     *
     * @param MediaRepresentation $media
     * @return string|null
     */
    public function getScreenshotPath(MediaRepresentation $media): ?string
    {
        $hash = $this->getMediaHash($media);
        if (!$hash || !$this->hasScreenshot($media)) {
            return null;
        }

        $path = $this->basePath . '/' . $hash . '/' . self::SCREENSHOT_FILENAME;
        return file_exists($path) ? $path : null;
    }

    /**
     * Public URL to the bundled screenshot.png, served through the secure
     * content proxy (never directly from /files/exelearning/).
     *
     * @param MediaRepresentation $media
     * @param string $baseUrl Site base URL (with optional path prefix).
     * @return string|null
     */
    public function getScreenshotUrl(MediaRepresentation $media, string $baseUrl): ?string
    {
        $hash = $this->getMediaHash($media);
        if (!$hash || !$this->hasScreenshot($media)) {
            return null;
        }

        return rtrim($baseUrl, '/')
            . '/exelearning/content/' . $hash . '/' . self::SCREENSHOT_FILENAME;
    }

    /**
     * Get the filesystem path to a media file.
     *
     * @param MediaRepresentation $media
     * @return string
     */
    public function getMediaFilePath(MediaRepresentation $media): string
    {
        $filename = $media->filename();
        return $this->filesPath . '/original/' . $filename;
    }

    /**
     * Generate a unique hash for a file.
     *
     * @param string $filePath
     * @return string
     *
     * @codeCoverageIgnore
     */
    protected function generateHash(string $filePath): string
    {
        return sha1($filePath . microtime(true) . random_bytes(16));
    }

    /**
     * Extract a ZIP file to a directory.
     *
     * @param string $zipPath
     * @param string $extractPath
     * @throws \Exception
     *
     * @codeCoverageIgnore
     */
    protected function extractZip(string $zipPath, string $extractPath): void
    {
        // Create directory if needed
        if (!is_dir($extractPath)) {
            if (!@mkdir($extractPath, 0755, true)) {
                throw new \Exception('Failed to create extract directory: ' . $extractPath);
            }
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw new \Exception('Failed to open ZIP file: error code ' . $result);
        }

        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new \Exception('Failed to extract ZIP file to: ' . $extractPath);
        }

        $zip->close();
        $this->log('info', sprintf('ZIP extracted successfully to %s', $extractPath));
    }

    /**
     * Build Omeka thumbnail derivatives (large/medium/square) from a
     * screenshot.png and persist hasThumbnails on the media entity.
     *
     * Best-effort: any failure is logged but does not propagate, so a
     * faulty screenshot never blocks an .elpx upload.
     *
     * @param MediaRepresentation $media
     * @param string $screenshotPath Absolute path to the extracted PNG.
     *
     * @codeCoverageIgnore
     */
    protected function generateThumbnailsFromScreenshot(
        MediaRepresentation $media,
        string $screenshotPath
    ): void {
        if (!$this->tempFileFactory) {
            $this->log('info', 'No TempFileFactory available; skipping thumbnail generation');
            return;
        }

        if (!is_file($screenshotPath)) {
            $this->log('warn', sprintf('Screenshot not found at %s', $screenshotPath));
            return;
        }

        $mediaEntity = $this->entityManager->find(Media::class, $media->id());
        if (!$mediaEntity) {
            $this->log('warn', sprintf('Media entity %d not found for thumbnails', $media->id()));
            return;
        }

        // Copy screenshot to a temp file because TempFile may delete its source.
        $tempPath = tempnam(sys_get_temp_dir(), 'elpx-thumb-');
        if ($tempPath === false || !@copy($screenshotPath, $tempPath)) {
            $this->log('err', 'Failed to copy screenshot.png to temp location');
            return;
        }

        try {
            $tempFile = $this->tempFileFactory->build();
            $tempFile->setSourceName(self::SCREENSHOT_FILENAME);
            $tempFile->setTempPath($tempPath);
            $tempFile->setStorageId($mediaEntity->getStorageId());

            $hasThumbnails = (bool) $tempFile->storeThumbnails();

            $mediaEntity->setHasThumbnails($hasThumbnails);
            $this->entityManager->persist($mediaEntity);
            $this->entityManager->flush();

            $this->log(
                'info',
                sprintf('Thumbnails %s for media %d', $hasThumbnails ? 'generated' : 'skipped', $media->id())
            );
        } catch (\Throwable $e) {
            $this->log('err', sprintf('Thumbnail generation failed: %s', $e->getMessage()));
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Update media data with eXeLearning metadata.
     *
     * @param MediaRepresentation $media
     * @param array $data
     *
     * @codeCoverageIgnore
     */
    protected function updateMediaData(MediaRepresentation $media, array $data): void
    {
        $this->log('info', sprintf('Updating media data for ID %d', $media->id()));

        // Get the entity directly from entity manager
        $mediaEntity = $this->entityManager->find(Media::class, $media->id());

        if (!$mediaEntity) {
            $this->log('err', sprintf('Media entity %d not found', $media->id()));
            return;
        }

        // Merge with existing data
        $existingData = $mediaEntity->getData() ?? [];
        $this->log('info', sprintf('Existing data: %s', json_encode($existingData)));

        $newData = array_merge($existingData, $data);
        $this->log('info', sprintf('New data: %s', json_encode($newData)));

        $mediaEntity->setData($newData);

        // Persist and flush
        $this->entityManager->persist($mediaEntity);
        $this->entityManager->flush();

        $this->log('info', 'Media data updated and flushed');

        // Verify the update
        $this->entityManager->refresh($mediaEntity);
        $verifyData = $mediaEntity->getData();
        $this->log('info', sprintf('Verified data after flush: %s', json_encode($verifyData)));
    }

    /**
     * Create a security .htaccess file to block direct access.
     *
     * This forces all content to be served through the secure proxy controller.
     *
     * @codeCoverageIgnore
     */
    protected function createSecurityHtaccess(): void
    {
        $htaccessPath = $this->basePath . '/.htaccess';
        $htaccessContent = <<<'HTACCESS'
# Security: Block direct access to eXeLearning extracted content
# All content must be served through the secure proxy controller
# which adds proper security headers (CSP, X-Frame-Options, etc.)

# Deny all direct access
<IfModule mod_authz_core.c>
    # Apache 2.4+
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    # Apache 2.2
    Order deny,allow
    Deny from all
</IfModule>

# Alternative: return 403 for all requests
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^ - [F,L]
</IfModule>
HTACCESS;

        if (@file_put_contents($htaccessPath, $htaccessContent) === false) {
            $this->log('warn', 'Failed to create .htaccess security file');
        } else {
            $this->log('info', 'Created .htaccess security file');
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir
     *
     * @codeCoverageIgnore
     */
    protected function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
