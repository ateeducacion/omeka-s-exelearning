<?php

declare(strict_types=1);

namespace Omeka\Stdlib;

/**
 * Test stub for Omeka's translatable message value object.
 *
 * The real class defers interpolation until the translator renders it; the
 * stub applies sprintf() eagerly so assertions can compare the final string.
 */
class Message
{
    /** @var string */
    protected $message;

    /** @var array<int, mixed> */
    protected $args;

    /**
     * @param string $message
     * @param mixed  ...$args
     */
    public function __construct($message, ...$args)
    {
        $this->message = (string) $message;
        $this->args = $args;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return array<int, mixed>
     */
    public function getArgs()
    {
        return $this->args;
    }

    public function __toString(): string
    {
        if ($this->args === []) {
            return $this->message;
        }
        return vsprintf($this->message, $this->args);
    }
}
