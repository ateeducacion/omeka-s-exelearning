<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Item representation exposing only media(), which is all
 * Module::handlePublicItemShow() iterates over.
 */
class FakeItem
{
    /** @var array<int, object> */
    private array $media;

    /**
     * @param array<int, object> $media
     */
    public function __construct(array $media = [])
    {
        $this->media = $media;
    }

    /**
     * @return array<int, object>
     */
    public function media(): array
    {
        return $this->media;
    }
}
