<?php

namespace Tusk\Security\Middleware;

use Tusk\Framework\Http\MiddlewareInterface;
use Tusk\Framework\Http\RequestHandlerInterface;
use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;
use Tusk\Security\Contract\GuardInterface;
use Tusk\Security\Authorization\Gate;
use Tusk\Security\Attribute\Authenticated;
use Tusk\Security\Attribute\Can;
use ReflectionClass;
use ReflectionMethod;

class SecurityMiddleware
{
    public function __construct(
        private GuardInterface $guard,
        private Gate $gate
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Ideally, we would inspect the route handler here to find Attributes.
        // For this v0.1 implementation, we assume the handler is resolved and available via request attribute
        // or we rely on the Router to pass reflection info.

        // This is a simplified version. In a real implementation we need access to the matched route's controller/action.
        $controller = $request->attributes->get('_controller');
        $action = $request->attributes->get('_action');

        if ($controller && $action) {
            $this->checkAttributes($controller, $action);
        }

        return $next($request);
    }

    private function checkAttributes(string $controller, string $action): void
    {
        $class = new ReflectionClass($controller);
        $method = $class->getMethod($action);

        $attributes = array_merge(
            $class->getAttributes(),
            $method->getAttributes()
        );

        foreach ($attributes as $attribute) {
            $inst = $attribute->newInstance();

            if ($inst instanceof Authenticated) {
                if (!$this->guard->check()) {
                    throw new \RuntimeException("Unauthenticated", 401);
                }
            }

            if ($inst instanceof Can) {
                if (!$this->gate->allows($inst->ability, $inst->subject)) {
                    throw new \RuntimeException("Unauthorized", 403);
                }
            }
        }
    }
}
