<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ElpFileServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');
        $logger = $services->get('Omeka\Logger');

        $filesPath = null;

        // Try config first (most reliable, works in Playground too)
        try {
            $config = $services->get('Config');
            $filesPath = $config['file_store']['local']['base_path'] ?? null;
        } catch (\Throwable $e) {
            // ignore
        }

        // Try the file store service
        if (!$filesPath) {
            try {
                $fileStore = $services->get('Omeka\File\Store');
                if (method_exists($fileStore, 'getLocalPath')) {
                    $filesPath = dirname($fileStore->getLocalPath(''));
                }
            } catch (\Throwable $e) {
                // ignore - file store may not be available in Playground
            }
        }

        // Fallback: use OMEKA_PATH
        if (!$filesPath) {
            $filesPath = defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : '/var/www/html/files';
        }

        // In Docker with volume, files are typically in /var/www/html/volume/files
        $volumePath = '/var/www/html/volume/files';
        if (is_dir($volumePath)) {
            $filesPath = $volumePath;
        }

        // Extracted eXeLearning content goes in /files/exelearning/
        $basePath = $filesPath . '/exelearning';

        return new ElpFileService($api, $entityManager, $basePath, $filesPath, $logger);
    }
}
