<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RoadRunner\Lock\LockInterface as RoadRunnerLockInterface;
use Tusk\Runtime\RoadRunner\RoadRunnerLock;

final class RoadRunnerLockTest extends TestCase
{
    public function test_it_releases_a_lock_after_a_successful_callback(): void
    {
        $lock = $this->createMock(RoadRunnerLockInterface::class);
        $lock->expects(self::once())->method('lock')->with('resource', null, 30, 0)->willReturn('lock-id');
        $lock->expects(self::once())->method('release')->with('resource', 'lock-id')->willReturn(true);

        $result = (new RoadRunnerLock($lock, new NullLogger))->withLock('resource', static fn (): string => 'done', 30);

        self::assertSame('done', $result);
    }

    public function test_callback_failure_wins_when_release_also_fails(): void
    {
        $lock = $this->createMock(RoadRunnerLockInterface::class);
        $lock->method('lock')->willReturn('lock-id');
        $lock->method('release')->willThrowException(new \RuntimeException('release failure'));

        $this->expectExceptionMessage('callback failure');

        (new RoadRunnerLock($lock, new NullLogger))->withLock(
            'resource',
            static function (): never {
                throw new \RuntimeException('callback failure');
            },
        );
    }
}
