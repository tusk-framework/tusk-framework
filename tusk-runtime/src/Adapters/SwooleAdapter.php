<?php

namespace Tusk\Runtime\Adapters;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Throwable;

class SwooleAdapter implements RuntimeAdapterInterface
{
    // Real implementation would hold:
    // private \Swoole\Http\Server $server;

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        echo "[Swoole] Adapter started.\n";
        
        // Swoole handles its own HTTP Server loop and coroutines
        /*
        $this->server = new \Swoole\Http\Server("0.0.0.0", 9501);
        
        $this->server->on("request", function ($swooleRequest, $swooleResponse) use ($container, $requestHandler) {
            try {
                // Convert Swoole Request to PSR-7 (or framework native)
                $response = $requestHandler($swooleRequest);
                // Send response
                $swooleResponse->end($response);
            } catch (Throwable $e) {
                $swooleResponse->status(500);
                $swooleResponse->end("Internal Server Error");
            } finally {
                // Context Isolation: clean request scoped container services
                // Note: In true Swoole, since it uses Coroutines, context resetting 
                // might need to be tied to Coroutine Context (Swoole\Coroutine::getContext()).
                $container->resetScope('request');
            }
        });
        
        $this->server->start();
        */
    }

    public function stop(): void
    {
        // if (isset($this->server)) {
        //     $this->server->shutdown();
        // }
    }

    public function getName(): string
    {
        return 'swoole';
    }
}
