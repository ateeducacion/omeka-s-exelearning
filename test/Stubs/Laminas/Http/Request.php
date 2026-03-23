<?php

declare(strict_types=1);

namespace Laminas\Http;

use Laminas\Uri\Http as HttpUri;

/**
 * Minimal stub for Laminas\Http\Request for tests.
 */
class Request
{
    private HttpUri $uri;
    private string $basePath = '';

    public function __construct()
    {
        $this->uri = new HttpUri();
    }

    public function getUri(): HttpUri
    {
        return $this->uri;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
