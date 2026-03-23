<?php

declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * Downloads and installs the static eXeLearning editor from GitHub Releases
 * into the module's dist/static/ directory.
 */
class StaticEditorInstaller
{
    /** GitHub API URL for latest release. */
    const GITHUB_API_URL = 'https://api.github.com/repos/exelearning/exelearning/releases/latest';

    /** jsDelivr API URL (CORS-friendly fallback for PHP-WASM / Playground). */
    const JSDELIVR_API_URL = 'https://data.jsdelivr.com/v1/packages/gh/exelearning/exelearning/resolved?specifier=latest';

    /** jsDelivr CDN base for downloading repo files. */
    const JSDELIVR_CDN_BASE = 'https://cdn.jsdelivr.net/gh/exelearning/exelearning';

    /** Asset filename prefix. */
    const ASSET_PREFIX = 'exelearning-static-v';

    /** Settings key for installed version. */
    const SETTING_VERSION = 'exelearning_editor_installed_version';

    /** Settings key for installation timestamp. */
    const SETTING_INSTALLED_AT = 'exelearning_editor_installed_at';

    /**
     * Route a URL through the CORS proxy when running in Omeka S Playground.
     * In normal Omeka installations the URL is returned unchanged.
     */
    private static function proxyUrl(string $url): string
    {
        if (defined('OMEKA_PLAYGROUND') && defined('OMEKA_PLAYGROUND_PROXY_URL') && OMEKA_PLAYGROUND_PROXY_URL !== '') {
            return rtrim(OMEKA_PLAYGROUND_PROXY_URL, '/') . '?url=' . urlencode($url);
        }
        return $url;
    }

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
     * Install the latest static editor from GitHub Releases.
     *
     * @return array{version: string, installed_at: string}
     * @throws \RuntimeException on failure
     *
     * @codeCoverageIgnore
     */
    public function installLatestEditor(): array
    {
        $version = $this->discoverLatestVersion();
        $assetUrl = $this->getAssetUrl($version);

        $tmpFile = $this->downloadAsset($assetUrl);

        try {
            $this->validateZip($tmpFile);
            $tmpDir = $this->extractZip($tmpFile);
        } finally {
            $this->cleanupFile($tmpFile);
        }

        try {
            $sourceDir = $this->normalizeExtraction($tmpDir);
            $this->validateEditorContents($sourceDir);
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
     * Install the editor from a local ZIP file (e.g. uploaded by the browser).
     *
     * @param string $zipPath Path to the ZIP file
     * @param string $version Version string
     * @return array{version: string, installed_at: string}
     * @throws \RuntimeException on failure
     */
    public function installFromFile(string $zipPath, string $version): array
    {
        $this->validateZip($zipPath);
        $tmpDir = $this->extractZip($zipPath);

        try {
            $sourceDir = $this->normalizeExtraction($tmpDir);
            $this->validateEditorContents($sourceDir);
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
     * Discover the latest release version from GitHub.
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
                    'Accept: application/vnd.github.v3+json',
                    'User-Agent: OmekaS-ExeLearning-Module',
                ]),
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents(self::proxyUrl(self::GITHUB_API_URL), false, $context);
        if ($response === false) {
            // Try jsDelivr as fallback (works in PHP-WASM / Playground environments where direct GitHub access fails due to CORS).
            $response = @file_get_contents(self::proxyUrl(self::JSDELIVR_API_URL), false, $context);
        }
        if ($response === false) {
            throw new \RuntimeException(
                'Could not connect to GitHub. Please check your internet connection or install the editor from a release package.' // @translate
            );
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException(
                'Could not parse the latest release information from GitHub.' // @translate
            );
        }

        // GitHub API returns { tag_name: "v4.0.0" }
        // jsDelivr API returns { version: "4.0.0-beta2" }
        $tagName = $data['tag_name'] ?? $data['version'] ?? '';
        if (empty($tagName)) {
            throw new \RuntimeException(
                'Could not parse the latest release information from GitHub.' // @translate
            );
        }

        $version = ltrim($tagName, 'v');

        if (!preg_match('/^\d+\.\d+/', $version)) {
            throw new \RuntimeException(
                sprintf('Unexpected release tag format: %s', $data['tag_name']) // @translate
            );
        }

        return $version;
    }

    /**
     * Build the download URL for the static editor asset.
     */
    public function getAssetUrl(string $version): string
    {
        $filename = self::ASSET_PREFIX . $version . '.zip';
        return 'https://github.com/exelearning/exelearning/releases/download/v' . $version . '/' . $filename;
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

        $content = @file_get_contents(self::proxyUrl($url), false, $context);

        // Fallback: try jsDelivr CDN mirror for CORS-restricted environments (PHP-WASM).
        if ($content === false && preg_match('#/download/v([^/]+)/#', $url, $m)) {
            $tag = 'v' . $m[1];
            $jsdelivrUrl = self::JSDELIVR_CDN_BASE . '@' . $tag . '/dist/static.zip';
            $content = @file_get_contents(self::proxyUrl($jsdelivrUrl), false, $context);
        }

        if ($content === false) {
            $this->cleanupFile($tmpFile);
            throw new \RuntimeException(
                'Failed to download the editor package.'
                . ' This may happen in browser-based environments.'
                . ' Please install the module from a release package that includes the editor.' // @translate
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

        $zip->extractTo($tmpDir);
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

        // Check one more level deep.
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

        // Try rename first (fast, same-filesystem). Fall back to copy.
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
        // Check before unlink: in Playground (PHP-WASM), the file may have been
        // cleaned up by a concurrent process between download and installation.
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
}
