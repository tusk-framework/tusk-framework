<?php

namespace Tusk\Runtime;

use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\LifecycleEvent;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;
use Tusk\Contracts\Runtime\Jobs\JobContext;
use Tusk\Runtime\Observability\LifecycleObserverInterface;
use Tusk\Runtime\Observability\RequestScopeObserverInterface;

final class LifecycleManager implements LifecycleManagerInterface
{
    private bool $applicationStarted = false;

    private bool $workerStarted = false;

    private bool $requestStarted = false;

    private bool $jobStarted = false;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?LifecycleObserverInterface $observer = null,
    ) {}

    public function applicationStart(): void
    {
        if ($this->applicationStarted) {
            return;
        }

        $this->runHook(LifecycleEvent::APPLICATION_START);
        $this->applicationStarted = true;
        $this->notifyObserver(fn () => $this->observer?->applicationStarted());
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
        $this->notifyObserver(fn () => $this->observer?->workerStarted());
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
        $this->notifyObserver(fn () => $this->observer?->requestStarted());
    }

    public function requestEnd(): void
    {
        $this->finishRequest(null, null);
    }

    public function jobStart(JobContext $job): void
    {
        if (! $this->workerStarted) {
            throw new LogicException('Cannot start a job before the worker has started.');
        }

        if ($this->jobStarted) {
            throw new LogicException('A job is already active.');
        }

        $this->jobStarted = true;
        $this->notifyObserver(fn () => $this->observer?->jobStarted($job->name(), $job->id()));

        try {
            $this->runHook(LifecycleEvent::JOB_START);
        } catch (Throwable $exception) {
            $this->jobEnd($exception);
        }
    }

    public function jobEnd(?Throwable $exception = null): void
    {
        if (! $this->jobStarted) {
            return;
        }

        $this->jobStarted = false;
        $failure = $exception;

        try {
            $this->runHook(LifecycleEvent::JOB_END);
        } catch (Throwable $cleanupException) {
            if ($failure !== null) {
                $this->reportSecondaryJobFailure($cleanupException);
            }
            $failure ??= $cleanupException;
        } finally {
            try {
                $this->container->resetScope('job');
            } catch (Throwable $cleanupException) {
                if ($failure !== null) {
                    $this->reportSecondaryJobFailure($cleanupException);
                }
                $failure ??= $cleanupException;
            }
        }

        $this->notifyObserver(fn () => $this->observer?->jobFinished($failure === null, $failure));

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function reportSecondaryJobFailure(Throwable $exception): void
    {
        try {
            $this->logger?->error('Job cleanup failed after an earlier failure.', ['exception' => $exception]);
        } catch (Throwable) {
            // Diagnostic reporting must not replace the primary failure.
        }
    }

    private function finishRequest(mixed $response, ?Throwable $handlerException): void
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

        if ($this->observer instanceof RequestScopeObserverInterface) {
            $this->notifyObserver(fn () => $this->observer->requestScopeReset($failure !== null));
        }

        $this->notifyObserver(fn () => $this->observer?->requestFinished($response, $handlerException));

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

        if ($this->jobStarted) {
            throw new LogicException('Cannot stop a worker while a job is active.');
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

        $this->notifyObserver(fn () => $this->observer?->workerStopped());

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
        $this->notifyObserver(fn () => $this->observer?->applicationStopped());
    }

    public function wrap(callable $handler): callable
    {
        return function (...$arguments) use ($handler): mixed {
            $this->requestStart();
            $handlerException = null;
            $response = null;

            try {
                $response = $handler(...$arguments);

                return $response;
            } catch (Throwable $exception) {
                $handlerException = $exception;
                throw $exception;
            } finally {
                try {
                    $this->finishRequest($response, $handlerException);
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

    private function notifyObserver(callable $callback): void
    {
        if ($this->observer === null) {
            return;
        }

        try {
            $callback();
        } catch (Throwable $exception) {
            $this->logger?->error('Runtime observability callback failed.', ['exception' => $exception]);
        }
    }

    private function runHook(LifecycleEvent $event): void
    {
        $this->container->runLifecycleHooks($event->value);
    }
}
