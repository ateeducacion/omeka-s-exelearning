<?php

declare(strict_types=1);

namespace Laminas\Uri;

/**
 * Minimal stub for Laminas\Uri\Http for tests.
 */
class Http
{
    private string $scheme = 'http';
    private string $host = 'localhost';
    private ?int $port = null;
    private string $path = '/admin/media/1';

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
