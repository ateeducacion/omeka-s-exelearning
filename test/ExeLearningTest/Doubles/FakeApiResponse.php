<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Omeka API response wrapper: the only method Module touches is getContent().
 */
class FakeApiResponse
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
