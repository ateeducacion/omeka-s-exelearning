<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Request whose URI can be varied per test.
 *
 * The shared Laminas\Uri\Http stub is fixed at http://localhost/admin/media/1;
 * buildContentUrl() has to be exercised across schemes, ports and base paths,
 * which is what this double provides.
 */
class FakeHttpRequest
{
    private string $scheme;
    private string $host;
    /** @var int|null */
    private $port;
    private string $path;

    public function __construct(string $scheme, string $host, ?int $port, string $path)
    {
        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
    }

    public function getUri(): object
    {
        return new class ($this->scheme, $this->host, $this->port, $this->path) {
            private string $scheme;
            private string $host;
            /** @var int|null */
            private $port;
            private string $path;

            public function __construct(string $scheme, string $host, ?int $port, string $path)
            {
                $this->scheme = $scheme;
                $this->host = $host;
                $this->port = $port;
                $this->path = $path;
            }

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
        };
    }
}
