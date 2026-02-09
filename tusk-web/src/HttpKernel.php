<?php

namespace Tusk\Web;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;
use Tusk\Web\Router\Router;

class HttpKernel
{
    public function __construct(
        private ContainerInterface $container,
        private Router $router
    ) {
    }

    public function handle(Request $request): Response
    {
        $handler = $this->router->match($request);

        if (!$handler) {
            return new Response(404, [], 'Not Found');
        }

        // Handler is [ControllerClass, methodName]
        if (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
            $controllerClass = $handler[0];
            $method = $handler[1];

            // Resolve controller from DI container
            $controllerInstance = $this->container->get($controllerClass);

            // Execute method
            $response = $controllerInstance->$method($request);

            if ($response instanceof ResponsableInterface) {
                $response = $response->toResponse($request);
            }

            if (!$response instanceof Response) {
                // If controller returns string/array, normalize it?
                // For v0.3 strict typing: Request -> Response
                return new Response(500, [], "Controller must return a Response object.");
            }

            return $response;
        }

        return new Response(500, [], "Invalid handler.");
    }
}
