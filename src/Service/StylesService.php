<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Omeka\Settings\Settings;
use Laminas\Log\LoggerInterface;

/**
 * Service for managing eXeLearning style packages uploaded by administrators.
 *
 * Handles ZIP validation, installation, listing, enable/disable state, and
 * builds the themeRegistryOverride payload consumed by the embedded editor.
 *
 * Uploaded styles extract to:
 *   {OMEKA_PATH}/files/exelearning-styles/{slug}/
 *
 * The registry (built-in disable list + uploaded metadata + block-import
 * flag) is persisted in Omeka's settings store under keys documented below.
 *
 * Built-in themes are discovered by reading the bundled editor's
 * dist/static/data/bundle.json; writes never happen inside dist/static/,
 * so reinstalling the embedded editor never destroys admin-managed styles.
 */
class StylesService
{
    /** @var string Omeka setting key for the registry JSON. */
    public const SETTING_REGISTRY = 'exelearning_styles_registry';

    /** @var string Omeka setting key for the block-import flag. */
    public const SETTING_BLOCK_IMPORT = 'exelearning_styles_block_import';

    /** @var string Directory under {OMEKA_PATH}/files/ that holds uploaded styles. */
    public const STORAGE_SUBDIR = 'exelearning-styles';

    /** @var int Default max upload size (20 MB). */
    public const DEFAULT_MAX_ZIP_SIZE = 20971520;

    /**
     * File extensions allowed inside a style ZIP.
     *
     * @var string[]
     */
    public const ALLOWED_EXTENSIONS = [
        'css', 'js', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
        'xml', 'json', 'md', 'txt', 'html', 'htm',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    private Settings $settings;
    private string $filesPath;
    private string $modulePath;
    private ?LoggerInterface $logger;

    /**
     * @param Settings $settings Omeka global settings service.
     * @param string   $filesPath Absolute path to Omeka's files dir.
     * @param string   $modulePath Absolute path to this module's dir.
     * @param ?LoggerInterface $logger Optional logger.
     */
    public function __construct(
        Settings $settings,
        string $filesPath,
        string $modulePath,
        ?LoggerInterface $logger = null
    ) {
        $this->settings = $settings;
        $this->filesPath = rtrim($filesPath, '/');
        $this->modulePath = rtrim($modulePath, '/');
        $this->logger = $logger;
    }

    // ------------------------------------------------------------------
    // Storage helpers
    // ------------------------------------------------------------------

    public function getStorageDir(): string
    {
        return $this->filesPath . '/' . self::STORAGE_SUBDIR;
    }

    public function getStyleDir(string $slug): string
    {
        return $this->getStorageDir() . '/' . self::normalizeSlug($slug);
    }

    /**
     * Public URL for an uploaded style, served via the styles serve route.
     */
    public function getStyleUrlPath(string $slug): string
    {
        return '/exelearning/styles/' . rawurlencode(self::normalizeSlug($slug));
    }

    public function getMaxZipSize(): int
    {
        return self::DEFAULT_MAX_ZIP_SIZE;
    }

    // ------------------------------------------------------------------
    // Registry persistence
    // ------------------------------------------------------------------

    /**
     * @return array{uploaded: array<string,array>, disabled_builtins: string[]}
     */
    public function getRegistry(): array
    {
        $raw = $this->settings->get(self::SETTING_REGISTRY, null);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = [];
        }
        return [
            'uploaded' => isset($data['uploaded']) && is_array($data['uploaded']) ? $data['uploaded'] : [],
            'disabled_builtins' => isset($data['disabled_builtins']) && is_array($data['disabled_builtins'])
                ? array_values(array_map('strval', $data['disabled_builtins']))
                : [],
        ];
    }

    public function saveRegistry(array $registry): void
    {
        $this->settings->set(self::SETTING_REGISTRY, json_encode($registry));
    }

    public function isImportBlocked(): bool
    {
        // Default: imports allowed (matches upstream ONLINE_THEMES_INSTALL=true).
        // Admins enable the lockdown explicitly from the Styles page.
        $value = $this->settings->get(self::SETTING_BLOCK_IMPORT, null);
        if ($value === null || $value === '') {
            return false;
        }
        return (bool) $value;
    }

