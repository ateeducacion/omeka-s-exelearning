<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use ZipArchive;

/**
 * Safe ZIP extraction shared by the .elpx and static-editor pipelines.
 *
 * Replaces blind ZipArchive::extractTo() — which is not a guaranteed security
 * boundary across libzip builds — with an entry-by-entry extractor that:
 *  - rejects zip-slip entries (traversal, absolute, backslash, stream-wrapper),
 *  - confines every write inside the destination directory, and
 *  - caps the entry count and the *actual* decompressed byte count to defuse
 *    zip bombs (the cap is enforced on bytes written, not the central
 *    directory's declared sizes, which an attacker controls).
 */
final class ZipSafety
{
    /** Maximum number of entries permitted in one archive. */
    public const DEFAULT_MAX_FILES = 50000;

    /** Maximum total uncompressed bytes permitted (1 GiB). */
    public const DEFAULT_MAX_TOTAL_BYTES = 1073741824;

    /** Read chunk size while streaming entries out of the archive. */
    private const CHUNK = 8192;

    /**
     * Whether a ZIP entry name is unsafe to extract.
     */
    public static function isUnsafeEntry(string $name): bool
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
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $name)) {
            return true;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $name)) {
            return true;
        }
        return false;
    }

    /**
     * Whether an archive entry is forbidden even if its path is otherwise safe.
     *
     * Server configuration files and PHP-capable extensions must never be
     * extracted from user-controlled archives because they can become executable
     * on some Apache/PHP deployments when direct access is possible. This is a
     * defense-in-depth deny-list on top of isUnsafeEntry(); a single forbidden
     * entry rejects the whole archive.
     */
    public static function isForbiddenEntry(string $name): bool
    {
        // Normalize separators and reduce to the basename for comparison.
        $normalized = str_replace('\\', '/', $name);
        $slash = strrpos($normalized, '/');
        $basename = $slash === false ? $normalized : substr($normalized, $slash + 1);
        $lower = strtolower($basename);

        // Server-configuration files that must never be extracted.
        $forbiddenBasenames = [
            '.htaccess',
            '.htpasswd',
            '.user.ini',
            'php.ini',
            'web.config',
        ];
        if (in_array($lower, $forbiddenBasenames, true)) {
            return true;
        }

        // Server-executable extensions that must never be extracted.
        $forbiddenExtensions = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
            'shtml', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'jspx',
        ];
        $dot = strrpos($lower, '.');
        if ($dot !== false) {
            $extension = substr($lower, $dot + 1);
            if (in_array($extension, $forbiddenExtensions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Open a ZIP file and extract it safely into $destDir.
     *
     * @throws \RuntimeException on an unreadable archive or any unsafe entry.
     */
    public static function extractFile(
        string $zipPath,
        string $destDir,
        int $maxFiles = self::DEFAULT_MAX_FILES,
        int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES
    ): void {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open the archive for extraction.');
        }
        try {
            self::extract($zip, $destDir, $maxFiles, $maxTotalBytes);
        } finally {
            $zip->close();
        }
    }

    /**
     * Extract an already-open archive safely into $destDir.
     *
     * @throws \RuntimeException
     */
    public static function extract(
        ZipArchive $zip,
        string $destDir,
        int $maxFiles = self::DEFAULT_MAX_FILES,
        int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES
    ): void {
        if ($zip->numFiles > $maxFiles) {
            throw new \RuntimeException('Archive contains too many entries.');
        }

        $destReal = rtrim(str_replace('\\', '/', $destDir), '/');
        if (!is_dir($destReal) && !@mkdir($destReal, 0755, true) && !is_dir($destReal)) {
            throw new \RuntimeException('Could not create the extraction directory.');
        }

        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new \RuntimeException('Archive contains an unreadable entry.');
            }
            $name = (string) $stat['name'];
            if (self::isUnsafeEntry($name)) {
                throw new \RuntimeException('Refused unsafe archive entry: ' . $name);
            }
            if (self::isForbiddenEntry($name)) {
                throw new \RuntimeException('Refused forbidden archive entry: ' . $name);
            }

            $target = $destReal . '/' . ltrim(str_replace('\\', '/', $name), '/');
            if ($target !== $destReal && strpos($target, $destReal . '/') !== 0) {
                throw new \RuntimeException('Refused path traversal in archive entry: ' . $name);
            }

            if (substr($name, -1) === '/') {
                self::ensureDir($target);
                continue;
            }

            self::ensureDir(dirname($target));
            $totalBytes = self::writeEntry($zip, $name, $target, $totalBytes, $maxTotalBytes);
        }
    }

    /**
     * Stream one entry out of the archive, enforcing the byte cap on the data
     * actually written.
     *
     * @throws \RuntimeException
     */
    private static function writeEntry(
        ZipArchive $zip,
        string $name,
        string $target,
        int $totalBytes,
        int $maxTotalBytes
    ): int {
        $in = $zip->getStream($name);
        if ($in === false) {
            throw new \RuntimeException('Could not read an archive entry.');
        }
        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('Could not write an extracted file.');
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) {
                    break;
                }
                $totalBytes += strlen($chunk);
                if ($totalBytes > $maxTotalBytes) {
                    throw new \RuntimeException('Archive exceeds the maximum allowed uncompressed size.');
                }
                if ($chunk !== '' && fwrite($out, $chunk) === false) {
                    throw new \RuntimeException('Could not write an extracted file.');
                }
            }
        } catch (\RuntimeException $e) {
            fclose($in);
            fclose($out);
            @unlink($target);
            throw $e;
        }
        fclose($in);
        fclose($out);
        return $totalBytes;
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create a directory from the archive.');
        }
    }
}
