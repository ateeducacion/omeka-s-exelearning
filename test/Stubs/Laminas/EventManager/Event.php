<?php

declare(strict_types=1);

namespace Laminas\EventManager;

/**
 * Test stub for Laminas' event value object.
 *
 * Module.php uses only getTarget(), getParam() and setParam(); the stub keeps
 * the same semantics, including getParam()'s default for a missing key.
 */
class Event
{
    /** @var string|null */
    protected $name;

    /** @var mixed */
    protected $target;

    /** @var array<string, mixed> */
    protected $params = [];

    /**
     * @param string|null          $name
     * @param mixed                $target
     * @param array<string, mixed> $params
     */
    public function __construct($name = null, $target = null, array $params = [])
    {
        $this->name = $name;
        $this->target = $target;
        $this->params = $params;
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return mixed
     */
    public function getTarget()
    {
        return $this->target;
    }

    /**
     * @param mixed $target
     */
    public function setTarget($target): void
    {
        $this->target = $target;
    }

    /**
     * @param string $name
     * @param mixed  $default
     * @return mixed
     */
    public function getParam($name, $default = null)
    {
        return array_key_exists($name, $this->params) ? $this->params[$name] : $default;
    }

    /**
     * @param string $name
     * @param mixed  $value
     */
    public function setParam($name, $value): void
    {
        $this->params[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }
}
