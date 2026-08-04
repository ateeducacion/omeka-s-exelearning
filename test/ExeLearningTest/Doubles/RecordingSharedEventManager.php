<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

use Laminas\EventManager\SharedEventManagerInterface;

/**
 * Captures what Module::attachListeners() registers.
 */
class RecordingSharedEventManager implements SharedEventManagerInterface
{
    /** @var array<int, array{identifier: string, event: string, listener: callable, priority: int}> */
    public array $attached = [];

    /**
     * @param string $identifier
     * @param string $event
     * @param int    $priority
     */
    public function attach($identifier, $event, callable $listener, $priority = 1)
    {
        $this->attached[] = [
            'identifier' => (string) $identifier,
            'event' => (string) $event,
            'listener' => $listener,
            'priority' => (int) $priority,
        ];
    }

    /**
     * @param string|null $identifier
     * @param string|null $event
     */
    public function detach(callable $listener, $identifier = null, $event = null)
    {
    }

    /**
     * @param string $event
     * @return array<int, callable>
     */
    public function getListeners(array $identifiers, $event)
    {
        return [];
    }

    /**
     * @param string|null $identifier
     * @param string|null $event
     */
    public function clearListeners($identifier, $event = null)
    {
    }
}
