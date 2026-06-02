<?php

declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * Downloads and installs the static eXeLearning editor from GitHub Releases
 * into the module's dist/static/ directory.
 */
class StaticEditorInstaller
{
    /** GitHub Atom feed for latest releases. */
    const RELEASES_FEED_URL = 'https://github.com/exelearning/exelearning/releases.atom';

    /** GitHub REST API endpoint for a release's metadata (tag appended). */
    const RELEASES_API_URL = 'https://api.github.com/repos/exelearning/exelearning/releases/tags/v';

    /** Asset filename prefix. */
    const ASSET_PREFIX = 'exelearning-static-v';

    /** Settings key for installed version. */
    const SETTING_VERSION = 'exelearning_editor_installed_version';

    /** Settings key for installation timestamp. */
    const SETTING_INSTALLED_AT = 'exelearning_editor_installed_at';

    /** Settings key for installation phase. */
    const SETTING_INSTALL_PHASE = 'exelearning_editor_install_phase';

    /** Settings key for installation message. */
    const SETTING_INSTALL_MESSAGE = 'exelearning_editor_install_message';

    /** Settings key for target version. */
    const SETTING_INSTALL_TARGET_VERSION = 'exelearning_editor_install_target_version';

    /** Settings key for installation started at timestamp. */
    const SETTING_INSTALL_STARTED_AT = 'exelearning_editor_install_started_at';

    /** Settings key for installation success flag. */
    const SETTING_INSTALL_SUCCESS = 'exelearning_editor_install_success';

    /** Settings key for final installation error. */
    const SETTING_INSTALL_ERROR = 'exelearning_editor_install_error';

    /** Lock TTL in seconds. */
    const INSTALL_LOCK_TTL = 600;

    /** @var callable|null */
    private $statusCallback;

    /**
     * Check if the static editor is installed locally.
     */
    public static function isEditorInstalled(): bool
    {
        return file_exists(self::getEditorPath() . '/index.html');
    }

    /**
     * Get the local editor directory path.
     */
    public static function getEditorPath(): string
    {
        return dirname(__DIR__, 2) . '/dist/static';
    }

    /**
     * Register a callback for installation phase updates.
     *
     * The callback receives: phase, message, extra payload array.
     */
    public function setStatusCallback(?callable $callback): self
    {
        $this->statusCallback = $callback;
        return $this;
    }

    /**
     * Return the persisted install status.
     */
    public static function getStoredInstallStatus($settings): array
    {
        $phase = (string) $settings->get(self::SETTING_INSTALL_PHASE, 'idle');
        $startedAt = (int) $settings->get(self::SETTING_INSTALL_STARTED_AT, 0);

        return [
            'phase' => $phase,
            'message' => (string) $settings->get(self::SETTING_INSTALL_MESSAGE, ''),
            'target_version' => (string) $settings->get(self::SETTING_INSTALL_TARGET_VERSION, ''),
            'started_at' => $startedAt,
            'success' => (bool) $settings->get(self::SETTING_INSTALL_SUCCESS, false),
            'error' => (string) $settings->get(self::SETTING_INSTALL_ERROR, ''),
            'running' => self::isRunningPhase($phase) && self::isFreshLock($startedAt),
            'stale' => self::isRunningPhase($phase) && !self::isFreshLock($startedAt),
        ];
    }

    /**
     * Persist install status.
     */
    public static function storeInstallStatus(
        $settings,
        string $phase,
        string $message = '',
        array $extra = []
    ): void {
        $settings->set(self::SETTING_INSTALL_PHASE, $phase);
        $settings->set(self::SETTING_INSTALL_MESSAGE, $message);

        if (array_key_exists('target_version', $extra)) {
            $settings->set(self::SETTING_INSTALL_TARGET_VERSION, (string) $extra['target_version']);
        }

        if (array_key_exists('started_at', $extra)) {
            $settings->set(self::SETTING_INSTALL_STARTED_AT, (int) $extra['started_at']);
        }

        if (array_key_exists('success', $extra)) {
            $settings->set(self::SETTING_INSTALL_SUCCESS, (bool) $extra['success']);
        }

        if (array_key_exists('error', $extra)) {
            $settings->set(self::SETTING_INSTALL_ERROR, (string) $extra['error']);
        }
    }

    /**
     * Reset install status to idle.
     */
    public static function resetInstallStatus($settings): void
    {
        self::storeInstallStatus($settings, 'idle', '', [
            'target_version' => '',
            'started_at' => 0,
            'success' => false,
            'error' => '',
        ]);
    }

