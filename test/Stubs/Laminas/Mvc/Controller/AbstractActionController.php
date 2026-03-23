<?php

declare(strict_types=1);

namespace Laminas\Mvc\Controller;

use Laminas\Http\Request;
use Laminas\Http\Response;

/**
 * Minimal stub for Laminas\Mvc\Controller\AbstractActionController for tests.
 */
abstract class AbstractActionController
{
    protected ?object $request = null;
    protected ?Response $response = null;
    protected array $routeParams = [];
    protected ?object $identity = null;
    protected ?object $event = null;
    protected ?object $apiManager = null;
    protected bool $userAllowed = true;
    protected array $mediaStore = [];
    /** @var string */
    protected $stubBasePath = '';

    public function setRequest(object $request): void
    {
        $this->request = $request;
    }

    public function getRequest(): object
    {
        if (!$this->request) {
            $this->request = new Request();
        }
        return $this->request;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    public function getResponse(): Response
    {
        if (!$this->response) {
            $this->response = new Response();
        }
        return $this->response;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function params(?string $param = null, $default = null)
    {
        if ($param === null) {
            return new class($this->routeParams) {
                private array $params;
                public function __construct(array $params)
                {
                    $this->params = $params;
                }
                public function fromRoute($name, $default = null)
                {
                    return $this->params[$name] ?? $default;
                }
                public function fromQuery($name, $default = null)
                {
                    return $default;
                }
                public function fromPost($name, $default = null)
                {
                    return $default;
                }
            };
        }
        return $this->routeParams[$param] ?? $default;
    }

    public function setIdentity(?object $identity): void
    {
        $this->identity = $identity;
    }

    public function identity(): ?object
    {
        return $this->identity;
    }

    public function setUserAllowed(bool $allowed): void
    {
        $this->userAllowed = $allowed;
    }

    public function setEvent(?object $event): void
    {
        $this->event = $event;
    }

    public function getEvent(): ?object
    {
        if (!$this->event) {
            $userAllowed = $this->userAllowed;
            $this->event = new class($userAllowed) {
                private bool $userAllowed;
                public function __construct(bool $userAllowed)
                {
                    $this->userAllowed = $userAllowed;
                }
                public function getApplication()
                {
                    $userAllowed = $this->userAllowed;
                    return new class($userAllowed) {
                        private bool $userAllowed;
                        public function __construct(bool $userAllowed)
                        {
                            $this->userAllowed = $userAllowed;
                        }
                        public function getServiceManager()
                        {
                            $userAllowed = $this->userAllowed;
                            return new class($userAllowed) {
                                private bool $userAllowed;
                                public function __construct(bool $userAllowed)
                                {
                                    $this->userAllowed = $userAllowed;
                                }
                                public function get(string $name)
                                {
                                    if ($name === 'Omeka\Acl') {
                                        $userAllowed = $this->userAllowed;
                                        return new class($userAllowed) {
                                            private bool $userAllowed;
                                            public function __construct(bool $userAllowed)
                                            {
                                                $this->userAllowed = $userAllowed;
                                            }
                                            public function userIsAllowed(string $resource, string $privilege): bool
                                            {
                                                return $this->userAllowed;
                                            }
                                        };
                                    }
                                    if ($name === 'Omeka\Settings') {
                                        return new class {
                                            private array $store = [];
                                            public function set(string $key, $value): void
                                            {
                                                $this->store[$key] = $value;
                                            }
                                            public function get(string $key, $default = null)
                                            {
                                                return $this->store[$key] ?? $default;
                                            }
                                        };
                                    }
                                    return null;
                                }
                            };
                        }
                    };
                }
            };
        }
        return $this->event;
    }

    public function setApiManager(?object $apiManager): void
    {
        $this->apiManager = $apiManager;
    }

    public function setStubBasePath(string $basePath): void
    {
        $this->stubBasePath = $basePath;
    }

    public function addMedia(int $id, object $media): void
    {
        $this->mediaStore[$id] = $media;
    }

    public function api()
    {
        if ($this->apiManager) {
            return $this->apiManager;
        }
        $mediaStore = $this->mediaStore;
        return new class($mediaStore) {
            private array $mediaStore;
            public function __construct(array $mediaStore)
            {
                $this->mediaStore = $mediaStore;
            }
            public function read(string $resource, $id)
            {
                if ($resource === 'media' && isset($this->mediaStore[$id])) {
                    $media = $this->mediaStore[$id];
                    return new class($media) {
                        private object $content;
                        public function __construct(object $content)
                        {
                            $this->content = $content;
                        }
                        public function getContent(): object
                        {
                            return $this->content;
                        }
                    };
                }
                throw new \Exception('API not available in test');
            }
        };
    }

    public function url()
    {
        return new class {
            public function fromRoute(string $route, array $params = []): string
            {
                $url = '/' . $route;
                foreach ($params as $key => $value) {
                    $url .= '/' . $value;
                }
                return $url;
            }
        };
    }

    public function redirect()
    {
        return new class {
            public function toRoute(string $route): Response
            {
                $response = new Response();
                $response->setStatusCode(302);
                return $response;
            }
        };
    }

    public function messenger()
    {
        return new class {
            public function addError(string $message): void {}
            public function addSuccess(string $message): void {}
            public function addWarning(string $message): void {}
        };
    }

    public function settings()
    {
        return new class {
            public function get(string $key, $default = null)
            {
                return $default;
            }
        };
    }

    public function translate(string $message): string
    {
        return $message;
    }
}
