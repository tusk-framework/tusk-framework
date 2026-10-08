<?php

namespace Tusk\Runtime\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Tusk\Contracts\Runtime\Capabilities\JobTaskInterface;
use Tusk\Contracts\Runtime\Capabilities\QueueMessageInterface;
use Tusk\Contracts\Runtime\Jobs\JobContext;
use Tusk\Contracts\Runtime\Jobs\JobHandlerInterface;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Jobs\JobAttemptException;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Runtime\Jobs\JobProcessor;
use Tusk\Runtime\Jobs\JobRetryConfiguration;

final class JobProcessorTest extends TestCase
{
    public function test_success_resolves_registered_handler_and_acknowledges_after_handling(): void
    {
        $events = [];
        $task = new ProcessorTask;
        $task->shareEventsWith($events);
        $handler = new ProcessorHandler($events);
        $lifecycle = $this->lifecycle($events);
        $this->processor($handler, $lifecycle)->process($task);

        self::assertSame(['start', 'handle', 'ack', 'end'], $events);
        self::assertSame(['x-trace' => 'abc'], $handler->context?->headers());
    }

    public function test_first_failure_retries_with_configured_delay_and_retry_success_acks(): void
    {
        $events = [];
        $handler = new ProcessorHandler($events, new RuntimeException('secret'));
        $processor = $this->processor($handler, $this->lifecycle($events));
        $first = new ProcessorTask;
        $processor->process($first);
        self::assertSame(['retry:1'], $first->events);

        $handler->failure = null;
        $second = new ProcessorTask(attempt: 2);
        $processor->process($second);
        self::assertSame(['ack'], $second->events);
    }

    public function test_exhausted_attempt_fails_once(): void
    {
        $events = [];
        $task = new ProcessorTask(attempt: 3);
        $this->processor(new ProcessorHandler($events, new RuntimeException('secret')), $this->lifecycle($events))->process($task);
        self::assertCount(1, $task->events);
        self::assertStringStartsWith('fail:', $task->events[0]);
        self::assertStringNotContainsString('secret', $task->events[0]);
    }

    public function test_poison_payload_unknown_handler_and_malformed_attempt_fail_without_retry(): void
    {
        foreach ([['payload' => '[]'], ['payload' => '{'], ['name' => 'unknown'], ['attemptError' => true]] as $overrides) {
            $events = [];
            $task = new ProcessorTask(...$overrides);
            $this->processor(new ProcessorHandler($events), $this->lifecycle($events))->process($task);
            self::assertCount(1, $task->events);
            self::assertStringStartsWith('fail:', $task->events[0]);
            self::assertNotContains('handle', $events);
        }
    }

    public function test_transport_errors_propagate(): void
    {
        $events = [];
        $ackFailure = new RuntimeException('ack transport');
        $ackTask = new ProcessorTask;
        $ackTask->transportFailure = 'ack';
        $ackTask->transportException = $ackFailure;
        $this->expectExceptionIdentity($ackFailure, fn () => $this->processor(new ProcessorHandler($events), $this->lifecycle($events))->process($ackTask));
        self::assertSame([], $ackTask->events);

        foreach ([['retry', 1], ['fail', 3]] as [$operation, $attempt]) {
            $events = [];
            $handlerFailure = new RuntimeException('handler');
            $transportFailure = new RuntimeException($operation.' transport');
            $task = new ProcessorTask(attempt: $attempt);
            $task->transportFailure = $operation;
            $task->transportException = $transportFailure;
            $this->expectExceptionIdentity($transportFailure, fn () => $this->processor(new ProcessorHandler($events, $handlerFailure), $this->lifecycle($events))->process($task));
            self::assertSame([], $task->events);
            self::assertSame(['start', 'handle', 'end'], $events);
        }
    }

    public function test_job_end_rethrow_of_original_is_swallowed_only_after_successful_disposition(): void
    {
        $events = [];
        $failure = new RuntimeException('handler');
        $lifecycle = $this->lifecycle($events, true);
        $task = new ProcessorTask;
        $this->processor(new ProcessorHandler($events, $failure), $lifecycle)->process($task);
        self::assertSame(['retry:1'], $task->events);
    }

