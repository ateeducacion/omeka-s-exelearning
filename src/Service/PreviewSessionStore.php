<?php
declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * File-backed preview session store (serving contract v2 — Omeka S adapter).
 *
 * PHP is request-scoped, so an in-memory session map (as in the eXe core
 * reference src/services/preview-session-manager.ts) cannot survive between the
 * management request that publishes a revision and the authless serving request
 * that reads it. This store persists the three-layer model under the Omeka file
 * store, mirroring the reference semantics exactly:
 *
 * - `assets/` — author media, immutable per `assetKey` for the session
 *   lifetime; the key is an opaque validated token (asset id + content-hash
 *   prefix) the project model already stores, never hashed by the server;
 * - `rev/{n}/documents/` — the generated-document layer for revision n, a full
 *   self-contained snapshot so a reader of revision n never mixes revisions;
 * - `rev/{n}/revision.json` — that revision's FULL `assetRefs` / `fixedRefs`
 *   maps (served path → assetKey / fixedResourceId);
 * - `current` — the active-revision pointer, swapped atomically (rename) after a
 *   revision is fully materialized, so a concurrent GET observes revision N or
 *   N+1, never a mixture.
 *
 * Fixed resources (layer 1) are never stored here — they resolve through
 * {@see PreviewFixedResources} against the installed editor distribution.
 *
 * Sessions are ephemeral: an idle TTL sweeper (opportunistic, on access)
 * reclaims them, and per-user / per-session / global byte and file budgets bound
 * DoS. All of applyRevision runs under an exclusive file lock, so the
 * validate → materialize → pointer-swap critical section cannot interleave with
 * a racing publish on the same session.
 */
final class PreviewSessionStore
{
    /**
     * `assetKey` wire format: `{assetId}@{contentHashPrefix}` — a 36-char
     * UUID-like id plus 8–64 hex chars of the content hash. The server validates
     * the shape and treats the key as opaque; it never hashes the bytes.
     */
    public const ASSET_KEY_RE = '/^[0-9a-fA-F-]{36}@[0-9a-f]{8,64}$/';

    /** A capability id is a canonical v4-shaped UUID; anything else is a miss. */
    public const PREVIEW_ID_RE =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Apache deny guard written into the session base directory. Covers both
     * Apache 2.4 (mod_authz_core) and 2.2 syntaxes — the same upload-protection
     * pattern the framework uses for its own files/ tree.
     */
    private const DENY_HTACCESS =
        "<IfModule mod_authz_core.c>\n"
        . "Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "Deny from all\n"
        . "</IfModule>\n";

    /** Reference default budgets (mirrors DEFAULT_PREVIEW_SESSION_LIMITS). */
    private const DEFAULT_LIMITS = [
        'ttlSeconds' => 1800,
        'maxSessionsPerUser' => 4,
        'maxFilesPerSession' => 5000,
        'maxBytesPerSession' => 209715200,   // 200 MiB
        'maxAssetBytes' => 134217728,        // 128 MiB
        'globalMaxBytes' => 2147483648,      // 2 GiB
        'sweepIntervalSeconds' => 60,
        'recommendedBatchBytes' => 67108864, // 64 MiB
    ];

    /** Root directory holding one subdirectory per preview session. */
    private string $basePath;

    private PreviewFixedResources $fixed;

    /** @var array<string, int> Effective budgets (defaults overlaid by ctor). */
    private array $limits;

    /** @var callable():int Injectable clock (seconds), for TTL tests. */
    private $now;

    /**
     * @param string                 $basePath Root for per-session directories.
     * @param PreviewFixedResources  $fixed    Layer-1 resolver.
     * @param array<string, int>     $limits   Overrides for DEFAULT_LIMITS.
     * @param callable|null          $now      Clock returning UNIX seconds.
     */
    public function __construct(
        string $basePath,
        PreviewFixedResources $fixed,
        array $limits = [],
        ?callable $now = null
    ) {
        $this->basePath = rtrim($basePath, '/');
        $this->fixed = $fixed;
        $this->limits = array_merge(self::DEFAULT_LIMITS, $limits);
        $this->now = $now ?? static function (): int {
            return time();
        };
    }

