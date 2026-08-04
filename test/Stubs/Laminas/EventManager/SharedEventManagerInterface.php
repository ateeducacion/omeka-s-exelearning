<?php

declare(strict_types=1);

namespace Laminas\EventManager;

/**
 * Test stub for Laminas' shared event manager contract.
 *
 * Module::attachListeners() receives an implementation of this and only calls
 * attach(). ExeLearningTest\Stubs\RecordingSharedEventManager implements it so
 * a test can assert which identifier/event/callback triples were registered.
 */
interface SharedEventManagerInterface
{
    /**
     * @param string   $identifier
     * @param string   $event
     * @param callable $listener
     * @param int      $priority
     */
    public function attach($identifier, $event, callable $listener, $priority = 1);

    /**
     * @param string      $identifier
     * @param string|null $event
     */
    public function detach(callable $listener, $identifier = null, $event = null);

    /**
     * @param string $identifier
     * @param string $event
     * @return array<int, callable>
     */
    public function getListeners(array $identifiers, $event);

    /**
     * @param string|null $identifier
     */
    public function clearListeners($identifier, $event = null);
}
