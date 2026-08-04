<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

use Laminas\Mvc\Controller\AbstractController;

/**
 * Controller carrying a posted config-form payload.
 *
 * Module::handleConfigForm() type-hints AbstractController and then calls
 * params()->fromPost(), so the double extends the stub base class and supplies
 * that one plugin.
 */
class FakeConfigController extends AbstractController
{
    /** @var array<string, mixed> */
    private array $post;

    /**
     * @param array<string, mixed> $post
     */
    public function __construct(array $post)
    {
        $this->post = $post;
    }

    public function params(): object
    {
        $post = $this->post;

        return new class ($post) {
            /** @var array<string, mixed> */
            private array $post;

            /**
             * @param array<string, mixed> $post
             */
            public function __construct(array $post)
            {
                $this->post = $post;
            }

            /**
             * @param string|null $name
             * @param mixed       $default
             * @return mixed
             */
            public function fromPost($name = null, $default = null)
            {
                if ($name === null) {
                    return $this->post;
                }
                return $this->post[$name] ?? $default;
            }
        };
    }
}
