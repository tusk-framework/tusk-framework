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
        $options = $this->options($headers);
        $roadRunnerQueue = $this->queue($queue);
        $prepared = $roadRunnerQueue->create($name, $payload, $options);
        $queued = $roadRunnerQueue->dispatch($prepared);

        return RoadRunnerJobMessage::fromQueuedTask($queued);
    }

    public function create(string $queue, string $name, string $payload, array $headers = []): QueueMessageInterface
    {
        $options = $this->options($headers);
        $prepared = $this->queue($queue)->create($name, $payload, $options);

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
            if (strcasecmp($name, 'x-tusk-attempt') === 0) {
                throw new \InvalidArgumentException('The x-tusk-attempt header is reserved for the framework.');
            }
            $options = $options->withHeader($name, $value);
        }

        return $options;
    }
}
