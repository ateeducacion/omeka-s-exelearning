<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\PreviewSnapshotStore;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Injects the snapshot store into the authless serving controller.
 */
class PreviewControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new PreviewController($container->get(PreviewSnapshotStore::class));
    }
}
