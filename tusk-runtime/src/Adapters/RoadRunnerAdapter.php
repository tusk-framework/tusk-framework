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
    private ?Worker $worker = null;

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        try {
            $this->worker = Worker::create();
            $psr17Factory = new Psr17Factory();
            $psr7 = new PSR7Worker($this->worker, $psr17Factory, $psr17Factory, $psr17Factory);

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
                    gc_collect_cycles();
                }
            }
        } finally {
            $this->worker = null;
            $this->running = false;
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $this->worker?->stop();
    }

    public function getName(): string
    {
        return 'roadrunner';
    }
}