    /**
     * Bytes an owned session may still accept (maxBytesPerSession minus what it
     * already holds), or null when the session is unknown or not owned by
     * `$ownerId`. The management API uses it to reject an oversized upload on its
     * DECLARED sizes BEFORE buffering any part into memory (the contract's
     * declared-then-actual two-stage budget).
     *
     * @param string $previewId
     * @param int    $ownerId
     * @return int|null
     */
    public function remainingSessionBudget(string $previewId, int $ownerId): ?int
    {
        $meta = $this->ownedMeta($previewId, $ownerId);
        if (isset($meta['status'])) {
            return null;
        }
        return $this->limits['maxBytesPerSession'] - $this->sessionBytes($this->sessionDir($previewId));
    }

    /** The limits a create-session response advertises to the client. */
    public function advertisedLimits(): array
    {
        return [
            'maxFilesPerSession' => $this->limits['maxFilesPerSession'],
            'maxBytesPerSession' => $this->limits['maxBytesPerSession'],
            'maxAssetBytes' => $this->limits['maxAssetBytes'],
            'recommendedBatchBytes' => $this->limits['recommendedBatchBytes'],
        ];
    }

    // =========================================================================
    // Management API surface (authenticated, owner-scoped)
    // =========================================================================

    /**
     * Create a session for an owner. Enforces the per-user session cap by
     * LRU-evicting the owner's oldest session first.
     *
     * @param int $ownerId
     * @return array{previewId: string, limits: array}
     */
    public function createSession(int $ownerId): array
    {
        $this->ensureBase();
        $this->maybeSweep();

        $owned = $this->sessionsOwnedBy($ownerId);
        while (count($owned) >= $this->limits['maxSessionsPerUser']) {
            $lru = $this->lruOf($owned);
            if ($lru === null) {
                break;
            }
            $this->deleteDir($lru['dir']);
            $owned = $this->sessionsOwnedBy($ownerId);
        }

        $previewId = $this->uuid4();
        $dir = $this->sessionDir($previewId);
        @mkdir($dir . '/assets', 0700, true);
        $this->writeJsonAtomic($dir . '/session.json', [
            'owner' => $ownerId,
            'assetBytes' => 0,
            'createdAt' => ($this->now)(),
        ]);
        $this->touch($dir);

        return ['previewId' => $previewId, 'limits' => $this->advertisedLimits()];
    }

