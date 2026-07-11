<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Builds the file-backed preview session store under the Omeka file store.
 *
 * Sessions live in a dedicated `exelearning-preview/` tree next to the
 * published-content `exelearning/` tree used by ContentController; both derive
 * from the local file store base path (or OMEKA_PATH/files as a fallback). The
 * tree is ephemeral — the store sweeps idle sessions on access.
 */
class PreviewSessionStoreFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = $container->get('Config');
        $localBase = $config['file_store']['local']['base_path']
            ?? (OMEKA_PATH . '/files');
        $basePath = $localBase . '/exelearning-preview';

        return new PreviewSessionStore(
            $basePath,
            $container->get(PreviewFixedResources::class)
        );
    }
}
