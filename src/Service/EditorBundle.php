<?php

declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * Read-only view of the static eXeLearning editor bundled with the module.
 *
 * The embedded editor is a release artifact: official release packages ship
 * it pre-built under dist/static/, and that bundle is the only editor source
 * the module ever uses. There is no runtime install or update path; when the
 * bundle is missing (a source checkout without `make build-editor`) or
 * invalid, embedded editing is disabled. See ADR-28-01.
 */
class EditorBundle
{
    /** Asset directories, at least one of which a valid bundle must contain. */
    const ASSET_DIRS = ['app', 'libs', 'files'];

    /**
     * Get the bundled editor directory path.
     */
    public static function getPath(): string
    {
        return static::getModuleDir() . '/dist/static';
    }

    /**
     * Whether a valid editor bundle is available.
     *
     * Requires a readable index.html plus at least one of the expected asset
     * directories, so a stray or truncated dist/static/ is rejected.
     */
    public static function isAvailable(): bool
    {
        $path = static::getPath();
        if (!is_readable($path . '/index.html')) {
            return false;
        }
        foreach (self::ASSET_DIRS as $dir) {
            if (is_dir($path . '/' . $dir)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Base module directory holding dist/static/.
     *
     * Resolved through late static binding so tests can point the helper at a
     * fixture directory by subclassing.
     */
    protected static function getModuleDir(): string
    {
        return dirname(__DIR__, 2);
    }
}
