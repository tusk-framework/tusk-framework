<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Tusk\Contracts\Runtime\Capabilities\JobTaskInterface;
use Tusk\Contracts\Runtime\Capabilities\QueueMessageInterface;
use Tusk\Runtime\Jobs\JobAttemptException;

final class RoadRunnerJobTask implements JobTaskInterface
{
    public function __construct(private ReceivedTaskInterface $task) {}

    public function message(): QueueMessageInterface
    {
        return RoadRunnerJobMessage::fromReceivedTask($this->task);
    }

    public function attempt(): int
    {
        $values = [];
        $present = false;
        foreach ($this->task->getHeaders() as $name => $headerValues) {
            if (strcasecmp($name, 'x-tusk-attempt') === 0) {
                $present = true;
                array_push($values, ...$headerValues);
            }
        }
        if (! $present) {
            return 1;
        }
        if (count($values) !== 1 || ! is_string($values[0]) || ! preg_match('/^[0-9]+$/D', $values[0])) {
            throw new JobAttemptException('Invalid internal job attempt header.');
        }
        $number = ltrim($values[0], '0');
        $maximum = (string) PHP_INT_MAX;
        if ($number === '' || strlen($number) > strlen($maximum) || (strlen($number) === strlen($maximum) && strcmp($number, $maximum) > 0)) {
            throw new JobAttemptException('Invalid internal job attempt header.');
        }
        return (int) $number;
    }

    public function acknowledge(): void
    {
        $this->task->ack();
    }

    public function retry(?int $delaySeconds = null): void
    {
        $attempt = $this->attempt();
        if ($attempt === PHP_INT_MAX) {
            throw new JobAttemptException('Job attempt exceeds supported range.');
        }
        foreach (array_keys($this->task->getHeaders()) as $name) {
            if (strcasecmp($name, 'x-tusk-attempt') === 0) {
                $this->task = $this->task->withoutHeader($name);
            }
        }
        $this->task = $this->task->withHeader('x-tusk-attempt', (string) ($attempt + 1));
        if ($delaySeconds !== null) {
            $this->task = $this->task->withDelay($delaySeconds);
        }

        $this->task->requeue('Tusk job retry');
    }

    public function fail(string $reason): void
    {
        $this->task->nack($reason, redelivery: false);
    }
}
