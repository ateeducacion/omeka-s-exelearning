<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ContentControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = $container->get('Config');

        // Get the files path from Omeka config
        $localConfig = $config['file_store']['local']['base_path']
            ?? (OMEKA_PATH . '/files');

        $basePath = $localConfig . '/exelearning';

        // The iframe-security mode also drives the response CSP: in secure mode the
        // served HTML carries a `sandbox` directive so the content stays opaque-origin
        // even when opened outside the embedding iframe (new tab / escaped popup).
        $settings = $container->get('Omeka\Settings');
        $mode = \ExeLearning\Service\IframeSandbox::normalizeMode(
            $settings->get('exelearning_iframe_mode', 'secure')
        );

        return new ContentController($basePath, $mode);
    }
}