    /**
     * Store uploaded assets. Keys are immutable: an existing key is reported in
     * `alreadyStored` and its bytes are NOT replaced (a replaced author file
     * gets a new key because its content hash changed). Per-entry failures land
     * in `rejected` with a reason.
     *
     * @param string $previewId
     * @param int    $ownerId
     * @param array<int, array{key: string, size: int, bytes: string}> $entries
     * @return array{status?: int, stored?: string[], alreadyStored?: string[], rejected?: array}
     */
    public function storeAssets(string $previewId, int $ownerId, array $entries): array
    {
        $meta = $this->ownedMeta($previewId, $ownerId);
        if (isset($meta['status'])) {
            return $meta;
        }
        $dir = $this->sessionDir($previewId);
        $this->touch($dir);

        $stored = [];
        $alreadyStored = [];
        $rejected = [];
        $assetBytes = (int) $meta['assetBytes'];
        $documentBytes = $this->activeDocumentBytes($dir);

        foreach ($entries as $entry) {
            $key = (string) ($entry['key'] ?? '');
            $bytes = (string) ($entry['bytes'] ?? '');
            $length = strlen($bytes);

            if (!preg_match(self::ASSET_KEY_RE, $key)) {
                $rejected[] = ['key' => $key, 'reason' => 'invalid-key'];
                continue;
            }
            if ($this->assetExists($dir, $key)) {
                $alreadyStored[] = $key;
                continue;
            }
            if ((int) ($entry['size'] ?? -1) !== $length) {
                $rejected[] = ['key' => $key, 'reason' => 'size-mismatch'];
                continue;
            }
            if ($length > $this->limits['maxAssetBytes']) {
                $rejected[] = ['key' => $key, 'reason' => 'asset-too-large'];
                continue;
            }
            if ($documentBytes + $assetBytes + $length > $this->limits['maxBytesPerSession']) {
                $rejected[] = ['key' => $key, 'reason' => 'session-budget-exceeded'];
                continue;
            }
            if (!$this->evictOthersForGlobal($previewId, $length)) {
                $rejected[] = ['key' => $key, 'reason' => 'global-budget-exceeded'];
                continue;
            }

            $this->writeAsset($dir, $key, $bytes);
            $assetBytes += $length;
            $stored[] = $key;
        }

        $meta['assetBytes'] = $assetBytes;
        $this->writeJsonAtomic($dir . '/session.json', $meta);

        return ['stored' => $stored, 'alreadyStored' => $alreadyStored, 'rejected' => $rejected];
    }

