<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Jobs\JobsInterface;
use Spiral\RoadRunner\Jobs\OptionsInterface;
use Spiral\RoadRunner\Jobs\QueueInterface as RoadRunnerQueueInterface;
use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;
use Spiral\RoadRunner\Jobs\Task\ProvidesHeadersInterface;
use Spiral\RoadRunner\Jobs\Task\QueuedTaskInterface;
use Tusk\Runtime\RoadRunner\RoadRunnerJobs;

final class RoadRunnerJobsTest extends TestCase
{
    public function test_dispatch_preserves_queue_payload_and_headers(): void
    {
        $jobs = $this->createMock(JobsInterface::class);
        $queue = $this->createMock(RoadRunnerQueueInterface::class);
        $prepared = $this->createMock(PreparedTaskInterface::class);
        $queued = $this->createMock(QueuedTaskInterface::class);

        $jobs->expects(self::once())->method('connect')->with('emails')->willReturn($queue);
        $queue->expects(self::once())->method('create')->with(
            'welcome',
            'payload',
            self::callback(static function (OptionsInterface $options): bool {
                self::assertInstanceOf(ProvidesHeadersInterface::class, $options);

                return $options->getHeaders() === ['x-trace' => ['abc']];
            }),
        )->willReturn($prepared);
        $queue->expects(self::once())->method('dispatch')->with($prepared)->willReturn($queued);
        $queued->method('getId')->willReturn('job-1');
        $queued->method('getPipeline')->willReturn('emails');
        $queued->method('getName')->willReturn('welcome');
        $queued->method('getPayload')->willReturn('payload');
        $queued->method('getHeaders')->willReturn(['x-trace' => ['abc']]);

        $message = (new RoadRunnerJobs($jobs))->dispatch('emails', 'welcome', 'payload', ['x-trace' => 'abc']);

        self::assertSame('job-1', $message->id());
        self::assertSame('emails', $message->queue());
        self::assertSame('welcome', $message->name());
        self::assertSame('payload', $message->payload());
        self::assertSame(['x-trace' => 'abc'], $message->headers());
    }

    public function test_create_only_prepares_a_message_and_does_not_start_a_consumer(): void
    {
        $jobs = $this->createMock(JobsInterface::class);
        $queue = $this->createMock(RoadRunnerQueueInterface::class);
        $prepared = $this->createMock(PreparedTaskInterface::class);

        $jobs->expects(self::once())->method('connect')->with('emails')->willReturn($queue);
        $queue->expects(self::once())->method('create')->willReturn($prepared);
        $queue->expects(self::never())->method('dispatch');
        $prepared->method('getName')->willReturn('welcome');
        $prepared->method('getPayload')->willReturn('payload');
        $prepared->method('getHeaders')->willReturn([]);

        $message = (new RoadRunnerJobs($jobs))->create('emails', 'welcome', 'payload');

        self::assertSame('', $message->id());
        self::assertSame('emails', $message->queue());
    }
}