    /**
     * Check whether a running install lock is still fresh.
     */
    public static function isFreshLock(int $startedAt): bool
    {
        return $startedAt > 0 && (time() - $startedAt) < self::INSTALL_LOCK_TTL;
    }

    /**
     * Check whether a phase is a running phase.
     */
    public static function isRunningPhase(string $phase): bool
    {
        return in_array($phase, ['checking', 'downloading', 'extracting', 'validating', 'installing'], true);
    }

    /**
     * Install the latest static editor from GitHub Releases.
     *
     * @return array{version: string, installed_at: string}
     * @throws \RuntimeException on failure
     *
     * @codeCoverageIgnore
     */
    public function installLatestEditor(): array
    {
        $this->reportStatus('checking', 'Checking latest version...'); // @translate
        $version = $this->discoverLatestVersion();

        $this->reportStatus('downloading', 'Downloading editor...', ['target_version' => $version]); // @translate
        $assetUrl = $this->getAssetUrl($version);
        $tmpFile = $this->downloadAsset($assetUrl);

        try {
            // Bind the download to GitHub's published release metadata, not just
            // transport TLS. Older releases predate asset digests, so a missing
            // digest falls back to TLS rather than blocking the install; a
            // published digest that does not match aborts.
            $expectedSha256 = $this->fetchReleaseAssetSha256($version, $this->getAssetFilename($version));
            if ($expectedSha256 !== null) {
                $this->reportStatus('validating', 'Verifying download integrity...', ['target_version' => $version]); // @translate
                $this->verifyFileSha256($tmpFile, $expectedSha256);
            }

            $this->reportStatus('extracting', 'Extracting editor package...', ['target_version' => $version]); // @translate
            $this->validateZip($tmpFile);
            $tmpDir = $this->extractZip($tmpFile);
        } finally {
            $this->cleanupFile($tmpFile);
        }

        try {
            $this->reportStatus('validating', 'Validating editor files...', ['target_version' => $version]); // @translate
            $sourceDir = $this->normalizeExtraction($tmpDir);
            $this->validateEditorContents($sourceDir);

            $this->reportStatus('installing', 'Installing editor...', ['target_version' => $version]); // @translate
            $this->safeInstall($sourceDir);
        } finally {
            $this->cleanupDirectory($tmpDir);
        }

        return [
            'version' => $version,
            'installed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Discover the latest release version from the GitHub Atom feed.
     *
     * @throws \RuntimeException
     *
     * @codeCoverageIgnore
     */
    public function discoverLatestVersion(): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: application/atom+xml,application/xml,text/xml',
                    'User-Agent: OmekaS-ExeLearning-Module',
                ]),
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents(self::RELEASES_FEED_URL, false, $context);
        if ($response === false) {
            throw new \RuntimeException(
                'Could not connect to GitHub. Please check your internet connection or install the module from a release package.' // @translate
            );
        }

