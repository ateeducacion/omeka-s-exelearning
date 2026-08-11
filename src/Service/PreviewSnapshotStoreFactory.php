<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Builds the snapshot store over a private, site-scoped directory.
 *
 * The store lives outside the web root so no direct web-server path can bypass
 * the serving route and its sandbox CSP. The site key keeps two Omeka installs
 * on one host from sharing a capability namespace.
 */
class PreviewSnapshotStoreFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $config = $container->get('Config');
        $configuredPath = $config['exelearning']['preview_store_path'] ?? null;
        $siteKey = substr(hash('sha256', (string) OMEKA_PATH), 0, 16);
        $basePath = is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : sys_get_temp_dir() . '/omeka-s-exelearning-preview-' . $siteKey;

        return new PreviewSnapshotStore(rtrim($basePath, '/\\'));
    }
}
