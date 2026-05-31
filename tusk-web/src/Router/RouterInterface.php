<?php

namespace Tusk\Web\Router;

interface RouterInterface
{
    /**
     * Matches an HTTP method and URI to a compiled route.
     * Returns null if no match is found.
     */
    public function match(string $method, string $uri): ?RouteMatch;
}
