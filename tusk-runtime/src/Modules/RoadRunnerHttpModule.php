<?php

declare(strict_types=1);

namespace Tusk\Runtime\Modules;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\WorkerInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;

final class RoadRunnerHttpModule implements RuntimeAdapterInterface
{
    private bool $running = false;

    private ?WorkerInterface $worker = null;

    /**
     * @param  Closure(): Worker|null  $workerFactory
     */
    public function __construct(
        private readonly ?Closure $workerFactory = null,
        private readonly ?PSR7WorkerInterface $channel = null,
    ) {}

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        try {
            $psr7 = $this->channel;
            if ($psr7 === null) {
                $workerFactory = $this->workerFactory ?? static fn (): Worker => Worker::create();
                $this->worker = $workerFactory();
                $psr17Factory = new Psr17Factory;
                $psr7 = new PSR7Worker($this->worker, $psr17Factory, $psr17Factory, $psr17Factory);
            } else {
                $this->worker = $psr7->getWorker();
            }

            while ($this->running) {
                try {
                    $request = $psr7->waitRequest();
                    if ($request === null) {
                        break;
                    }

                    $response = $requestHandler($request);
                    $psr7->respond($response);
                } catch (\Throwable $exception) {
                    $psr7->getWorker()->error((string) $exception);
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
        return 'roadrunner.http';
    }

    public function name(): string
    {
        return $this->getName();
    }
}
