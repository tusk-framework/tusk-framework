<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Spiral\RoadRunner\Jobs\JobsInterface;
use Spiral\RoadRunner\Jobs\Options;
use Spiral\RoadRunner\Jobs\QueueInterface;
use Tusk\Contracts\Runtime\Capabilities\QueueInterface as TuskQueueInterface;
use Tusk\Contracts\Runtime\Capabilities\QueueMessageInterface;

final class RoadRunnerJobs implements TuskQueueInterface
{
    public function __construct(private readonly JobsInterface $jobs) {}

    public function dispatch(string $queue, string $name, string $payload, array $headers = []): QueueMessageInterface
    {
        $roadRunnerQueue = $this->queue($queue);
        $prepared = $roadRunnerQueue->create($name, $payload, $this->options($headers));
        $queued = $roadRunnerQueue->dispatch($prepared);

        return RoadRunnerJobMessage::fromQueuedTask($queued);
    }

    public function create(string $queue, string $name, string $payload, array $headers = []): QueueMessageInterface
    {
        $prepared = $this->queue($queue)->create($name, $payload, $this->options($headers));

        return RoadRunnerJobMessage::fromPreparedTask($queue, $prepared);
    }

    private function queue(string $name): QueueInterface
    {
        return $this->jobs->connect($name);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function options(array $headers): Options
    {
        $options = new Options;

        foreach ($headers as $name => $value) {
            $options = $options->withHeader($name, $value);
        }

        return $options;
    }
}
