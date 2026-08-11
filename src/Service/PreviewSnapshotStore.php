<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use ZipArchive;

/**
 * File-backed store for opaque editor-preview snapshots.
 *
 * The editor sends the WHOLE project as one ZIP on every opaque refresh and gets
 * back an unguessable capability id; the authless serving route then hands that
 * tree out without a session. This replaces the layered protocol-v2 store
 * (immutable asset keys, incremental revisions, fixed installation resources) —
 * machinery that existed only to avoid re-uploading unchanged bytes, for a
 * protocol the editor no longer speaks.
 *
 * PHP is request-scoped and the authless GETs that serve a snapshot carry no
 * session, so the capability must live on disk:
 *
 *   {previewId}/
 *     meta.json    ownerId
 *     access       empty marker; its mtime is the idle-TTL clock
 *     content/     the extracted snapshot
 *
 * Content sits in its own subdirectory so no author path can collide with the
 * store's own files — there are no reserved names to police.
 */
class PreviewSnapshotStore
{
    /** Idle lifetime; a snapshot untouched for this long is reclaimed. */
    public const TTL_SECONDS = 1800;

    /** Capability shape: a UUIDv4 minted by this store. */
    public const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private string $basePath;

    /** @var callable */
    private $now;

    private int $maxFiles;

    private int $maxBytes;

    public function __construct(
        string $basePath,
        ?callable $now = null,
        int $maxFiles = ZipSafety::DEFAULT_MAX_FILES,
        int $maxBytes = ZipSafety::DEFAULT_MAX_TOTAL_BYTES
    ) {
        $this->basePath = rtrim($basePath, '/');
        $this->now = $now ?? static function (): int {
            return time();
        };
        $this->maxFiles = $maxFiles > 0 ? $maxFiles : ZipSafety::DEFAULT_MAX_FILES;
        $this->maxBytes = $maxBytes > 0 ? $maxBytes : ZipSafety::DEFAULT_MAX_TOTAL_BYTES;
    }

    /**
     * Create or atomically replace a snapshot.
     *
     * Everything is built beside the live tree and swapped in at the end, so a
     * reader sees either the previous snapshot or the new one, never a partial
     * extraction, and a failure anywhere leaves the live one untouched.
     *
     * @return array{previewId:string}|array{error:string,status:int}
     */
    public function replace(int $ownerId, string $zipPath, ?string $previewId = null): array
    {
        $this->sweepExpired();

        $replacing = $previewId !== null && $previewId !== '';
        $id = $replacing ? $previewId : $this->generateId();
        if ($replacing) {
            $guard = $this->authorize($id, $ownerId);
            if ($guard !== null) {
                return $guard;
            }
        }

        $staging = $this->basePath . '/.staging-' . bin2hex(random_bytes(12));
        if (!is_dir($staging . '/content')
            && !@mkdir($staging . '/content', 0700, true)
            && !is_dir($staging . '/content')) {
            return ['error' => 'Could not create the preview staging directory.', 'status' => 500];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->removeTree($staging);
            return ['error' => 'Invalid preview archive.', 'status' => 400];
        }
        try {
            // Reuses the module's own archive guard: unsafe and forbidden entries
            // reject the whole archive before anything is written, and the
            // zip-bomb cap is measured on the REAL decompressed bytes rather than
            // the attacker-declared sizes in the central directory.
            ZipSafety::extract($zip, $staging . '/content', $this->maxFiles, $this->maxBytes);
        } catch (\RuntimeException $e) {
            $zip->close();
            $this->removeTree($staging);
            return ['error' => $e->getMessage(), 'status' => 400];
        }
        $zip->close();

        if (!is_file($staging . '/content/index.html')) {
            $this->removeTree($staging);
            return ['error' => 'Preview archive must contain index.html.', 'status' => 400];
        }

        $wrote = @file_put_contents($staging . '/meta.json', json_encode(['ownerId' => $ownerId]));
        if ($wrote === false || !@touch($staging . '/access', ($this->now)())) {
            $this->removeTree($staging);
            return ['error' => 'Could not write the preview metadata.', 'status' => 500];
        }

        $target = $this->basePath . '/' . $id;
        $backup = $target . '.old-' . bin2hex(random_bytes(6));
        if (is_dir($target) && !@rename($target, $backup)) {
            $this->removeTree($staging);
            return ['error' => 'Could not replace the preview snapshot.', 'status' => 500];
        }
        if (!@rename($staging, $target)) {
            if (is_dir($backup)) {
                @rename($backup, $target);
            }
            $this->removeTree($staging);
            return ['error' => 'Could not publish the preview snapshot.', 'status' => 500];
        }
        if (is_dir($backup)) {
            $this->removeTree($backup);
        }

        return ['previewId' => $id];
    }

