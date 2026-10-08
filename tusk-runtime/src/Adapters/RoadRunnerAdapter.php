<?php

declare(strict_types=1);

namespace Tusk\Runtime\Adapters;

use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\Jobs\Consumer;
use Spiral\RoadRunner\Jobs\ConsumerInterface;
use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\WorkerInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Tusk\Runtime\Modules\RoadRunnerHttpModule;
use Tusk\Runtime\RoadRunner\RoadRunnerJobTask;
use Tusk\Runtime\RoadRunner\RoadRunnerJobsModule;

final class RoadRunnerAdapter implements RuntimeAdapterInterface
{
    private readonly RoadRunnerHttpModule $http;

    private bool $running = false;

    private ?ConsumerInterface $consumer = null;

    private ?WorkerInterface $jobsWorker = null;

    private readonly string $executionMode;

    /** @var callable(WorkerInterface): ConsumerInterface */
    private $consumerFactory;

    /** @var callable(): WorkerInterface */
    private $workerFactory;

    /**
     * The worker channel is injectable for deterministic tests; production uses
     * the RoadRunner HTTP module and its real worker channel.
     */
    public function __construct(
        RoadRunnerHttpModule|PSR7WorkerInterface|null $runtime = null,
        string $mode = 'http',
        ?callable $consumerFactory = null,
        ?callable $workerFactory = null,
    ) {
        $this->executionMode = strtolower(trim($mode));
        if (! in_array($this->executionMode, ['http', 'jobs'], true)) {
            throw new \InvalidArgumentException('Unsupported RoadRunner execution mode. Supported RoadRunner modes: http, jobs.');
        }
        $this->workerFactory = $workerFactory ?? static fn (): WorkerInterface => Worker::create();
        $this->consumerFactory = $consumerFactory ?? static fn (WorkerInterface $worker): ConsumerInterface => new Consumer($worker);
        $this->http = $runtime instanceof RoadRunnerHttpModule
            ? $runtime
            : new RoadRunnerHttpModule(null, $runtime);
    }

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        if ($this->executionMode === 'http') {
            $this->http->start($container, $requestHandler);

            return;
        }

        if ($this->running) {
            return;
        }
        $this->running = true;
        try {
            $this->jobsWorker = ($this->workerFactory)();
            $this->consumer = ($this->consumerFactory)($this->jobsWorker);
            $module = $container->get(RoadRunnerJobsModule::class);
            if (! $module instanceof RoadRunnerJobsModule) {
                throw new \LogicException('RoadRunner Jobs module is not registered in the application container.');
            }
            while ($this->running && ($task = $this->consumer->waitTask()) !== null) {
                $module->handle(new RoadRunnerJobTask($task));
                gc_collect_cycles();
            }
        } finally {
            $this->consumer = null;
            $this->jobsWorker = null;
            $this->running = false;
        }
    }

    public function stop(): void
    {
        if ($this->executionMode === 'http') {
            $this->http->stop();

            return;
        }
        $this->jobsWorker?->stop();
        $this->running = false;
    }

    public function getName(): string
    {
        return 'roadrunner';
    }

    public function mode(): string
    {
        return $this->executionMode;
    }
}
