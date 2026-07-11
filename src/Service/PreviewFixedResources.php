<?php
declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * Fixed-resource resolver for the editor preview (serving contract v2, layer 1).
 *
 * Mirrors eXe core src/services/preview-fixed-resources.ts. The installed static
 * editor distribution emits `bundles/preview-fixed-resources.json`, enumerating
 * every installation-immutable file the preview serving route may resolve
 * OUTSIDE a session (official libraries, base iDevice runtimes, base themes,
 * PDF.js, content CSS, logo, global fonts). This class is the ONLY path from a
 * `fixedResourceId` to the filesystem:
 *
 * - ids resolve by EXACT map lookup — client input never becomes a path;
 * - only `resources[id].path` (server-controlled build output) reaches the
 *   filesystem, resolved under the distribution root with containment checks
 *   that also reject `..` and symlink escapes;
 * - unknown ids are a lookup miss, never a filesystem probe.
 *
 * A missing or invalid manifest disables the fixed layer (never fatal): every
 * id then resolves to a miss, so a revision carrying `fixedRefs` gets a 422 and
 * the client demotes those paths to document writes. The manifest is loaded
 * lazily and cached for the request lifetime.
 */
final class PreviewFixedResources
{
    /** Distribution root every manifest path must stay under. */
    private string $distRoot;

    /** Location of the build-emitted manifest JSON. */
    private string $manifestPath;

    /** Lazily loaded id => ['path' => string, 'size' => int]; null until loaded. */
    private ?array $resources = null;

    /**
     * @param string      $distRoot     Root of the installed static editor.
     * @param string|null $manifestPath Manifest path; defaults to
     *                                  {distRoot}/bundles/preview-fixed-resources.json.
     */
    public function __construct(string $distRoot, ?string $manifestPath = null)
    {
        $this->distRoot = rtrim($distRoot, '/');
        $this->manifestPath = $manifestPath ?? ($this->distRoot . '/bundles/preview-fixed-resources.json');
    }

    /** Absolute path of the manifest this resolver reads (diagnostics). */
    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    /** Exact-id membership test (the applyRevision validation hook). */
    public function hasResource(string $id): bool
    {
        return array_key_exists($id, $this->load());
    }

    /** All ids the manifest declares (diagnostics / conformance tooling). */
    public function manifestIds(): array
    {
        return array_keys($this->load());
    }

    /**
     * Resolve a fixed resource id to its bytes. Returns null for unknown ids,
     * manifest paths escaping the distribution root (path arithmetic or
     * symlinks), and missing files. Client-supplied data can only ever be the
     * exact-match `$id`.
     *
     * @param string $id
     * @return string|null Raw file bytes, or null on any miss.
     */
    public function getResource(string $id): ?string
    {
        $resources = $this->load();
        if (!isset($resources[$id])) {
            return null;
        }

        $rootReal = realpath($this->distRoot);
        if ($rootReal === false) {
            return null;
        }

        // Reject absolute manifest paths lexically before touching the FS.
        $relative = $resources[$id]['path'];
        if ($relative === '' || $relative[0] === '/' || $relative[0] === '\\') {
            return null;
        }

        // realpath() collapses `..` and resolves symlinks; containment on the
        // resolved path rejects both traversal and a symlink escape.
        $candidate = realpath($this->distRoot . '/' . $relative);
        if ($candidate === false) {
            return null;
        }
        if ($candidate !== $rootReal && strpos($candidate, $rootReal . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }

        $bytes = @file_get_contents($candidate);
        return $bytes === false ? null : $bytes;
    }

    /**
     * Load and validate the manifest once. A missing or invalid manifest
     * resolves to an empty map, disabling the fixed layer without an error.
     *
     * @return array<string, array{path: string, size: int}>
     */
    private function load(): array
    {
        if ($this->resources !== null) {
            return $this->resources;
        }

        $this->resources = [];

        $json = @file_get_contents($this->manifestPath);
        if ($json === false) {
            return $this->resources;
        }

        $data = json_decode($json, true);
        $valid = is_array($data)
            && ($data['schemaVersion'] ?? null) === 1
            && isset($data['resources'])
            && is_array($data['resources']);
        if (!$valid) {
            return $this->resources;
        }

        foreach ($data['resources'] as $id => $entry) {
            if (is_array($entry) && isset($entry['path']) && is_string($entry['path'])) {
                $this->resources[(string) $id] = [
                    'path' => $entry['path'],
                    'size' => (int) ($entry['size'] ?? 0),
                ];
            }
        }

        return $this->resources;
    }
}
