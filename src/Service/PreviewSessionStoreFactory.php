<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Builds the file-backed preview session store in a private temporary tree.
 *
 * Materialized preview documents contain untrusted author HTML/SVG/XML and must
 * never be reachable directly through Omeka's public `files/` tree, where they
 * would bypass PreviewController and its sandbox CSP. A site-scoped system-temp
 * directory is therefore the secure default. Deployments may provide another
 * PRIVATE path through `exelearning.preview_store_path`.
 */
class PreviewSessionStoreFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = $container->get('Config');
        $configuredPath = $config['exelearning']['preview_store_path'] ?? null;
        $siteKey = substr(hash('sha256', (string) OMEKA_PATH), 0, 16);
        $basePath = is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : sys_get_temp_dir() . '/omeka-s-exelearning-preview-' . $siteKey;

        return new PreviewSessionStore(
            rtrim($basePath, '/\\'),
            $container->get(PreviewFixedResources::class)
        );
    }
}
