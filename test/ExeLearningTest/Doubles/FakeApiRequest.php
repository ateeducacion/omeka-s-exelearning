<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Omeka API request carrying the posted media-edit payload.
 */
class FakeApiRequest
{
    /** @var mixed */
    private $content;

    /**
     * @param mixed $content
     */
    public function __construct($content)
    {
        $this->content = $content;
    }

    /**
     * @return mixed
     */
    public function getContent()
    {
        return $this->content;
    }
}
