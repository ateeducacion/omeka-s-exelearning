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
        if (!file_exists($filePath)) {
            $this->log('err', sprintf('File not found: %s', $filePath));
            throw new \Exception('Media file not found: ' . $filePath);
        }

        // Remember any prior extraction so we can drop it after a successful
        // re-process (otherwise every re-process leaks an orphan directory).
        $oldHash = $this->getMediaHash($media);

        // Only extract genuine eXeLearning packages. Anything else is marked
        // processed so the view hooks do not re-check (and re-extract) it on
        // every render.
        if (!$this->validateElpFile($filePath)) {
            $this->log('info', sprintf('Media %d is not a valid eXeLearning package; skipping extraction', $media->id()));
            $this->updateMediaData($media, [
                'exelearning_has_preview' => '0',
                'exelearning_has_screenshot' => '0',
                'exelearning_processed' => '1',
            ]);
            return [
                'hash' => $oldHash,
                'hasPreview' => false,
                'hasScreenshot' => false,
                'extractPath' => null,
            ];
        }

        $hash = $this->generateHash($filePath);
        $this->ensureBasePath();

        $extractPath = $this->basePath . '/' . $hash;
        $this->log('info', sprintf('Extracting to: %s', $extractPath));
        try {
            $this->extractZip($filePath, $extractPath);
        } catch (\Throwable $e) {
            // Remove any partially extracted files so a rejected upload leaves no
            // orphaned directory behind.
            $this->deleteDirectory($extractPath);
            throw $e;
        }

        $result = $this->finalizeExtraction($media, $extractPath, $hash);

        // Drop the previous extraction now that the new one is committed.
        if ($oldHash && $oldHash !== $hash) {
            $this->deleteDirectory($this->basePath . '/' . $oldHash);
        }

        $this->log('info', 'Processing complete');
        return $result;
    }

    /**
     * Ensure the extraction base directory and its blocking .htaccess exist.
     *
     * @throws \Exception
     *
     * @codeCoverageIgnore
     */
    private function ensureBasePath(): void
    {
        if (!is_dir($this->basePath)) {
            if (!@mkdir($this->basePath, 0755, true) && !is_dir($this->basePath)) {
                $error = error_get_last();
                throw new \Exception(
                    'Failed to create base directory: ' . $this->basePath
                    . ' - ' . ($error['message'] ?? 'unknown')
                );
            }
            $this->createSecurityHtaccess();
        } elseif (!file_exists($this->basePath . '/.htaccess')) {
            $this->createSecurityHtaccess();
        }
    }

    /**
     * Detect preview/screenshot in a fresh extraction, persist the metadata
     * (including the processed marker) and build thumbnails.
     *
     * @param MediaRepresentation $media
     * @param string $extractPath
     * @param string $hash
     * @return array
     *
     * @codeCoverageIgnore
     */
    private function finalizeExtraction(MediaRepresentation $media, string $extractPath, string $hash): array
    {
        $hasPreview = file_exists($extractPath . '/index.html');
        $screenshotPath = $extractPath . '/' . self::SCREENSHOT_FILENAME;
        $hasScreenshot = file_exists($screenshotPath);

        $this->updateMediaData($media, [
            'exelearning_extracted_hash' => $hash,
            'exelearning_has_preview' => $hasPreview ? '1' : '0',
            'exelearning_has_screenshot' => $hasScreenshot ? '1' : '0',
            'exelearning_processed' => '1',
        ]);

        // Best-effort: a faulty screenshot must never block ingestion.
        if ($hasScreenshot) {
            $this->generateThumbnailsFromScreenshot($media, $screenshotPath);
        }

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
        // Validate the new file
        if (!$this->validateElpFile($newFilePath)) {
            throw new \Exception('Invalid eXeLearning file');
        }

        $originalPath = $this->getMediaFilePath($media);
        $oldHash = $this->getMediaHash($media);

        // 1. Extract the NEW upload into a fresh staging dir FIRST. A corrupt or
        //    unextractable archive throws here, leaving the original file, its
        //    existing extraction and the media data untouched (transactional
        //    save — no data loss on failure).
        $newHash = $this->generateHash($newFilePath);
        $this->ensureBasePath();
        $stagingPath = $this->basePath . '/' . $newHash;
        try {
            $this->extractZip($newFilePath, $stagingPath);
        } catch (\Throwable $e) {
            $this->deleteDirectory($stagingPath);
            throw $e;
        }

        // 2. Commit: overwrite the original, verifying the copy landed intact
        //    (guards against truncated writes, e.g. php-wasm OPFS quota).
        $expectedSize = filesize($newFilePath);
        if (!copy($newFilePath, $originalPath)) {
            $this->deleteDirectory($stagingPath);
            throw new \Exception('Failed to replace file');
        }
        if ($expectedSize !== false && filesize($originalPath) !== $expectedSize) {
            $this->deleteDirectory($stagingPath);
            throw new \Exception('File copy verification failed (size mismatch)');
        }

        // 3. Point the media at the new extraction, then (only now) drop the old.
        $result = $this->finalizeExtraction($media, $stagingPath, $newHash);
        if ($oldHash && $oldHash !== $newHash) {
            $this->deleteDirectory($this->basePath . '/' . $oldHash);
        }

        return $result;
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
     * Whether this media has already been processed (extracted or rejected).
     *
     * Used by the view hooks to avoid re-extracting on every page view — a
     * preview-less but already-processed package would otherwise be re-ingested
     * on every render, accumulating orphan extraction directories.
     *
     * @param MediaRepresentation $media
     * @return bool
     */
    public function isProcessed(MediaRepresentation $media): bool
    {
        $data = $media->mediaData();
        return ($data['exelearning_processed'] ?? '0') === '1';
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
            if (!@mkdir($extractPath, 0755, true) && !is_dir($extractPath)) {
                throw new \Exception('Failed to create extract directory: ' . $extractPath);
            }
        }

        // The .elpx is attacker-controlled: extract entry-by-entry with zip-slip
        // rejection and an uncompressed-size/entry-count cap (zip-bomb), instead
        // of a blind ZipArchive::extractTo().
        try {
            ZipSafety::extractFile($zipPath, $extractPath);
        } catch (\RuntimeException $e) {
            throw new \Exception('Failed to extract ZIP file to: ' . $extractPath . ' (' . $e->getMessage() . ')');
        }

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
