<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use Laminas\Session\Container as SessionContainer;

/**
 * A session-container test double that faithfully models the ONE Laminas
 * behavior the PreviewCsrf design turns on: `setExpirationSeconds($ttl)` stamps
 * an ABSOLUTE, container-global expiry (`now + $ttl`) that is checked on every
 * key read and, once elapsed, wipes the whole container — and is NEVER set when
 * the validator's timeout is null.
 *
 * It extends the test's Laminas\Session\Container stub (so it satisfies
 * Laminas\Validator\Csrf::setSession()'s type hint) but overrides every storage
 * accessor to use its own store plus a controllable clock, so a test can advance
 * time past 5 minutes and observe the real validator's behavior without a live
 * PHP session. The real container keys off $_SERVER['REQUEST_TIME'];
 * {@see advance()} is the injectable clock seam that stands in for it.
 */
class ClockedSessionContainer extends SessionContainer
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** Absolute expiry timestamp, or null when no expiry has been set. */
    private ?int $expireAt = null;

    private int $now;

    public function __construct(int $now)
    {
        parent::__construct('preview-clocked');
        $this->now = $now;
    }

    /** Advance the clock (stands in for the request time moving forward). */
    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    /**
     * Stamp an absolute container-global expiry, exactly as the real container
     * does for a Csrf validator with a finite timeout.
     *
     * @param int $seconds
     * @param string|null $data
     * @return self
     */
    public function setExpirationSeconds($seconds, $data = null): self
    {
        $this->expireAt = $this->now + (int) $seconds;
        return $this;
    }

    /** Wipe the container once its absolute expiry has elapsed (read-time check). */
    private function expireIfDue(): void
    {
        if ($this->expireAt !== null && $this->now > $this->expireAt) {
            $this->store = [];
            $this->expireAt = null;
        }
    }

    public function &__get($name): mixed
    {
        $this->expireIfDue();
        if (!array_key_exists($name, $this->store)) {
            $this->store[$name] = null;
        }
        return $this->store[$name];
    }

    public function __set($name, $value): void
    {
        $this->store[$name] = $value;
    }

    public function __isset($name): bool
    {
        $this->expireIfDue();
        return isset($this->store[$name]);
    }

    public function __unset($name): void
    {
        unset($this->store[$name]);
    }

    public function offsetExists($offset): bool
    {
        $this->expireIfDue();
        return isset($this->store[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        $this->expireIfDue();
        return $this->store[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->store[] = $value;
        } else {
            $this->store[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->store[$offset]);
    }
}
