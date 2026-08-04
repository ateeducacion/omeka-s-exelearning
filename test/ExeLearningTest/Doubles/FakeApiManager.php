<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Omeka\ApiManager double for Module::handleMediaCreate(), which reads the
 * freshly created entity back as a representation.
 */
class FakeApiManager
{
    /** @var object|null */
    private $media;

    /** @var \Throwable|null Thrown by read() when set. */
    public $exception = null;

    /**
     * @param object|null $media
     */
    public function __construct($media)
    {
        $this->media = $media;
    }

    /**
     * @param string     $resource
     * @param int|string $id
     */
    public function read($resource, $id): object
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }
        return new FakeApiResponse($this->media);
    }
}
