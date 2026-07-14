<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use ZipArchive;

/** Stores complete, expiring editor-preview snapshots outside the web root. */
class PreviewSnapshotStore
{
    private const MAX_FILES = 5000;
    private const MAX_BYTES = 104857600;
    private const DEFAULT_TTL_SECONDS = 1800;

    /** @var string */
    private $root;
    /** @var int */
    private $ttlSeconds;
    /** @var callable */
    private $clock;

    public function __construct(
        ?string $root = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?callable $clock = null
    ) {
        $this->root = $root ?: sys_get_temp_dir() . '/omeka-exelearning-preview';
        $this->ttlSeconds = $ttlSeconds;
        $this->clock = $clock ?: 'time';
    }

    /** Create or atomically replace a complete preview snapshot. */
    public function replace(int $ownerId, int $mediaId, string $zipPath, ?string $previewId = null): string
    {
        $this->sweepExpired();
        $id = $previewId ?: $this->uuid();
        if (!$this->validId($id)) {
            throw new \InvalidArgumentException('Invalid preview capability.');
        }
        $metadata = $this->metadata($id);
        if ($previewId !== null && $metadata === null) {
            throw new \RuntimeException('Preview snapshot not found.');
        }
        if ($metadata !== null
            && ($metadata['ownerId'] !== $ownerId || $metadata['mediaId'] !== $mediaId)
        ) {
            throw new \UnexpectedValueException('Preview snapshot belongs to another media item.');
        }

        $this->ensureDirectory($this->root);
        $staging = $this->root . '/.staging-' . bin2hex(random_bytes(12));
        $this->ensureDirectory($staging);
        try {
            $this->extract($zipPath, $staging);
            $json = json_encode(['ownerId' => $ownerId, 'mediaId' => $mediaId]);
            if ($json === false
                || file_put_contents($staging . '/.metadata.json', $json) === false
                || !touch($staging . '/.accessed', call_user_func($this->clock))
            ) {
                throw new \RuntimeException('Cannot write preview metadata.');
            }

            $target = $this->root . '/' . $id;
            $backup = $target . '.old-' . bin2hex(random_bytes(6));
            if (is_dir($target) && !rename($target, $backup)) {
                throw new \RuntimeException('Cannot replace preview snapshot.');
            }
            if (!rename($staging, $target)) {
                if (is_dir($backup)) {
                    rename($backup, $target);
                }
                throw new \RuntimeException('Cannot publish preview snapshot.');
            }
            $this->removeTree($backup);
        } catch (\Throwable $error) {
            $this->removeTree($staging);
            throw $error;
        }
        return $id;
    }

    /** @return array{bytes:string,mime:string}|null */
    public function get(string $previewId, string $path): ?array
    {
        $this->sweepExpired();
        if (!$this->validId($previewId) || $this->metadata($previewId) === null) {
            return null;
        }
        $decoded = rawurldecode($path);
        if (!$this->safePath($decoded) || $this->reservedPath($decoded)) {
            return null;
        }
        $root = realpath($this->root . '/' . $previewId);
        $file = realpath($this->root . '/' . $previewId . '/' . $decoded);
        if ($root === false
            || $file === false
            || !is_file($file)
            || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0
        ) {
            return null;
        }
        $bytes = file_get_contents($file);
        if ($bytes === false) {
            return null;
        }
        touch($root . '/.accessed', call_user_func($this->clock));
        return ['bytes' => $bytes, 'mime' => $this->mimeFor($decoded)];
    }

    /** Delete a preview after validating its owner and media scope. */
    public function delete(int $ownerId, int $mediaId, string $previewId): bool
    {
        $metadata = $this->metadata($previewId);
        if ($metadata === null) {
            return false;
        }
        if ($metadata['ownerId'] !== $ownerId || $metadata['mediaId'] !== $mediaId) {
            throw new \UnexpectedValueException('Preview snapshot belongs to another media item.');
        }
        $this->removeTree($this->root . '/' . $previewId);
        return true;
    }

    /** Remove capabilities whose idle TTL has elapsed. */
    public function sweepExpired(): int
    {
        if (!is_dir($this->root)) {
            return 0;
        }
        $count = 0;
        foreach (scandir($this->root) ?: [] as $id) {
            if (!$this->validId($id)) {
                continue;
            }
            $accessed = @filemtime($this->root . '/' . $id . '/.accessed');
            if ($accessed === false || call_user_func($this->clock) - $accessed > $this->ttlSeconds) {
                $this->removeTree($this->root . '/' . $id);
                ++$count;
            }
        }
        return $count;
    }

    /** Validate and extract one complete preview archive. */
    private function extract(string $zipPath, string $target): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException('Invalid preview ZIP.');
        }
        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new \LengthException('Preview ZIP contains too many files.');
            }
            $total = 0;
            $hasIndex = false;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                if (!is_string($name) || !is_array($stat)) {
                    throw new \InvalidArgumentException('Invalid preview ZIP entry.');
                }
                $directory = substr($name, -1) === '/';
                $validated = $directory ? rtrim($name, '/') : $name;
                if (!$this->safePath($validated)) {
                    throw new \InvalidArgumentException('Unsafe preview ZIP path.');
                }
                if ($directory) {
                    continue;
                }
                if ($this->reservedPath($name)) {
                    throw new \InvalidArgumentException('Reserved preview ZIP path.');
                }
                $operatingSystem = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                    && $operatingSystem === ZipArchive::OPSYS_UNIX
                    && (($attributes >> 16) & 0xf000) === 0xa000
                ) {
                    throw new \InvalidArgumentException('Preview ZIP contains a symbolic link.');
                }
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_BYTES) {
                    throw new \LengthException('Preview ZIP is too large.');
                }
                $hasIndex = $hasIndex || $name === 'index.html';
            }
            if (!$hasIndex || !$zip->extractTo($target)) {
                throw new \InvalidArgumentException('Preview ZIP must contain index.html.');
            }
        } finally {
            $zip->close();
        }
    }

    /** @return array{ownerId:int,mediaId:int}|null */
    private function metadata(string $id): ?array
    {
        if (!$this->validId($id)) {
            return null;
        }
        $json = @file_get_contents($this->root . '/' . $id . '/.metadata.json');
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['ownerId'], $data['mediaId'])) {
            return null;
        }
        return ['ownerId' => (int) $data['ownerId'], 'mediaId' => (int) $data['mediaId']];
    }

    private function safePath(string $path): bool
    {
        if ($path === ''
            || strpos($path, "\0") !== false
            || $path[0] === '/'
            || $path[0] === '\\'
            || strpos($path, '\\') !== false
        ) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }

    private function reservedPath(string $path): bool
    {
        return $path === '.metadata.json' || $path === '.accessed';
    }

    private function validId(string $id): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        ) === 1;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    private function mimeFor(string $path): string
    {
        $types = [
            'html' => 'text/html; charset=utf-8',
            'htm' => 'text/html; charset=utf-8',
            'xhtml' => 'application/xhtml+xml',
            'xml' => 'application/xml',
            'svg' => 'image/svg+xml',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'mjs' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'audio/ogg',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=utf-8',
        ];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $types[$extension] ?? 'application/octet-stream';
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create preview directory.');
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeTree($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
