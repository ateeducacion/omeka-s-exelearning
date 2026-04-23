<?php
declare(strict_types=1);

namespace ExeLearningTest\Controller\Admin;

use ExeLearning\Controller\Admin\StylesController;
use ExeLearning\Service\StylesService;

/**
 * Test double for StylesController that sidesteps the Laminas MVC
 * plugin manager. Each MVC plugin the real controller uses
 * (`allowed`, `params`, `getRequest`, `redirect`, `messenger`) is
 * replaced by a lightweight stub so action-level unit tests can
 * exercise the controller without a full Laminas\\Mvc runtime.
 */
class TestableStylesController extends StylesController
{
    public bool $allowedFlag;
    public bool $stubRequestIsPost = true;
    public array $stubPost = [];
    public ?array $lastRedirect = null;
    public array $messengerSuccess = [];
    public array $messengerError = [];

    public function __construct(StylesService $svc, bool $allowed = true)
    {
        parent::__construct($svc);
        $this->allowedFlag = $allowed;
    }

    // Override the private/protected MVC interactions.
    public function params($name = null, $default = null)
    {
        if (is_string($name)) {
            return $this->stubPost[$name] ?? $default;
        }
        // Mimic the plugin helper returning a params-like object.
        $post = $this->stubPost;
        return new class($post) {
            private array $post;
            public function __construct(array $post)
            {
                $this->post = $post;
            }
            public function fromPost($name = null, $default = null)
            {
                if ($name === null) {
                    return $this->post;
                }
                return $this->post[$name] ?? $default;
            }
            public function fromFiles($name = null, $default = null)
            {
                return $default;
            }
        };
    }

    public function getRequest(): object
    {
        return new class($this->stubRequestIsPost) {
            private bool $post;
            public function __construct(bool $post)
            {
                $this->post = $post;
            }
            public function isPost(): bool
            {
                return $this->post;
            }
        };
    }

    public function redirect()
    {
        $outer = $this;
        return new class($outer) {
            private TestableStylesController $outer;
            public function __construct(TestableStylesController $outer)
            {
                $this->outer = $outer;
            }
            public function toRoute(string $name)
            {
                $this->outer->lastRedirect = ['redirect' => $name];
                return null;
            }
        };
    }

    public function messenger()
    {
        $outer = $this;
        return new class($outer) {
            private TestableStylesController $outer;
            public function __construct(TestableStylesController $outer)
            {
                $this->outer = $outer;
            }
            public function addSuccess(string $msg): void
            {
                $this->outer->messengerSuccess[] = $msg;
            }
            public function addError(string $msg): void
            {
                $this->outer->messengerError[] = $msg;
            }
        };
    }

    protected function allowed(): bool
    {
        return $this->allowedFlag;
    }
}
