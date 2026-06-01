<?php

namespace Tusk\Runtime\Adapters;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;

use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\Http\PSR7Worker;

class RoadRunnerAdapter implements RuntimeAdapterInterface
{
    private bool $running = false;

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        $this->running = true;

        $worker = Worker::create();
        $psr17Factory = new Psr17Factory();
        $psr7 = new PSR7Worker($worker, $psr17Factory, $psr17Factory, $psr17Factory);

        while ($this->running) {
            try {
                $request = $psr7->waitRequest();
                if ($request === null) {
                    break;
                }

                $response = $requestHandler($request);
                $psr7->respond($response);
            } catch (\Throwable $e) {
                $psr7->getWorker()->error((string) $e);
            } finally {
                // Garbage Collection and Service Isolation per request
                $container->resetScope('request');
            }
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