        return $this->extractVersionFromFeed($response);
    }

    /**
     * Extract the latest version from an Atom feed.
     *
     * @throws \RuntimeException
     */
    public function extractVersionFromFeed(string $feed): string
    {
        if (!preg_match('/<entry\\b[^>]*>(.*?)<\\/entry>/si', $feed, $entryMatch)) {
            throw new \RuntimeException(
                'Could not parse the latest release information from GitHub.' // @translate
            );
        }

        $entry = $entryMatch[1];
        $candidate = $this->extractVersionCandidateFromEntry($entry);

        if ($candidate === '') {
            throw new \RuntimeException(
                'Could not parse the latest release information from GitHub.' // @translate
            );
        }

        return $this->normalizeVersionCandidate($candidate);
    }

    /**
     * Build the static editor ZIP asset filename for a version.
     */
    public function getAssetFilename(string $version): string
    {
        return self::ASSET_PREFIX . $version . '.zip';
    }

    /**
     * Build the download URL for the static editor asset.
     *
     * The version has already been validated to a strict semver-ish shape, so
     * rawurlencode is belt-and-suspenders against any path-injection.
     */
    public function getAssetUrl(string $version): string
    {
        return 'https://github.com/exelearning/exelearning/releases/download/v'
            . rawurlencode($version) . '/' . $this->getAssetFilename($version);
    }

    /**
     * Fetch the GitHub-published SHA-256 digest for a release asset, or null if
     * GitHub cannot be queried or the release does not publish a digest.
     *
     * @codeCoverageIgnore
     */
    public function fetchReleaseAssetSha256(string $version, string $assetName): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: application/vnd.github+json',
                    'User-Agent: OmekaS-ExeLearning-Module',
                    'X-GitHub-Api-Version: 2022-11-28',
                ]),
                'timeout' => 30,
            ],
        ]);

        $json = @file_get_contents(self::RELEASES_API_URL . rawurlencode($version), false, $context);
        if ($json === false) {
            return null;
        }

        return $this->extractAssetSha256FromReleaseJson($json, $assetName);
    }

    /**
     * Extract the lowercase SHA-256 hex digest for one asset from a GitHub
     * Releases API JSON body, or null if absent/malformed.
     */
    public function extractAssetSha256FromReleaseJson(string $json, string $assetName): ?string
    {
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['assets']) || !is_array($data['assets'])) {
            return null;
        }

        foreach ($data['assets'] as $asset) {
            if (!is_array($asset) || (string) ($asset['name'] ?? '') !== $assetName) {
                continue;
            }
            $digest = strtolower((string) ($asset['digest'] ?? ''));
            if (preg_match('/^sha256:([a-f0-9]{64})$/', $digest, $matches)) {
                return $matches[1];
            }
            return null;
        }

        return null;
    }

    /**
     * Verify a downloaded file against an expected SHA-256 digest.
     *
     * @throws \RuntimeException on a read failure or digest mismatch.
     */
    public function verifyFileSha256(string $filePath, string $expectedSha256): void
    {
        $actual = hash_file('sha256', $filePath);
        if ($actual === false || !hash_equals(strtolower($expectedSha256), strtolower($actual))) {
            throw new \RuntimeException(
                'The downloaded editor package failed integrity verification (SHA-256 mismatch).' // @translate
            );
        }
    }

    /**
     * Download the asset ZIP file to a temp location.
     *
     * @throws \RuntimeException
     *
     * @codeCoverageIgnore
     */
    public function downloadAsset(string $url): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'exelearning-editor-');
        if ($tmpFile === false) {
            throw new \RuntimeException(
                'Could not create temporary file for download.' // @translate
            );
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: OmekaS-ExeLearning-Module',
                'timeout' => 300,
                'follow_location' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            $this->cleanupFile($tmpFile);
            throw new \RuntimeException(
                'Failed to download the editor package.' // @translate
            );
        }

        if (file_put_contents($tmpFile, $content) === false) {
            $this->cleanupFile($tmpFile);
            throw new \RuntimeException('Failed to write downloaded content to temp file.');
        }

        return $tmpFile;
    }

    /**
     * Validate that a file is a ZIP archive by checking PK magic bytes.
     *
     * @throws \RuntimeException
     */
    public function validateZip(string $filePath): void
    {
        $header = file_get_contents($filePath, false, null, 0, 4);
        if ($header !== "PK\x03\x04") {
            throw new \RuntimeException(
                'The downloaded file is not a valid ZIP archive.' // @translate
            );
        }
    }

    /**
     * Extract a ZIP file to a temporary directory.
     *
     * @throws \RuntimeException
     */
    public function extractZip(string $zipFile): string
    {
        $tmpDir = sys_get_temp_dir() . '/exelearning-editor-' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0755, true)) {
            throw new \RuntimeException(
                'Could not create temporary directory for extraction.' // @translate
            );
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            $this->cleanupDirectory($tmpDir);
            throw new \RuntimeException(
                'Failed to open the editor package for extraction.' // @translate
            );
        }

        // Extract entry-by-entry with zip-slip/zip-bomb guards instead of a
        // blind extractTo() (defense-in-depth; the same hardening the .elpx
        // path uses).
        try {
            ZipSafety::extract($zip, $tmpDir);
        } catch (\RuntimeException $e) {
            $zip->close();
            $this->cleanupDirectory($tmpDir);
            throw $e;
        }
        $zip->close();

        return $tmpDir;
    }

    /**
     * Normalize extraction layout.
     *
     * The ZIP may contain files directly or inside a top-level directory.
     *
     * @throws \RuntimeException
     */
    public function normalizeExtraction(string $tmpDir): string
    {
        if (file_exists($tmpDir . '/index.html')) {
            return $tmpDir;
        }

        $entries = array_diff(scandir($tmpDir), ['.', '..']);
        if (count($entries) === 1) {
            $singleEntry = $tmpDir . '/' . reset($entries);
            if (is_dir($singleEntry) && file_exists($singleEntry . '/index.html')) {
                return $singleEntry;
            }
        }

        foreach ($entries as $entry) {
            $entryPath = $tmpDir . '/' . $entry;
            if (is_dir($entryPath)) {
                $subEntries = array_diff(scandir($entryPath), ['.', '..']);
                if (count($subEntries) === 1) {
                    $subEntry = $entryPath . '/' . reset($subEntries);
                    if (is_dir($subEntry) && file_exists($subEntry . '/index.html')) {
                        return $subEntry;
                    }
                }
            }
        }

        throw new \RuntimeException(
            'The downloaded package does not contain the expected editor files. Could not find index.html.' // @translate
        );
    }

    /**
     * Validate that extracted contents look like a valid static editor.
     *
     * @throws \RuntimeException
     */
    public function validateEditorContents(string $sourceDir): void
    {
        if (!file_exists($sourceDir . '/index.html')) {
            throw new \RuntimeException(
                'The editor package is missing index.html.' // @translate
            );
        }

        $expectedDirs = ['app', 'libs', 'files'];
        $foundDir = false;
        foreach ($expectedDirs as $dir) {
            if (is_dir($sourceDir . '/' . $dir)) {
                $foundDir = true;
                break;
            }
        }

        if (!$foundDir) {
            throw new \RuntimeException(
                'The editor package is missing expected asset directories (app, libs, or files).' // @translate
            );
        }
    }

    /**
     * Install the editor with rollback on failure.
     *
     * @throws \RuntimeException
     *
     * @codeCoverageIgnore
     */
    public function safeInstall(string $sourceDir): void
    {
        $targetDir = self::getEditorPath();
        $parentDir = dirname($targetDir);
        $backupDir = $parentDir . '/static-backup-' . time();

        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true)) {
            throw new \RuntimeException(
                'Could not create the dist directory.' // @translate
            );
        }

        $hadExisting = is_dir($targetDir);
        if ($hadExisting) {
            if (!rename($targetDir, $backupDir)) {
                throw new \RuntimeException(
                    'Could not back up the existing editor installation.' // @translate
                );
            }
        }

        $installed = @rename($sourceDir, $targetDir);
        if (!$installed) {
            $installed = $this->recursiveCopy($sourceDir, $targetDir);
        }

        if (!$installed) {
            if ($hadExisting && is_dir($backupDir)) {
                if (is_dir($targetDir)) {
                    $this->cleanupDirectory($targetDir);
                }
                rename($backupDir, $targetDir);
            }
            throw new \RuntimeException(
                'Failed to copy editor files to the module directory.' // @translate
            );
        }

        if ($hadExisting && is_dir($backupDir)) {
            $this->cleanupDirectory($backupDir);
        }
    }

    /**
     * Recursively copy a directory.
     *
     * @codeCoverageIgnore
     */
    private function recursiveCopy(string $source, string $dest): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($dest) && !mkdir($dest, 0755, true)) {
            return false;
        }

        $dir = opendir($source);
        if (!$dir) {
            return false;
        }

        while (($entry = readdir($dir)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $entry;
            $destPath = $dest . '/' . $entry;

            if (is_dir($sourcePath)) {
                if (!$this->recursiveCopy($sourcePath, $destPath)) {
                    closedir($dir);
                    return false;
                }
            } else {
                if (!copy($sourcePath, $destPath)) {
                    closedir($dir);
                    return false;
                }
            }
        }

        closedir($dir);
        return true;
    }

    /**
     * Clean up a temporary file.
     *
     * @codeCoverageIgnore
     */
    private function cleanupFile(string $file): void
    {
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Clean up a temporary directory recursively.
     *
     * @codeCoverageIgnore
     */
    public function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanupDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Report an installation phase update.
     */
    private function reportStatus(string $phase, string $message, array $extra = []): void
    {
        if ($this->statusCallback) {
            ($this->statusCallback)($phase, $message, $extra);
        }
    }

    /**
     * Extract a version candidate from an Atom entry.
     */
    private function extractVersionCandidateFromEntry(string $entry): string
    {
        if (preg_match('/<link\\b[^>]*href="([^"]*\\/tag\\/v?([^"\\/]+))"[^>]*>/i', $entry, $matches)) {
            return html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
        }

        if (preg_match('/<title\\b[^>]*>(.*?)<\\/title>/si', $entry, $matches)) {
            return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5));
        }

        return '';
    }

    /**
     * Normalize a raw version candidate.
     *
     * @throws \RuntimeException
     */
    private function normalizeVersionCandidate(string $candidate): string
    {
        $version = trim(ltrim($candidate, 'v'));

        // Anchor both ends: a tag like "4.0.0 ../../x" or "4.0.0; rm" must be
        // rejected, not just prefix-matched, before it reaches a download URL.
        if (!preg_match('/^\\d+\\.\\d+(?:\\.\\d+)?(?:-[0-9A-Za-z.]+)?$/', $version)) {
            throw new \RuntimeException(
                sprintf('Unexpected release tag format: %s', $candidate) // @translate
            );
        }

        return $version;
    }
}
