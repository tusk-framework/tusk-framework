<?php

namespace Tusk\Runtime\Adapters;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Throwable;

class RoadRunnerAdapter implements RuntimeAdapterInterface
{
    private bool $running = false;

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        $this->running = true;
        
        // This is a stub for the actual Spiral\RoadRunner\Worker implementation
        echo "[RoadRunner] Adapter started. Waiting for RPC/IPC messages...\n";

        // Real implementation would use:
        // $worker = \Spiral\RoadRunner\Worker::create();
        // $psr7 = new \Spiral\RoadRunner\Http\PSR7Worker($worker, $psr17Factory, $psr17Factory, $psr17Factory);
        
        while ($this->running) {
            // $request = $psr7->waitRequest();
            $request = null; // Simulation
            
            /** @phpstan-ignore-next-line */
            if ($request === null) {
                // In RoadRunner, null payload might mean stop or disconnect
                break;
            }

            try {
                $response = $requestHandler($request);
                // $psr7->respond($response);
            } catch (Throwable $e) {
                // $psr7->getWorker()->error((string)$e);
            }
            
            // Context Isolation: clean request scoped container services
            $container->resetScope('request');
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function getName(): string
    {
        return 'roadrunner';
    }
}
