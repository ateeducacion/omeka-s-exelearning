<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Reproduces the Application -> MvcEvent -> RouteMatch chain that
 * Module::handleViewLayout() walks to decide whether the current request is an
 * admin route. A null route name models a request that has not been routed.
 */
class FakeApplication
{
    /** @var string|null */
    private $routeName;

    public function __construct(?string $routeName)
    {
        $this->routeName = $routeName;
    }

    public function getMvcEvent(): object
    {
        $routeName = $this->routeName;

        return new class ($routeName) {
            /** @var string|null */
            private $routeName;

            public function __construct(?string $routeName)
            {
                $this->routeName = $routeName;
            }

            public function getRouteMatch(): ?object
            {
                if ($this->routeName === null) {
                    return null;
                }
                $name = $this->routeName;
                return new class ($name) {
                    /** @var string */
                    private $name;

                    public function __construct(string $name)
                    {
                        $this->name = $name;
                    }

                    public function getMatchedRouteName(): string
                    {
                        return $this->name;
                    }
                };
            }
        };
    }
}
