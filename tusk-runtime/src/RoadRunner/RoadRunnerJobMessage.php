<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;
use Spiral\RoadRunner\Jobs\Task\QueuedTaskInterface;
use Spiral\RoadRunner\Jobs\Task\TaskInterface;
use Tusk\Contracts\Runtime\Capabilities\QueueMessageInterface;

final class RoadRunnerJobMessage implements QueueMessageInterface
{
    /**
     * @param  array<string, string>  $taskHeaders
     */
    public function __construct(
        private readonly string $taskId,
        private readonly string $queueName,
        private readonly string $taskName,
        private readonly string $taskPayload,
        private readonly array $taskHeaders,
    ) {}

    public static function fromQueuedTask(QueuedTaskInterface $task): self
    {
        return self::fromTask($task->getId(), $task->getPipeline(), $task);
    }

    public static function fromPreparedTask(string $queue, PreparedTaskInterface $task): self
    {
        return self::fromTask('', $queue, $task);
    }

    public function id(): string
    {
        return $this->taskId;
    }

    public function queue(): string
    {
        return $this->queueName;
    }

    public function name(): string
    {
        return $this->taskName;
    }

    public function payload(): string
    {
        return $this->taskPayload;
    }

    public function headers(): array
    {
        return $this->taskHeaders;
    }

    private static function fromTask(string $id, string $queue, TaskInterface $task): self
    {
        $headers = [];

        foreach ($task->getHeaders() as $name => $values) {
            $headers[$name] = implode(',', $values);
        }

        return new self($id, $queue, $task->getName(), $task->getPayload(), $headers);
    }
}
