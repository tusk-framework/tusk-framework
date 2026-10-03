<?php

namespace Tusk\Runtime;

use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\LifecycleEvent;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;

final class LifecycleManager implements LifecycleManagerInterface
{
    private bool $applicationStarted = false;

    private bool $workerStarted = false;

    private bool $requestStarted = false;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function applicationStart(): void
    {
        if ($this->applicationStarted) {
            return;
        }

        $this->runHook(LifecycleEvent::APPLICATION_START);
        $this->applicationStarted = true;
    }

    public function workerStart(): void
    {
        if ($this->workerStarted) {
            return;
        }

        if (! $this->applicationStarted) {
            throw new LogicException('Cannot start a worker before the application has started.');
        }

        $this->runHook(LifecycleEvent::WORKER_START);
        $this->workerStarted = true;
    }

    public function requestStart(): void
    {
        if (! $this->workerStarted) {
            throw new LogicException('Cannot start a request before the worker has started.');
        }

        if ($this->requestStarted) {
            throw new LogicException('A request is already active.');
        }

        $this->runHook(LifecycleEvent::REQUEST_START);
        $this->requestStarted = true;
    }

    public function requestEnd(): void
    {
        if (! $this->requestStarted) {
            return;
        }

        $this->requestStarted = false;
        $failure = null;

        try {
            $this->runHook(LifecycleEvent::REQUEST_END);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $this->container->resetScope('request');
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function workerStop(): void
    {
        if (! $this->workerStarted) {
            return;
        }

        if ($this->requestStarted) {
            throw new LogicException('Cannot stop a worker while a request is active.');
        }

        $this->workerStarted = false;
        $failure = null;

        try {
            $this->runHook(LifecycleEvent::WORKER_STOP);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $this->container->resetScope('worker');
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function applicationStop(): void
    {
        if (! $this->applicationStarted) {
            return;
        }

        if ($this->workerStarted) {
            throw new LogicException('Cannot stop the application while a worker is active.');
        }

        $this->applicationStarted = false;
        $this->runHook(LifecycleEvent::APPLICATION_STOP);
    }

    public function wrap(callable $handler): callable
    {
        return function (...$arguments) use ($handler): mixed {
            $this->requestStart();
            $handlerException = null;

            try {
                return $handler(...$arguments);
            } catch (Throwable $exception) {
                $handlerException = $exception;
                throw $exception;
            } finally {
                try {
                    $this->requestEnd();
                } catch (Throwable $cleanupException) {
                    if ($handlerException === null) {
                        throw $cleanupException;
                    }

                    $this->logger?->error(
                        'Request cleanup failed after the handler threw.',
                        ['exception' => $cleanupException, 'handler_exception' => $handlerException],
                    );
                }
            }
        };
    }

    private function runHook(LifecycleEvent $event): void
    {
        $this->container->runLifecycleHooks($event->value);
    }
}
