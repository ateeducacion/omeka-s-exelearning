<?php

declare(strict_types=1);

namespace Omeka\Module;

use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * Test stub for Omeka's module base class.
 *
 * Only the surface Module.php actually relies on is reproduced: the service
 * locator it stores at bootstrap and the lifecycle hooks it overrides. The
 * real class also wires the MVC event and the module manager, none of which a
 * unit test can drive without a full Omeka runtime.
 */
abstract class AbstractModule
{
    /** @var ServiceLocatorInterface|null */
    protected $serviceLocator;

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator($serviceLocator): void
    {
        $this->serviceLocator = $serviceLocator;
    }

    /**
     * @return ServiceLocatorInterface|null
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function install(ServiceLocatorInterface $serviceLocator)
    {
    }

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
    }

    /**
     * @param string                  $oldVersion
     * @param string                  $newVersion
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
    }
}
