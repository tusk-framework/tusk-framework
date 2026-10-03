<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RoadRunner\Lock\LockInterface as RoadRunnerLockInterface;
use RuntimeException;
use Throwable;
use Tusk\Contracts\Runtime\Capabilities\LockInterface;

final class RoadRunnerLock implements LockInterface
{
    /** @var array<string, string> */
    private array $lockIds = [];

    public function __construct(
        private readonly RoadRunnerLockInterface $lock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function acquire(string $name, ?int $ttlSeconds = null): bool
    {
        $id = $this->lock->lock($name, null, $ttlSeconds ?? 0);

        if ($id === false) {
            return false;
        }

        $this->lockIds[$name] = $id;

        return true;
    }

    public function release(string $name): void
    {
        $id = $this->lockIds[$name] ?? null;

        if ($id === null) {
            return;
        }

        if (! $this->lock->release($name, $id)) {
            throw new RuntimeException(sprintf('RoadRunner could not release lock "%s".', $name));
        }

        unset($this->lockIds[$name]);
    }

    public function withLock(string $name, callable $handler, ?int $ttlSeconds = null): mixed
    {
        if (! $this->acquire($name, $ttlSeconds)) {
            throw new RuntimeException(sprintf('RoadRunner could not acquire lock "%s".', $name));
        }

        $handlerFailure = null;

        try {
            return $handler();
        } catch (Throwable $exception) {
            $handlerFailure = $exception;
            throw $exception;
        } finally {
            try {
                $this->release($name);
            } catch (Throwable $releaseFailure) {
                if ($handlerFailure === null) {
                    throw $releaseFailure;
                }

                try {
                    $this->logger->error(
                        'RoadRunner lock cleanup failed after the handler threw.',
                        ['exception' => $releaseFailure, 'handler_exception' => $handlerFailure],
                    );
                } catch (Throwable) {
                    // Preserve the application failure even if cleanup logging fails.
                }
            }
        }
    }
}