    public function test_job_end_rethrow_of_original_is_swallowed_after_successful_terminal_failure(): void
    {
        $events = [];
        $failure = new RuntimeException('handler');
        $task = new ProcessorTask(attempt: 3);
        $this->processor(new ProcessorHandler($events, $failure), $this->lifecycle($events, true))->process($task);
        self::assertSame(['fail:Tusk job failed'], $task->events);
    }

    public function test_distinct_cleanup_failure_preserves_original_handler_exception_identity_after_retry(): void
    {
        $events = [];
        $handlerFailure = new RuntimeException('handler');
        $cleanupFailure = new RuntimeException('cleanup');
        $task = new ProcessorTask;
        try {
            $this->processor(new ProcessorHandler($events, $handlerFailure), $this->lifecycle($events, false, $cleanupFailure))->process($task);
            self::fail('Expected handler failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($handlerFailure, $exception);
        }
        self::assertSame(['retry:1'], $task->events);
    }

    public function test_distinct_cleanup_failure_preserves_original_handler_exception_identity_after_terminal_failure(): void
    {
        $events = [];
        $handlerFailure = new RuntimeException('handler');
        $cleanupFailure = new RuntimeException('cleanup');
        $task = new ProcessorTask(attempt: 3);
        try {
            $this->processor(new ProcessorHandler($events, $handlerFailure), $this->lifecycle($events, false, $cleanupFailure))->process($task);
            self::fail('Expected handler failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($handlerFailure, $exception);
        }
        self::assertSame(['fail:Tusk job failed'], $task->events);
    }

    public function test_cleanup_failure_after_failed_disposition_preserves_original_handler_exception_identity(): void
    {
        foreach ([['retry', 1], ['fail', 3]] as [$operation, $attempt]) {
            $events = [];
            $handlerFailure = new RuntimeException('handler');
            $cleanupFailure = new RuntimeException('cleanup');
            $task = new ProcessorTask(attempt: $attempt);
            $task->transportFailure = $operation;
            try {
                $this->processor(new ProcessorHandler($events, $handlerFailure), $this->lifecycle($events, false, $cleanupFailure))->process($task);
                self::fail('Expected handler failure.');
            } catch (RuntimeException $exception) {
                self::assertSame($handlerFailure, $exception);
            }
            self::assertSame(1, $task->dispositionCalls);
            self::assertSame([], $task->events);
            self::assertSame(['start', 'handle', 'end'], $events);
        }
    }

    public function test_cleanup_failure_after_failed_retry_preserves_transport_exception_identity(): void
    {
        $events = [];
        $transportFailure = new RuntimeException('retry transport');
        $task = new ProcessorTask;
        $task->transportFailure = 'retry';
        $task->transportException = $transportFailure;
        $this->expectExceptionIdentity($transportFailure, fn () => $this->processor(
            new ProcessorHandler($events, new RuntimeException('handler')),
            $this->lifecycle($events, false, new RuntimeException('cleanup')),
        )->process($task));

        self::assertSame(1, $task->dispositionCalls);
        self::assertSame([], $task->events);
        self::assertSame(['start', 'handle', 'end'], $events);
    }

    public function test_cleanup_failure_after_failed_terminal_failure_preserves_transport_exception_identity(): void
    {
        $events = [];
        $transportFailure = new RuntimeException('fail transport');
        $task = new ProcessorTask(attempt: 3);
        $task->transportFailure = 'fail';
        $task->transportException = $transportFailure;
        $this->expectExceptionIdentity($transportFailure, fn () => $this->processor(
            new ProcessorHandler($events, new RuntimeException('handler')),
            $this->lifecycle($events, false, new RuntimeException('cleanup')),
        )->process($task));

        self::assertSame(1, $task->dispositionCalls);
        self::assertSame([], $task->events);
        self::assertSame(['start', 'handle', 'end'], $events);
    }

    public function test_cleanup_failure_after_failed_ack_preserves_transport_exception_identity(): void
    {
        $events = [];
        $transportFailure = new RuntimeException('transport');
        $task = new ProcessorTask;
        $task->shareEventsWith($events);
        $task->transportFailure = 'ack';
        $task->transportException = $transportFailure;
        try {
            $this->processor(new ProcessorHandler($events), $this->lifecycle($events, false, new RuntimeException('cleanup')))->process($task);
            self::fail('Expected transport failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($transportFailure, $exception);
        }
        self::assertSame(['start', 'handle', 'end'], $events);
        self::assertSame(1, $task->dispositionCalls);
        self::assertSame([], $task->events);
    }

    public function test_cleanup_failure_after_ack_propagates(): void
    {
        $events = [];
        $lifecycle = $this->lifecycle($events, false, new RuntimeException('cleanup'));
        $this->expectExceptionMessage('cleanup');
        $this->processor(new ProcessorHandler($events), $lifecycle)->process(new ProcessorTask);
    }

    private function processor(ProcessorHandler $handler, LifecycleManagerInterface $lifecycle): JobProcessor
    {
        $container = new Container;
        $container->instance(ProcessorHandler::class, $handler);
        return new JobProcessor($container, new JobHandlerRegistry(['welcome' => ProcessorHandler::class]), $lifecycle, JobRetryConfiguration::fromArray([]));
    }

    private function lifecycle(array &$events, bool $rethrow = false, ?Throwable $cleanupFailure = null): LifecycleManagerInterface
    {
        $lifecycle = $this->createMock(LifecycleManagerInterface::class);
        $lifecycle->method('jobStart')->willReturnCallback(static function (JobContext $context) use (&$events): void { $events[] = 'start'; });
        $lifecycle->method('jobEnd')->willReturnCallback(static function (?Throwable $error) use (&$events, $rethrow, $cleanupFailure): void {
            $events[] = 'end';
            if ($cleanupFailure !== null) { throw $cleanupFailure; }
            if ($rethrow && $error !== null) { throw $error; }
        });
        return $lifecycle;
    }

    private function expectExceptionIdentity(Throwable $expected, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected failure.');
        } catch (Throwable $exception) {
            self::assertSame($expected, $exception);
        }
    }
}

final class ProcessorHandler implements JobHandlerInterface
{
    public ?JobContext $context = null;

    public function __construct(private array &$events, public ?Throwable $failure = null) {}

    public function handle(JobContext $job): void
    {
        $this->events[] = 'handle';
        $this->context = $job;
        if ($this->failure !== null) { throw $this->failure; }
    }
}

final class ProcessorTask implements JobTaskInterface
{
    public array $events = [];
    public int $dispositionCalls = 0;
    public ?string $transportFailure = null;
    public ?Throwable $transportException = null;

    private array $sharedEvents = [];

    public function __construct(private string $payload = '{}', private string $name = 'welcome', private int $attempt = 1, private bool $attemptError = false) {}

    public function shareEventsWith(array &$events): void
    {
        $this->sharedEvents =& $events;
    }

    public function message(): QueueMessageInterface
    {
        return new \Tusk\Runtime\RoadRunner\RoadRunnerJobMessage('job-1', 'emails', $this->name, $this->payload, ['x-trace' => 'abc']);
    }

    public function attempt(): int
    {
        if ($this->attemptError) { throw new JobAttemptException('Invalid attempt.'); }
        return $this->attempt;
    }

    public function acknowledge(): void { $this->record('ack'); }
    public function retry(?int $delaySeconds = null): void { $this->record('retry:'.$delaySeconds); }
    public function fail(string $reason): void { $this->record('fail:'.$reason); }

    private function record(string $operation): void
    {
        ++$this->dispositionCalls;
        if ($this->transportFailure !== null && str_starts_with($operation, $this->transportFailure)) {
            throw $this->transportException ?? new RuntimeException('transport');
        }
        $this->events[] = $operation;
        $this->sharedEvents[] = $operation;
    }
}