    /**
     * Publish a revision. Validation order per the contract: revision ordering
     * (409) → path normalization (400) → asset existence (422) → fixed-resource
     * existence (422) → file-count / byte budgets (413). The whole method runs
     * under an exclusive lock; documents materialize into a fresh `rev/{n}`
     * directory and the `current` pointer is swapped atomically, so a concurrent
     * GET sees revision N or N+1 in full, never a mixture.
     *
     * @param string $previewId
     * @param int    $ownerId
     * @param array  $meta      Decoded `revision` field (baseRevision,
     *                          nextRevision, writes[], deletes[], assetRefs{},
     *                          fixedRefs{}).
     * @param array<int, string> $writeBytes Byte payloads index-aligned with
     *                          `meta['writes']`.
     * @return array
     */
    public function applyRevision(string $previewId, int $ownerId, array $meta, array $writeBytes): array
    {
        $owned = $this->ownedMeta($previewId, $ownerId);
        if (isset($owned['status'])) {
            return $owned;
        }
        $dir = $this->sessionDir($previewId);

        $lock = $this->lock($dir);
        try {
            return $this->publishRevisionLocked($dir, $owned, $meta, $writeBytes);
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * Delete a session and its capability URL.
     *
     * @param string $previewId
     * @param int    $ownerId
     * @return array{status?: int, success?: bool}
     */
    public function deleteSession(string $previewId, int $ownerId): array
    {
        $meta = $this->ownedMeta($previewId, $ownerId);
        if (isset($meta['status'])) {
            return $meta;
        }
        $this->deleteDir($this->sessionDir($previewId));
        return ['success' => true];
    }

    // =========================================================================
    // Serving surface (authless capability URL)
    // =========================================================================

    /**
     * Resolve a normalized served path against the active revision: documents →
     * assetRefs→assets → fixedRefs→manifest → null. Touches the idle-TTL clock
     * and opportunistically sweeps. Returns null for an unknown/expired session,
     * a session without a published revision, or a path that resolves to
     * nothing.
     *
     * @param string $previewId Capability id (validated here again, defensively).
     * @param string $path      A path already run through {@see normalizePath}.
     * @return array{kind: string, bytes: string, etag?: string}|null
     */
    public function resolveForServing(string $previewId, string $path): ?array
    {
        if (!preg_match(self::PREVIEW_ID_RE, $previewId)) {
            return null;
        }
        $dir = $this->sessionDir($previewId);
        if (!is_dir($dir)) {
            return null;
        }
        if ($this->isExpired($dir)) {
            $this->deleteDir($dir);
            return null;
        }
        $this->touch($dir);
        $this->maybeSweep();

        $revision = $this->activeRevision($dir);
        if ($revision === 0) {
            return null;
        }
        $revMeta = $this->readJson($dir . '/rev/' . $revision . '/revision.json');
        if ($revMeta === null) {
            return null;
        }

        // 1. Generated document.
        $docFile = $dir . '/rev/' . $revision . '/documents/' . $path;
        if (is_file($docFile)) {
            $bytes = @file_get_contents($docFile);
            if ($bytes !== false) {
                return ['kind' => 'document', 'bytes' => $bytes];
            }
        }

        // 2. Session project asset (immutable per assetKey).
        $assetRefs = is_array($revMeta['assetRefs'] ?? null) ? $revMeta['assetRefs'] : [];
        if (isset($assetRefs[$path])) {
            $key = (string) $assetRefs[$path];
            if ($this->assetExists($dir, $key)) {
                $bytes = @file_get_contents($this->assetFile($dir, $key));
                if ($bytes !== false) {
                    return ['kind' => 'asset', 'bytes' => $bytes, 'etag' => $key];
                }
            }
        }

        // 3. Fixed installation resource (manifest-gated).
        $fixedRefs = is_array($revMeta['fixedRefs'] ?? null) ? $revMeta['fixedRefs'] : [];
        if (isset($fixedRefs[$path])) {
            $bytes = $this->fixed->getResource((string) $fixedRefs[$path]);
            if ($bytes !== null) {
                return ['kind' => 'fixed', 'bytes' => $bytes];
            }
        }

        return null;
    }

    // =========================================================================
    // Path normalization (shared with PreviewController)
    // =========================================================================

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

    // =========================================================================
    // Revision publication (locked critical section)
    // =========================================================================

    /**
     * @param string $dir
     * @param array  $sessionMeta
     * @param array  $meta
     * @param array<int, string> $writeBytes
     * @return array
     */
    private function publishRevisionLocked(string $dir, array $sessionMeta, array $meta, array $writeBytes): array
    {
        $active = $this->activeRevision($dir);

        // 1. Revision ordering.
        $base = $meta['baseRevision'] ?? null;
        $next = $meta['nextRevision'] ?? null;
        if ($base !== $active || $next !== $active + 1) {
            return ['status' => 409, 'reason' => 'revision-conflict', 'currentRevision' => $active];
        }

        // 2. Normalize every client-supplied path.
        $writePaths = $meta['writes'] ?? [];
        if (!is_array($writePaths) || count($writePaths) !== count($writeBytes)) {
            return ['status' => 400, 'message' => 'writes and files must be index-aligned'];
        }
        $writes = [];
        foreach (array_values($writePaths) as $i => $rawPath) {
            $normalized = self::normalizePath((string) $rawPath);
            if ($normalized === null) {
                return ['status' => 400, 'message' => 'Unsafe path in writes: ' . $rawPath];
            }
            $writes[$normalized] = (string) $writeBytes[$i];
        }
        $deletes = [];
        foreach (($meta['deletes'] ?? []) as $rawPath) {
            $normalized = self::normalizePath((string) $rawPath);
            if ($normalized === null) {
                return ['status' => 400, 'message' => 'Unsafe path in deletes: ' . $rawPath];
            }
            $deletes[] = $normalized;
        }
        $assetRefs = $this->normalizeRefMap($meta['assetRefs'] ?? []);
        if ($assetRefs === null) {
            return ['status' => 400, 'message' => 'Unsafe path in assetRefs'];
        }
        $fixedRefs = $this->normalizeRefMap($meta['fixedRefs'] ?? []);
        if ($fixedRefs === null) {
            return ['status' => 400, 'message' => 'Unsafe path in fixedRefs'];
        }

        // 3. Every referenced asset must exist in the session store.
        $missing = [];
        foreach (array_unique(array_values($assetRefs)) as $key) {
            if (!$this->assetExists($dir, (string) $key)) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            return ['status' => 422, 'reason' => 'missing-assets', 'missing' => array_values($missing)];
        }

        // 4. Every referenced fixed resource must be manifest-listed.
        $unknown = [];
        foreach (array_unique(array_values($fixedRefs)) as $id) {
            if (!$this->fixed->hasResource((string) $id)) {
                $unknown[] = $id;
            }
        }
        if ($unknown !== []) {
            return ['status' => 422, 'reason' => 'unknown-fixed-resources', 'resources' => array_values($unknown)];
        }

        // 5. Budgets, computed on the post-delta document set.
        $prevDocs = $active > 0 ? $this->listDocuments($dir . '/rev/' . $active . '/documents') : [];
        $prevDocBytes = array_sum($prevDocs);
        $newDocs = $prevDocs;
        foreach ($deletes as $del) {
            unset($newDocs[$del]);
        }
        foreach ($writes as $writePath => $bytes) {
            $newDocs[$writePath] = strlen($bytes);
        }
        $documentBytes = array_sum($newDocs);
        $documentCount = count($newDocs);
        // Re-read the asset byte counter under the lock: a concurrent asset
        // upload (which does not take this lock) may have advanced it since the
        // pre-lock ownership check.
        $freshMeta = $this->readJson($dir . '/session.json');
        $assetBytes = (int) (($freshMeta ?? $sessionMeta)['assetBytes'] ?? 0);

        $fileCount = $documentCount + count($assetRefs) + count($fixedRefs);
        if ($fileCount > $this->limits['maxFilesPerSession']) {
            return ['status' => 413, 'message' => 'Too many files (max ' . $this->limits['maxFilesPerSession'] . ')'];
        }
        if ($documentBytes + $assetBytes > $this->limits['maxBytesPerSession']) {
            return ['status' => 413, 'message' => 'Session over byte budget'];
        }
        $delta = $documentBytes - $prevDocBytes;
        if ($delta > 0 && !$this->evictOthersForGlobal($this->sessionIdOf($dir), $delta)) {
            return ['status' => 413, 'message' => 'Preview storage budget exceeded'];
        }

        // 6. Materialize rev/{next} as a full self-contained snapshot, then swap.
        $this->materializeRevision($dir, $active, $next, $newDocs, $writes);
        $this->writeJsonAtomic($dir . '/rev/' . $next . '/revision.json', [
            'revision' => $next,
            'assetRefs' => $assetRefs,
            'fixedRefs' => $fixedRefs,
            'documentBytes' => $documentBytes,
            'documentCount' => $documentCount,
        ]);
        $this->swapPointer($dir, $next);
        $this->pruneOldRevisions($dir, $next);
        $this->touch($dir);

        return ['revision' => $next, 'active' => true];
    }

    /**
     * Build `rev/{next}/documents` from the previous revision's documents (kept
     * files hardlinked, or copied if linking is unavailable) plus this
     * revision's writes. Deletes are simply absent from `$newDocs`.
     *
     * @param string $dir
     * @param int    $active
     * @param int    $next
     * @param array<string, int>    $newDocs Full post-delta path → size set.
     * @param array<string, string> $writes  Written path → bytes.
     */
    private function materializeRevision(string $dir, int $active, int $next, array $newDocs, array $writes): void
    {
        $dstDocs = $dir . '/rev/' . $next . '/documents';
        @mkdir($dstDocs, 0700, true);
        $srcDocs = $dir . '/rev/' . $active . '/documents';

        foreach (array_keys($newDocs) as $path) {
            $dstFile = $dstDocs . '/' . $path;
            $parent = dirname($dstFile);
            if (!is_dir($parent)) {
                @mkdir($parent, 0700, true);
            }
            if (isset($writes[$path])) {
                file_put_contents($dstFile, $writes[$path]);
                continue;
            }
            $srcFile = $srcDocs . '/' . $path;
            if (!@link($srcFile, $dstFile)) {
                @copy($srcFile, $dstFile); // @codeCoverageIgnore
            }
        }
    }

    // =========================================================================
    // Ownership / metadata
    // =========================================================================

    /**
     * Load the session metadata for the owner, or a {status} marker: 404 when
     * the session is unknown/expired, 403 when it belongs to another user.
     *
     * @param string $previewId
     * @param int    $ownerId
     * @return array
     */
    private function ownedMeta(string $previewId, int $ownerId): array
    {
        if (!preg_match(self::PREVIEW_ID_RE, $previewId)) {
            return ['status' => 404];
        }
        $dir = $this->sessionDir($previewId);
        if (!is_dir($dir) || $this->isExpired($dir)) {
            if (is_dir($dir)) {
                $this->deleteDir($dir);
            }
            return ['status' => 404];
        }
        $meta = $this->readJson($dir . '/session.json');
        if ($meta === null) {
            return ['status' => 404];
        }
        if ((int) ($meta['owner'] ?? -1) !== $ownerId) {
            return ['status' => 403];
        }
        return $meta;
    }

    // =========================================================================
    // Byte accounting, budgets, sweeping
    // =========================================================================

    /** Bytes a session currently holds (active documents + session assets). */
    private function sessionBytes(string $dir): int
    {
        $meta = $this->readJson($dir . '/session.json');
        $assetBytes = $meta === null ? 0 : (int) ($meta['assetBytes'] ?? 0);
        return $assetBytes + $this->activeDocumentBytes($dir);
    }

    /** Document bytes of the active revision (0 when none is published). */
    private function activeDocumentBytes(string $dir): int
    {
        $revision = $this->activeRevision($dir);
        if ($revision === 0) {
            return 0;
        }
        $revMeta = $this->readJson($dir . '/rev/' . $revision . '/revision.json');
        return $revMeta === null ? 0 : (int) ($revMeta['documentBytes'] ?? 0);
    }

    /**
     * Evict other sessions (never `$currentId`) in LRU order until
     * `$incomingBytes` fits the global budget. Returns false when it cannot fit
     * even after evicting every other session.
     */
    private function evictOthersForGlobal(string $currentId, int $incomingBytes): bool
    {
        $budget = $this->limits['globalMaxBytes'];
        $sessions = $this->allSessions();
        $total = 0;
        foreach ($sessions as $session) {
            $total += $session['bytes'];
        }

        while ($total + $incomingBytes > $budget) {
            $others = array_values(array_filter($sessions, static function (array $s) use ($currentId): bool {
                return $s['id'] !== $currentId;
            }));
            $lru = $this->lruOf($others);
            if ($lru === null) {
                return false;
            }
            $this->deleteDir($lru['dir']);
            $total -= $lru['bytes'];
            $sessions = array_values(array_filter($sessions, static function (array $s) use ($lru): bool {
                return $s['id'] !== $lru['id'];
            }));
        }
        return true;
    }

    /**
     * Enumerate every session with its byte total and last-access time.
     *
     * @return array<int, array{id: string, dir: string, owner: int, bytes: int, lastAccess: int}>
     */
    private function allSessions(): array
    {
        if (!is_dir($this->basePath)) {
            return []; // @codeCoverageIgnore
        }
        $sessions = [];
        foreach ((array) @scandir($this->basePath) as $entry) {
            if ($entry === '.' || $entry === '..' || !preg_match(self::PREVIEW_ID_RE, (string) $entry)) {
                continue;
            }
            $dir = $this->sessionDir($entry);
            $meta = $this->readJson($dir . '/session.json');
            if ($meta === null) {
                continue;
            }
            $sessions[] = [
                'id' => (string) $entry,
                'dir' => $dir,
                'owner' => (int) ($meta['owner'] ?? -1),
                'bytes' => $this->sessionBytes($dir),
                'lastAccess' => $this->lastAccess($dir),
            ];
        }
        return $sessions;
    }

    /**
     * @param int $ownerId
     * @return array<int, array{id: string, dir: string, owner: int, bytes: int, lastAccess: int}>
     */
    private function sessionsOwnedBy(int $ownerId): array
    {
        return array_values(array_filter($this->allSessions(), static function (array $s) use ($ownerId): bool {
            return $s['owner'] === $ownerId;
        }));
    }

    /**
     * The least-recently-accessed session among candidates, or null if empty.
     *
     * @param array<int, array{lastAccess: int}> $candidates
     * @return array|null
     */
    private function lruOf(array $candidates): ?array
    {
        $lru = null;
        foreach ($candidates as $candidate) {
            if ($lru === null || $candidate['lastAccess'] < $lru['lastAccess']) {
                $lru = $candidate;
            }
        }
        return $lru;
    }

    /**
     * Opportunistic idle-TTL sweep, throttled to at most once per sweep interval
     * via a marker file, so a burst of serving requests does not each rescan.
     */
    private function maybeSweep(): void
    {
        if (!is_dir($this->basePath)) {
            return; // @codeCoverageIgnore
        }
        $marker = $this->basePath . '/.last_sweep';
        $now = ($this->now)();
        if (is_file($marker) && ($now - (int) @filemtime($marker)) < $this->limits['sweepIntervalSeconds']) {
            return;
        }
        @touch($marker, $now);

        foreach ($this->allSessions() as $session) {
            if ($now - $session['lastAccess'] > $this->limits['ttlSeconds']) {
                $this->deleteDir($session['dir']);
            }
        }
    }

    /** Whether a session's idle TTL has elapsed. */
    private function isExpired(string $dir): bool
    {
        return (($this->now)() - $this->lastAccess($dir)) > $this->limits['ttlSeconds'];
    }

    /** Last-access time (the marker file mtime, session dir mtime as fallback). */
    private function lastAccess(string $dir): int
    {
        $marker = $dir . '/last_access';
        if (is_file($marker)) {
            return (int) @filemtime($marker);
        }
        return (int) @filemtime($dir);
    }

    /** Touch the idle-TTL clock for a session. */
    private function touch(string $dir): void
    {
        @touch($dir . '/last_access', ($this->now)());
    }

    // =========================================================================
    // Revision pointer + document listing
    // =========================================================================

    /** The active revision number, or 0 when none has been published. */
    private function activeRevision(string $dir): int
    {
        $pointer = @file_get_contents($dir . '/current');
        if ($pointer === false) {
            return 0;
        }
        $value = (int) trim($pointer);
        return $value > 0 ? $value : 0;
    }

    /** Atomically point `current` at revision $n (write temp, then rename). */
    private function swapPointer(string $dir, int $n): void
    {
        $tmp = $dir . '/current.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, (string) $n);
        rename($tmp, $dir . '/current');
    }

    /** Keep the current and immediately previous revision; drop older ones. */
    private function pruneOldRevisions(string $dir, int $current): void
    {
        $revRoot = $dir . '/rev';
        foreach ((array) @scandir($revRoot) as $entry) {
            if ($entry === '.' || $entry === '..' || !ctype_digit((string) $entry)) {
                continue;
            }
            if ((int) $entry < $current - 1) {
                $this->deleteDir($revRoot . '/' . $entry);
            }
        }
    }

    /**
     * List a revision's documents as path (relative to `documents/`) → size.
     *
     * @param string $documentsDir
     * @return array<string, int>
     */
    private function listDocuments(string $documentsDir): array
    {
        if (!is_dir($documentsDir)) {
            return [];
        }
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($documentsDir, \FilesystemIterator::SKIP_DOTS)
        );
        $prefixLength = strlen($documentsDir) + 1;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), $prefixLength);
                $result[str_replace('\\', '/', $relative)] = $file->getSize();
            }
        }
        return $result;
    }

    /**
     * Normalize a served path → id map, rejecting the whole map on any unsafe
     * key.
     *
     * @param mixed $map
     * @return array<string, string>|null
     */
    private function normalizeRefMap($map): ?array
    {
        if (!is_array($map)) {
            return [];
        }
        $normalized = [];
        foreach ($map as $rawPath => $value) {
            $key = self::normalizePath((string) $rawPath);
            if ($key === null) {
                return null;
            }
            $normalized[$key] = (string) $value;
        }
        return $normalized;
    }

    // =========================================================================
    // Asset storage
    // =========================================================================

    /**
     * Asset filename: keyed by a hash of the validated key, so an assetKey's
     * `@` / mixed case never depends on filesystem case-sensitivity or
     * path-charset quirks. The served ETag is always the raw key.
     */
    private function assetFile(string $dir, string $key): string
    {
        return $dir . '/assets/' . sha1($key) . '.bin';
    }

    private function assetExists(string $dir, string $key): bool
    {
        return is_file($this->assetFile($dir, $key));
    }

    private function writeAsset(string $dir, string $key, string $bytes): void
    {
        $file = $this->assetFile($dir, $key);
        $parent = dirname($file);
        if (!is_dir($parent)) {
            @mkdir($parent, 0700, true);
        }
        file_put_contents($file, $bytes);
    }

    // =========================================================================
    // Filesystem primitives
    // =========================================================================

    private function ensureBase(): void
    {
        if (!is_dir($this->basePath)) {
            @mkdir($this->basePath, 0700, true);
        }
        $this->writeAccessGuard();
    }

    /**
     * Write a deny guard into the session base directory so the preview tree is
     * NEVER web-servable. The store lives under the Omeka file store, which
     * Apache serves directly; without this, a direct GET to a materialized
     * document (rev/{n}/documents/*.html) would return untrusted author HTML
     * SAME-ORIGIN and WITHOUT the sandbox CSP — PreviewController adds the CSP,
     * the static web server does not — a second, un-sandboxed serving path that
     * defeats the opaque-origin isolation. Written idempotently on the BASE dir
     * only (never per session), so an existing/stricter guard is never clobbered.
     *
     * This .htaccess is the Apache guard only. nginx and other web servers do
     * not read it: those deployments must place the store outside the web root
     * or add an equivalent `location` deny block.
     */
    private function writeAccessGuard(): void
    {
        if (!is_dir($this->basePath)) {
            return; // @codeCoverageIgnore
        }
        $htaccess = $this->basePath . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, self::DENY_HTACCESS);
        }
        $index = $this->basePath . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php // Silence is golden.\n");
        }
    }

    private function sessionDir(string $previewId): string
    {
        return $this->basePath . '/' . $previewId;
    }

    private function sessionIdOf(string $dir): string
    {
        return basename($dir);
    }

    /**
     * @param string $path
     * @return array|null Decoded JSON object, or null on any read/parse failure.
     */
    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function writeJsonAtomic(string $path, array $data): void
    {
        $parent = dirname($path);
        if (!is_dir($parent)) {
            @mkdir($parent, 0700, true);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES));
        rename($tmp, $path);
    }

    /**
     * Acquire an exclusive per-session lock for the publish critical section.
     *
     * @param string $dir
     * @return resource|null Open, locked handle, or null when locking is
     *                       unavailable (the publish still proceeds).
     */
    private function lock(string $dir)
    {
        $handle = @fopen($dir . '/.lock', 'c');
        if ($handle === false) {
            return null; // @codeCoverageIgnore
        }
        @flock($handle, LOCK_EX);
        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function unlock($handle): void
    {
        if ($handle !== null) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return; // @codeCoverageIgnore
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** Generate a lowercase RFC 4122 v4 UUID. */
    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
