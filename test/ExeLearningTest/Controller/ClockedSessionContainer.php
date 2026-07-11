<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use Laminas\Session\Container as SessionContainer;

/**
 * A session-container test double that faithfully models the TWO Laminas expiry
 * mechanisms the PreviewCsrf design turns on:
 *
 *  - `setExpirationSeconds($ttl)` stamps an ABSOLUTE, container-global expiry
 *    (`now + $ttl`), checked on every key read; and
 *  - `setExpirationHops($n)` expires the container after `$n` further request
 *    cycles — which, if a Csrf validator armed it, would kill a token after ONE
 *    subsequent publish regardless of timeout.
 *
 * Neither must be armed for the preview namespace (timeout=null, and the shipped
 * Laminas versions never call setExpirationHops for Csrf). It also RECORDS which
 * mechanism a validator armed, so a test can assert the preview mint arms
 * neither. It extends the test's Laminas\Session\Container stub (to satisfy
 * Laminas\Validator\Csrf::setSession()'s type hint) but overrides every storage
 * accessor to use its own store plus a controllable clock/request counter, so a
 * test can advance time and requests and observe the real validator without a
 * live PHP session. The real container keys off $_SERVER['REQUEST_TIME'] /
 * per-request access; {@see advance()} and {@see nextRequest()} are the seams.
 */
class ClockedSessionContainer extends SessionContainer
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** Absolute seconds-expiry timestamp, or null when unset. */
    private ?int $expireAt = null;

    /** Request-cycle at which a hop-expiry elapses, or null when unset. */
    private ?int $hopsExpireAt = null;

    private int $now;

    private int $requests = 0;

    private bool $secondsArmed = false;

    private bool $hopsArmed = false;

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

    /** Advance to the next request cycle (drives hop-based expiry). */
    public function nextRequest(): void
    {
        $this->requests++;
    }

    /** Whether a validator armed a seconds-based expiry on this container. */
    public function secondsWereArmed(): bool
    {
        return $this->secondsArmed;
    }

    /** Whether a validator armed a hop-based expiry on this container. */
    public function hopsWereArmed(): bool
    {
        return $this->hopsArmed;
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
        $this->secondsArmed = true;
        $this->expireAt = $this->now + (int) $seconds;
        return $this;
    }

    /**
     * Expire the container after `$hops` further request cycles.
     *
     * @param int $hops
     * @param string|null $data
     * @return self
     */
    public function setExpirationHops($hops, $data = null): self
    {
        $this->hopsArmed = true;
        $this->hopsExpireAt = $this->requests + (int) $hops;
        return $this;
    }

    /** Wipe the container once a seconds- or hop-based expiry has elapsed. */
    private function expireIfDue(): void
    {
        $secondsDue = $this->expireAt !== null && $this->now > $this->expireAt;
        $hopsDue = $this->hopsExpireAt !== null && $this->requests > $this->hopsExpireAt;
        if ($secondsDue || $hopsDue) {
            $this->store = [];
            $this->expireAt = null;
            $this->hopsExpireAt = null;
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
