<?php
declare(strict_types=1);

namespace Omeka\Settings;

/**
 * Minimal in-memory Settings stub used by the test suite.
 *
 * Mirrors the get/set contract of the real Omeka\Settings\Settings service
 * that the StylesService depends on.
 */
class Settings
{
    /** @var array<string,mixed> */
    private array $store = [];

    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
    }

    public function set(string $key, $value): void
    {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }
}
