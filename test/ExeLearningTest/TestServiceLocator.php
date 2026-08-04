<?php

declare(strict_types=1);

namespace ExeLearningTest;

use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * Array-backed container satisfying the interface Module type-hints.
 *
 * Module reaches for collaborators by service name ('Omeka\Settings',
 * 'Omeka\Logger', 'Omeka\Connection', 'Request', 'Application', …), so tests
 * register doubles under those same names. Asking for a service that was not
 * registered throws, which keeps a test from silently exercising a different
 * branch than it intended.
 */
class TestServiceLocator implements ServiceLocatorInterface
{
    /** @var array<string, mixed> */
    private array $services;

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(array $services = [])
    {
        $this->services = $services;
    }

    /**
     * @param mixed $service
     */
    public function set(string $name, $service): void
    {
        $this->services[$name] = $service;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function get($name)
    {
        if (!array_key_exists($name, $this->services)) {
            throw new \RuntimeException('Service not registered in test container: ' . $name);
        }
        return $this->services[$name];
    }

    /**
     * @param string $name
     */
    public function has($name): bool
    {
        return array_key_exists($name, $this->services);
    }

    /**
     * @param string     $name
     * @param array|null $options
     * @return mixed
     */
    public function build($name, ?array $options = null)
    {
        return $this->get($name);
    }
}