    /**
     * Traversal-safe normalization for the exact-key layer lookups. Decodes one
     * percent-encoding layer, strips NUL bytes, normalizes slashes, drops empty
     * and "." segments, rejects any "..", and defaults to index.html.
     *
     * A double-encoded traversal (`%252e%252e%252f…`) survives a single decode
     * as a literal `%2e%2e` segment — not a `..` — so it is never a traversal:
     * it simply names a non-existent exact key and 404s. The lookups are exact
     * map / file-in-`documents` reads, never path arithmetic from client input,
     * so a surviving literal segment can only miss.
     *
     * @param string $path
     * @return string|null Normalized key, or null if it tries to escape.
     */
    public static function normalizePath(string $path): ?string
    {
        $path = urldecode($path);
        $path = str_replace(["\0", '\\'], ['', '/'], $path);

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $parts[] = $segment;
        }

        return $parts === [] ? 'index.html' : implode('/', $parts);
    }

    /**
     * Resolve a snapshot's content directory for an authless serving request and
     * refresh its idle clock, so a preview in use never expires under the author.
     */
    public function contentDir(string $previewId): ?string
    {
        if (!preg_match(self::UUID_RE, $previewId)) {
            return null;
        }
        $dir = $this->basePath . '/' . $previewId;
        $touched = @filemtime($dir . '/access');
        if ($touched === false || ($this->now)() - $touched > self::TTL_SECONDS) {
            return null;
        }
        @touch($dir . '/access', ($this->now)());
        return is_dir($dir . '/content') ? $dir . '/content' : null;
    }

    /**
     * The authorization verdict for a capability this user is claiming.
     *
     * Both management verbs run through here, so publish and delete can never
     * disagree about what owner scoping means: a malformed id is a 400, one
     * nobody holds a 404 and somebody else's a 403. Keeping the rule in the
     * store — rather than letting each caller reassemble it — is what makes that
     * guarantee structural instead of a convention two actions must remember.
     *
     * @param string $previewId
     * @param int $ownerId
     * @return array{error:string,status:int}|null Null when the caller may proceed.
     */
    public function authorize(string $previewId, int $ownerId): ?array
    {
        if (!preg_match(self::UUID_RE, $previewId)) {
            return ['error' => 'Invalid preview capability.', 'status' => 400];
        }
        $meta = $this->readMeta($previewId);
        if ($meta === null) {
            return ['error' => 'Preview snapshot not found.', 'status' => 404];
        }
        if ((int) ($meta['ownerId'] ?? -1) !== $ownerId) {
            return ['error' => 'Preview snapshot belongs to another user.', 'status' => 403];
        }
        return null;
    }

    /**
     * Delete a snapshot after {@see authorize()} has cleared the caller.
     *
     * @param string $previewId
     * @param int $ownerId
     * @return array{error:string,status:int}|null Null once it is gone.
     */
    public function deleteOwned(string $previewId, int $ownerId): ?array
    {
        $guard = $this->authorize($previewId, $ownerId);
        if ($guard !== null) {
            return $guard;
        }
        $this->removeTree($this->basePath . '/' . $previewId);
        return null;
    }

    /**
     * Reclaim snapshots whose idle lifetime has elapsed. Runs on every replace,
     * so the store never depends on a scheduled job to bound its size.
     */
    public function sweepExpired(): int
    {
        if (!is_dir($this->basePath)) {
            return 0;
        }
        $count = 0;
        foreach ((array) @scandir($this->basePath) as $entry) {
            if (!is_string($entry) || !preg_match(self::UUID_RE, $entry)) {
                continue;
            }
            $touched = @filemtime($this->basePath . '/' . $entry . '/access');
            if ($touched === false || ($this->now)() - $touched > self::TTL_SECONDS) {
                $this->removeTree($this->basePath . '/' . $entry);
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return array{ownerId:int}|null
     */
    private function readMeta(string $previewId): ?array
    {
        $path = $this->basePath . '/' . $previewId . '/meta.json';
        $raw = is_readable($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return (is_array($decoded) && isset($decoded['ownerId'])) ? $decoded : null;
    }

    private function generateId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