    public function setImportBlocked(bool $blocked): void
    {
        $this->settings->set(self::SETTING_BLOCK_IMPORT, $blocked ? '1' : '0');
    }

    // ------------------------------------------------------------------
    // Public listings
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string,string>>
     */
    public function listBuiltinThemes(): array
    {
        $bundlePath = $this->modulePath . '/dist/static/data/bundle.json';
        if (!is_file($bundlePath) || !is_readable($bundlePath)) {
            return [];
        }
        $json = @file_get_contents($bundlePath);
        if ($json === false || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return $this->extractThemesFromBundle(is_array($data) ? $data : []);
    }

    /**
     * Walk a decoded bundle.json payload and return a normalized list of
     * theme entries. Accepts both the double-nested shape the core build
     * emits and a flat-array shape.
     *
     * @param array $data Decoded bundle.
     * @return array<int, array<string,string>>
     */
    public function extractThemesFromBundle(array $data): array
    {
        if (empty($data['themes'])) {
            return [];
        }
        $themes = $data['themes'];
        if (is_array($themes) && isset($themes['themes']) && is_array($themes['themes'])) {
            $themes = $themes['themes'];
        }
        if (!is_array($themes)) {
            return [];
        }
        $out = [];
        foreach ($themes as $theme) {
            if (!is_array($theme) || empty($theme['name'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $theme['name'],
                'name' => (string) $theme['name'],
                'title' => (string) ($theme['title'] ?? $theme['name']),
                'version' => (string) ($theme['version'] ?? ''),
                'description' => (string) ($theme['description'] ?? ''),
                'author' => (string) ($theme['author'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listUploadedStyles(): array
    {
        $registry = $this->getRegistry();
        $out = [];
        foreach ($registry['uploaded'] as $slug => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $meta['id'] = (string) $slug;
            $meta['name'] = (string) $slug;
            $meta['url'] = $this->getStyleUrlPath((string) $slug);
            $meta['path'] = $this->getStyleDir((string) $slug);
            $out[] = $meta;
        }
        return $out;
    }

    /**
     * Build the payload consumed by the editor's themeRegistryOverride hook.
     *
     * @param string $baseUrl Prefix (eg `http://host/basepath`) to produce
     *                        absolute URLs the editor can fetch.
     */
    public function buildThemeRegistryOverride(string $baseUrl = ''): array
    {
        $registry = $this->getRegistry();
        $uploaded = [];
        $prefix = rtrim($baseUrl, '/');
        foreach ($registry['uploaded'] as $slug => $meta) {
            if (!is_array($meta) || empty($meta['enabled'])) {
                continue;
            }
            $cssFiles = isset($meta['css_files']) && is_array($meta['css_files'])
                ? array_values(array_map('strval', $meta['css_files']))
                : ['style.css'];
            $files = $this->listUploadedFiles((string) $slug);
            $styleUrl = $prefix . $this->getStyleUrlPath((string) $slug);
            $uploaded[] = [
                'id' => (string) $slug,
                'name' => (string) $slug,
                'dirName' => (string) $slug,
                'title' => (string) ($meta['title'] ?? $slug),
                'description' => (string) ($meta['description'] ?? ''),
                'version' => (string) ($meta['version'] ?? ''),
                'author' => (string) ($meta['author'] ?? ''),
                'license' => (string) ($meta['license'] ?? ''),
                'type' => 'admin',
                'url' => $styleUrl,
                'cssFiles' => $cssFiles,
                'files' => $files,
                'icons' => $this->scanUploadedIcons((string) $slug, $styleUrl),
                'downloadable' => '0',
                'valid' => true,
            ];
        }
        return [
            'disabledBuiltins' => $registry['disabled_builtins'],
            'uploaded' => $uploaded,
            'blockImportInstall' => $this->isImportBlocked(),
            'fallbackTheme' => 'base',
        ];
    }

    // ------------------------------------------------------------------
    // State mutations
    // ------------------------------------------------------------------

    public function setUploadedEnabled(string $slug, bool $enabled): bool
    {
        $slug = self::normalizeSlug($slug);
        $registry = $this->getRegistry();
        if (!isset($registry['uploaded'][$slug])) {
            return false;
        }
        $registry['uploaded'][$slug]['enabled'] = $enabled;
        $this->saveRegistry($registry);
        return true;
    }

    public function setBuiltinEnabled(string $id, bool $enabled): void
    {
        $id = self::normalizeSlug($id);
        $registry = $this->getRegistry();
        $disabled = $registry['disabled_builtins'];
        if ($enabled) {
            $disabled = array_values(array_filter($disabled, static fn($d) => $d !== $id));
        } elseif (!in_array($id, $disabled, true)) {
            $disabled[] = $id;
        }
        $registry['disabled_builtins'] = $disabled;
        $this->saveRegistry($registry);
    }

    public function deleteUploaded(string $slug): bool
    {
        $slug = self::normalizeSlug($slug);
        $registry = $this->getRegistry();
        if (!isset($registry['uploaded'][$slug])) {
            return false;
        }
        $dir = $this->getStyleDir($slug);
        if (is_dir($dir)) {
            self::recursiveDelete($dir);
        }
        unset($registry['uploaded'][$slug]);
        $this->saveRegistry($registry);
        return true;
    }

    // ------------------------------------------------------------------
    // ZIP install pipeline
    // ------------------------------------------------------------------

    /**
     * Install a style from a ZIP file on disk.
     *
     * @throws \RuntimeException on validation or extraction failure.
     */
    public function installFromZip(string $zipPath, string $origName = ''): array
    {
        $validation = $this->validateZip($zipPath);
        $config = $validation['config'];
        $prefix = $validation['prefix'];

        $requestedSlug = !empty($config['name'])
            ? $config['name']
            : pathinfo($origName, PATHINFO_FILENAME);
        $slug = $this->allocateUniqueSlug((string) $requestedSlug);

        $dest = $this->getStyleDir($slug);
        if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
            throw new \RuntimeException('Failed to create style directory.');
        }

        try {
            $this->extractZipSafely($zipPath, $dest, $prefix);
        } catch (\Throwable $e) {
            self::recursiveDelete($dest);
            throw $e;
        }

        $cssFiles = self::findCssFiles($dest);
        if (empty($cssFiles)) {
            self::recursiveDelete($dest);
            throw new \RuntimeException('The uploaded style does not contain any stylesheet.');
        }

        $entry = [
            'title' => (string) ($config['title'] ?? $slug),
            'version' => (string) ($config['version'] ?? ''),
            'author' => (string) ($config['author'] ?? ''),
            'license' => (string) ($config['license'] ?? ''),
            'description' => (string) ($config['description'] ?? ''),
            'css_files' => $cssFiles,
            'enabled' => true,
            'installed_at' => gmdate('c'),
            'checksum' => self::hashZip($zipPath),
            'size' => (int) @filesize($zipPath),
        ];

        $registry = $this->getRegistry();
        $registry['uploaded'][$slug] = $entry;
        $this->saveRegistry($registry);

        $entry['id'] = $slug;
        $entry['name'] = $slug;

        if ($this->logger) {
            $this->logger->info(sprintf('[ExeLearning] Installed style "%s"', $slug));
        }

        return $entry;
    }

    /**
     * Validate an uploaded ZIP.
     *
     * @return array{config: array<string,string>, prefix: string}
     * @throws \RuntimeException
     */
    public function validateZip(string $zipPath): array
    {
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new \RuntimeException('Uploaded file is missing or unreadable.');
        }
        $size = filesize($zipPath);
        if ($size === false || $size <= 0) {
            throw new \RuntimeException('Uploaded file is empty.');
        }
        if ($size > $this->getMaxZipSize()) {
            throw new \RuntimeException(sprintf(
                'Uploaded style exceeds the maximum allowed size of %d bytes.',
                $this->getMaxZipSize()
            ));
        }
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('The ZipArchive PHP extension is not available.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('The uploaded file is not a readable ZIP archive.');
        }

        $configPath = null;
        $prefix = null;
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                throw new \RuntimeException('The ZIP archive contains unreadable entries.');
            }
            $name = (string) $stat['name'];
            if (self::isUnsafeZipEntry($name)) {
                $zip->close();
                throw new \RuntimeException('Rejected unsafe archive entry: ' . $name);
            }
            $entries[] = $name;
            if (basename($name) === 'config.xml') {
                if ($configPath !== null) {
                    $zip->close();
                    throw new \RuntimeException('The archive contains more than one config.xml.');
                }
                $configPath = $name;
                $dir = trim(str_replace('\\', '/', dirname($name)), '/');
                $prefix = ($dir === '' || $dir === '.') ? '' : $dir . '/';
            }
        }

        if ($configPath === null) {
            $zip->close();
            throw new \RuntimeException('The style package is missing config.xml.');
        }

        foreach ($entries as $entry) {
            if ($prefix !== '' && strpos($entry, $prefix) !== 0) {
                $zip->close();
                throw new \RuntimeException(
                    'The archive must contain a single root folder or place all files at the root.'
                );
            }
            if (!self::isAllowedFilename($entry)) {
                $zip->close();
                throw new \RuntimeException('File type not allowed in style package: ' . $entry);
            }
        }

        $configXml = $zip->getFromName($configPath);
        $zip->close();
        if ($configXml === false) {
            throw new \RuntimeException('config.xml could not be read from the archive.');
        }

        return [
            'config' => self::parseConfigXml($configXml),
            'prefix' => (string) $prefix,
        ];
    }

    /**
     * Parse config.xml into a sanitized associative array.
     *
     * @throws \RuntimeException
     */
    public static function parseConfigXml(string $source): array
    {
        // Reject any DOCTYPE outright and parse WITHOUT LIBXML_NOENT, so an
        // attacker style's config.xml can never declare or substitute an
        // external entity (XXE / local-file disclosure) or expand a billion
        // laughs. LIBXML_NONET additionally blocks network fetches.
        if (preg_match('/<!DOCTYPE/i', $source)) {
            throw new \RuntimeException('config.xml must not contain a DOCTYPE declaration.');
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($source, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new \RuntimeException('config.xml is not valid XML.');
        }
        $name = isset($xml->name) ? trim((string) $xml->name) : '';
        if ($name === '') {
            throw new \RuntimeException('config.xml must declare a <name> element.');
        }
        return [
            'name' => self::normalizeSlug($name),
            'title' => isset($xml->title) ? (string) $xml->title : $name,
            'version' => isset($xml->version) ? (string) $xml->version : '',
            'author' => isset($xml->author) ? (string) $xml->author : '',
            'license' => isset($xml->license) ? (string) $xml->license : '',
            'description' => isset($xml->description) ? (string) $xml->description : '',
        ];
    }

    /**
     * @throws \RuntimeException
     */
    private function extractZipSafely(string $zipPath, string $dest, string $prefix): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('Failed to reopen ZIP archive.');
        }
        $destReal = rtrim(str_replace('\\', '/', $dest), '/');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = (string) $stat['name'];
            if (self::isUnsafeZipEntry($name)) {
                $zip->close();
                throw new \RuntimeException('Refused unsafe archive entry during extraction.');
            }
            $relative = $name;
            if ($prefix !== '') {
                if (strpos($name, $prefix) !== 0) {
                    continue;
                }
                $relative = substr($name, strlen($prefix));
                if ($relative === '') {
                    continue;
                }
            }
            $target = $destReal . '/' . ltrim($relative, '/');
            $target = str_replace('\\', '/', $target);
            if (strpos($target, $destReal . '/') !== 0 && $target !== $destReal) {
                $zip->close();
                throw new \RuntimeException('Refused path traversal during extraction.');
            }
            if (substr($name, -1) === '/') {
                if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                    $zip->close();
                    throw new \RuntimeException('Failed to create a directory from the archive.');
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                $zip->close();
                throw new \RuntimeException('Failed to create a directory from the archive.');
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                $zip->close();
                throw new \RuntimeException('Failed to read a file from the archive.');
            }
            if (file_put_contents($target, $contents) === false) {
                $zip->close();
                throw new \RuntimeException('Failed to write an extracted file.');
            }
        }
        $zip->close();
    }

    // ------------------------------------------------------------------
    // Static helpers (also exposed for tests)
    // ------------------------------------------------------------------

    public static function isUnsafeZipEntry(string $name): bool
    {
        if ($name === '') {
            return true;
        }
        if (strpos($name, '\\') !== false) {
            return true;
        }
        if (strpos($name, '/') === 0) {
            return true;
        }
        if (preg_match('#^[a-zA-Z]+://#', $name)) {
            return true;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $name)) {
            return true;
        }
        return false;
    }

    public static function isAllowedFilename(string $name): bool
    {
        if ($name === '' || substr($name, -1) === '/') {
            return true;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }
        return in_array($ext, self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Walk an uploaded style's extracted directory and return every file
     * inside it as a list of forward-slash relative paths. The embedded
     * editor's ResourceFetcher consumes this manifest via
     * themeRegistryOverride.uploaded[].files so admin-approved styles can
     * be fetched file-by-file instead of expecting a zip bundle under
     * /bundles/themes/<name>.zip.
     *
     * @return string[]
     */
    public function listUploadedFiles(string $slug): array
    {
        $slug = self::normalizeSlug($slug);
        $dir = $this->getStorageDir() . '/' . $slug;
        if (!is_dir($dir)) {
            return [];
        }
        $baseLen = strlen(rtrim($dir, '/') . '/');
        $out = [];
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $absolute = (string) $fileInfo->getPathname();
                $relative = substr($absolute, $baseLen);
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
                $out[] = $relative;
            }
        } catch (\Exception $e) {
            return [];
        }
        sort($out);
        return $out;
    }

    /**
     * Scan an uploaded style's `icons/` subfolder and return the editor-shape
     * icon map. Mirrors the upstream theme-parser.ts::scanThemeIcons logic so
     * the iDevice icon picker shows icons shipped with admin-uploaded styles
     * (built-in themes get scanned by the editor itself; admin-uploaded ones
     * arrive via themeRegistryOverride and need the icon list pre-built).
     *
     * @return array<string, array{id:string,title:string,type:string,value:string}>
     */
    public function scanUploadedIcons(string $slug, string $styleUrl): array
    {
        $slug = self::normalizeSlug($slug);
        $dir = $this->getStyleDir($slug) . '/icons';
        if (!is_dir($dir)) {
            return [];
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return [];
        }
        $out = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'svg', 'gif', 'jpg', 'jpeg'], true)) {
                continue;
            }
            $iconId = pathinfo($name, PATHINFO_FILENAME);
            $out[$iconId] = [
                'id' => $iconId,
                'title' => $iconId,
                'type' => 'img',
                'value' => rtrim($styleUrl, '/') . '/icons/' . rawurlencode($name),
            ];
        }
        ksort($out);
        return $out;
    }

    public static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
        $slug = trim((string) $slug, '-');
        return $slug === '' ? 'style' : $slug;
    }

    public function allocateUniqueSlug(string $requested): string
    {
        $base = self::normalizeSlug($requested);
        $builtins = array_map(
            static fn($t) => strtolower((string) ($t['name'] ?? '')),
            $this->listBuiltinThemes()
        );
        $registry = $this->getRegistry();
        $existing = array_map('strtolower', array_keys($registry['uploaded']));
        $taken = array_merge($builtins, $existing);
        $slug = $base;
        $i = 2;
        while (in_array(strtolower($slug), $taken, true)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private static function findCssFiles(string $dir): array
    {
        $out = [];
        if (is_file($dir . '/style.css')) {
            $out[] = 'style.css';
        }
        $glob = glob($dir . '/*.css');
        if (is_array($glob)) {
            foreach ($glob as $file) {
                $base = basename($file);
                if (!in_array($base, $out, true)) {
                    $out[] = $base;
                }
            }
        }
        return $out;
    }

    private static function hashZip(string $path): string
    {
        $h = @hash_file('sha256', $path);
        return is_string($h) ? 'sha256:' . $h : '';
    }

    public static function recursiveDelete(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            self::recursiveDelete($dir . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($dir);
    }
}
