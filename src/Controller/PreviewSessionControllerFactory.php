<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\PreviewSessionStore;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Injects the ephemeral preview session store into the management controller.
 */
class PreviewSessionControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new PreviewSessionController($container->get(PreviewSessionStore::class));
    }
}
