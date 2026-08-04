<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Doctrine media entity as seen by api.hydrate.post and api.delete.pre.
 *
 * Those events hand Module an entity, not a representation, which is why the
 * production code probes it with method_exists() rather than an interface.
 */
class FakeMediaEntity
{
    /** @var string|null Renderer set by Module::handleMediaHydrate(). */
    public $renderer = null;

    /** @var array<string, mixed> */
    public array $data = [];

    private string $filename;
    private int $id;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $filename, int $id = 1, array $data = [])
    {
        $this->filename = $filename;
        $this->id = $id;
        $this->data = $data;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getFilename(): ?string
    {
        return $this->filename === '' ? null : $this->filename;
    }

    public function setRenderer(string $renderer): void
    {
        $this->renderer = $renderer;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }
}
