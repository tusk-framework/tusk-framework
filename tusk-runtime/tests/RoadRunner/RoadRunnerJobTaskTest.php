<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Jobs\Queue\Driver;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Tusk\Runtime\RoadRunner\RoadRunnerJobTask;

final class RoadRunnerJobTaskTest extends TestCase
{
    public function test_it_acknowledges_and_requeues_a_received_task(): void
    {
        $task = new RecordingReceivedTask();
        $adapter = new RoadRunnerJobTask($task);

        $adapter->acknowledge();
        $adapter->retry(5);

        self::assertSame(['ack', 'delay:5', 'requeue:Tusk job retry'], $task->events);
    }
}

final class RecordingReceivedTask implements ReceivedTaskInterface
{
    /** @var list<string> */
    public array $events = [];

    public function ack(): void { $this->events[] = 'ack'; }

    public function requeue(string|\Stringable|\Throwable $message): void { $this->events[] = 'requeue:'.(string) $message; }

    public function withDelay(int $seconds): self { $this->events[] = 'delay:'.$seconds; return $this; }

    public function getId(): string { return 'task-1'; }
    public function getPipeline(): string { return 'emails'; }
    public function getName(): string { return 'welcome'; }
    public function getPayload(): string { return 'payload'; }
    public function getHeaders(): array { return []; }
    public function hasHeader(string $name): bool { return false; }
    public function getHeader(string $name): array { return []; }
    public function getHeaderLine(string $name): string { return ''; }
    public function complete(): void {}
    public function fail(string|\Stringable|\Throwable $error, bool $requeue = false): void {}
    public function isCompleted(): bool { return false; }
    public function isSuccessful(): bool { return false; }
    public function isFails(): bool { return false; }
    public function getQueue(): string { return 'emails'; }
    public function getDriver(): Driver { return Driver::Memory; }
    public function withHeader(string $name, string|iterable $value): self { return $this; }
    public function withAddedHeader(string $name, string|iterable $value): self { return $this; }
    public function withoutHeader(string $name): self { return $this; }
}
