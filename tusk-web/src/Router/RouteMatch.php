<?php

namespace Tusk\Web\Router;

class RouteMatch
{
    public function __construct(
        public string $controller,
        public string $method,
        public array $params = [],
        public array $middleware = []
    ) {}
}
