<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Entity that exposes getSource() but not getFilename().
 *
 * Module::handleMediaHydrate() falls back to the source when no stored
 * filename exists yet -- the state an entity is in before the file has been
 * moved into the store.
 */
class FakeSourceOnlyEntity
{
    /** @var string|null */
    public $renderer = null;

    private string $source;

    public function __construct(string $source)
    {
        $this->source = $source;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setRenderer(string $renderer): void
    {
        $this->renderer = $renderer;
    }
}
