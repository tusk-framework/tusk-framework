<?php

namespace Tusk\Web\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    private int $index = 0;
    
    public function __construct(
        private RequestHandlerInterface $fallbackHandler
    ) {}

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $pipeline = clone $this;
        
        if (!isset($pipeline->middlewares[$pipeline->index])) {
            return $pipeline->fallbackHandler->handle($request);
        }

        $middleware = $pipeline->middlewares[$pipeline->index];
        $pipeline->index++;

        return $middleware->process($request, $pipeline);
    }
}
