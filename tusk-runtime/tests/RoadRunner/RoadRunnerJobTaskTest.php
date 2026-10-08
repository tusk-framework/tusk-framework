<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Jobs\Queue\Driver;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Tusk\Runtime\Jobs\JobAttemptException;
use Tusk\Runtime\RoadRunner\RoadRunnerJobTask;

final class RoadRunnerJobTaskTest extends TestCase
{
    public function test_metadata_headers_and_successful_ack(): void
    {
        $task = new RecordingReceivedTask(['X-Trace' => ['one', 'two'], 'X-Tusk-Attempt' => ['2']]);
        $adapter = new RoadRunnerJobTask($task);

        self::assertSame('task-1', $adapter->message()->id());
        self::assertSame('emails', $adapter->message()->queue());
        self::assertSame('welcome', $adapter->message()->name());
        self::assertSame('payload', $adapter->message()->payload());
        self::assertSame(['X-Trace' => 'one,two'], $adapter->message()->headers());
        self::assertSame(2, $adapter->attempt());
        $adapter->acknowledge();
        self::assertSame(['ack'], $task->recorder->events);
    }

    public function test_retry_increments_once_and_terminal_failure_disables_redelivery(): void
    {
        $task = new RecordingReceivedTask;
        $adapter = new RoadRunnerJobTask($task);

        self::assertSame(1, $adapter->attempt());
        $adapter->retry(5);
        $adapter->fail('safe reason');

        self::assertSame(
            ['header:x-tusk-attempt:2', 'delay:5', 'requeue:Tusk job retry:x-tusk-attempt=2:delay=5', 'nack:safe reason:false'],
            $task->recorder->events,
        );
    }

    public function test_retry_replaces_mixed_case_attempt_header(): void
    {
        $task = new RecordingReceivedTask(['X-Tusk-Attempt' => ['2']]);
        $adapter = new RoadRunnerJobTask($task);
        $adapter->retry();
        self::assertSame(3, $adapter->attempt());
    }

    public function test_malformed_attempt_is_rejected(): void
    {
        $assertions = 0;
        foreach (['0', '-1', '2x', '', '999999999999999999999999999999'] as $value) {
            $adapter = new RoadRunnerJobTask(new RecordingReceivedTask(['x-tusk-attempt' => [$value]]));
            try {
                $adapter->attempt();
                self::fail('Expected invalid attempt.');
            } catch (JobAttemptException) {
                // Expected.
                $assertions++;
            }
        }
        self::assertSame(5, $assertions);
        foreach ([[], ['2', '3']] as $values) {
            $adapter = new RoadRunnerJobTask(new RecordingReceivedTask(['x-tusk-attempt' => $values]));
            $this->expectInvalidAttempt($adapter);
        }
    }

    public function test_positive_decimal_with_leading_zeroes_is_parsed(): void
    {
        $adapter = new RoadRunnerJobTask(new RecordingReceivedTask(['x-tusk-attempt' => ['002']]));
        self::assertSame(2, $adapter->attempt());
    }

    private function expectInvalidAttempt(RoadRunnerJobTask $adapter): void
    {
        try {
            $adapter->attempt();
            self::fail('Expected invalid attempt.');
        } catch (JobAttemptException) {
            // Expected.
        }
    }
}

final class RecordingReceivedTask implements ReceivedTaskInterface
{
    public function __construct(private array $headers = [], public readonly TaskOperationRecorder $recorder = new TaskOperationRecorder) {}

    public function ack(): void
    {
        $this->recorder->events[] = 'ack';
    }

    public function requeue(string|\Stringable|\Throwable $message): void
    {
        $attempt = $this->getHeaderLine('x-tusk-attempt');
        $delay = $this->recorder->delay;
        $this->recorder->events[] = 'requeue:'.(string) $message.':x-tusk-attempt='.$attempt.':delay='.($delay ?? 'none');
    }

    public function nack(string|\Stringable|\Throwable $message, bool $redelivery = false): void
    {
        $this->recorder->events[] = 'nack:'.(string) $message.':'.($redelivery ? 'true' : 'false');
    }

    public function withDelay(int $seconds): self
    {
        $this->recorder->events[] = 'delay:'.$seconds;
        $this->recorder->delay = $seconds;

        return clone $this;
    }

    public function getId(): string
    {
        return 'task-1';
    }

    public function getPipeline(): string
    {
        return 'emails';
    }

    public function getName(): string
    {
        return 'welcome';
    }

    public function getPayload(): string
    {
        return 'payload';
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        foreach ($this->headers as $key => $_) {
            if (strcasecmp($key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function getHeader(string $name): array
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values;
            }
        }

        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(',', $this->getHeader($name));
    }

    public function complete(): void {}

    public function fail(string|\Stringable|\Throwable $error, bool $requeue = false): void {}

    public function isCompleted(): bool
    {
        return false;
    }

    public function isSuccessful(): bool
    {
        return false;
    }

    public function isFails(): bool
    {
        return false;
    }

    public function getQueue(): string
    {
        return 'emails';
    }

    public function getDriver(): Driver
    {
        return Driver::Memory;
    }

    public function withHeader(string $name, string|iterable $value): self
    {
        $this->recorder->events[] = 'header:'.$name.':'.(is_string($value) ? $value : implode(',', (array) $value));
        $copy = clone $this;
        $copy->headers[$name] = is_string($value) ? [$value] : (array) $value;

        return $copy;
    }

    public function withAddedHeader(string $name, string|iterable $value): self
    {
        return $this;
    }

    public function withoutHeader(string $name): self
    {
        $copy = clone $this;
        foreach (array_keys($copy->headers) as $headerName) {
            if (strcasecmp($headerName, $name) === 0) {
                unset($copy->headers[$headerName]);
            }
        }

        return $copy;
    }
}

final class TaskOperationRecorder
{
    /** @var list<string> */
    public array $events = [];

    public ?int $delay = null;
}
