<?php

namespace Tusk\Runtime\Adapters;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Throwable;

class NativeLoopAdapter implements RuntimeAdapterInterface
{
    private bool $running = false;

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        $this->running = true;
        
        echo "[NativeLoop] Worker started (PID: " . getmypid() . ").\n";

        // Catch signals for graceful shutdown (requires ext-pcntl)
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'stop']);
            pcntl_signal(SIGTERM, [$this, 'stop']);
        }

        while ($this->running) {
            // Simulated request cycle for Native Loop
            // In a real web context, NativeLoop might read from a socket or just process queue jobs
            
            $request = ['time' => time(), 'type' => 'simulated_native_request'];
            
            try {
                $requestHandler($request);
            } catch (Throwable $e) {
                echo "[NativeLoop] Error handling request: " . $e->getMessage() . "\n";
            }
            
            // Context Isolation: clean request scoped container services
            $container->resetScope('request');
            
            // Prevent CPU thrashing in idle simulation
            usleep(100000); // 100ms
        }
        
        /** @phpstan-ignore-next-line */
        echo "[NativeLoop] Worker gracefully stopped.\n";
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function getName(): string
    {
        return 'native';
    }
}
