<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Tusk\Contracts\Runtime\Capabilities\JobTaskInterface;

final class RoadRunnerJobTask implements JobTaskInterface
{
    public function __construct(private ReceivedTaskInterface $task) {}

    public function acknowledge(): void
    {
        $this->task->ack();
    }

    public function retry(?int $delaySeconds = null): void
    {
        if ($delaySeconds !== null) {
            $this->task = $this->task->withDelay($delaySeconds);
        }

        $this->task->requeue('Tusk job retry');
    }
}
