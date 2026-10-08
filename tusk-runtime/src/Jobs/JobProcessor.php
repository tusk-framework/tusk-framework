<?php

namespace Tusk\Runtime\Jobs;

use Psr\Log\LoggerInterface;
use Throwable;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Capabilities\JobTaskInterface;
use Tusk\Contracts\Runtime\Jobs\JobContext;
use Tusk\Contracts\Runtime\Jobs\JobHandlerInterface;
use Tusk\Contracts\Runtime\Jobs\JobPayloadException;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;

final class JobProcessor
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly JobHandlerRegistry $registry,
        private readonly LifecycleManagerInterface $lifecycle,
        private readonly JobRetryConfiguration $retry,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function process(JobTaskInterface $task): void
    {
        $message = $task->message();
        $context = new JobContext($message->id(), $message->queue(), $message->name(), $message->payload(), $message->headers());
        $jobError = null;
        $failure = null;
        $dispositionSucceeded = false;
        $attempt = null;

        try {
            $this->lifecycle->jobStart($context);

            try {
                $attempt = $task->attempt();
                $context->jsonPayload();
                $handlerClass = $this->registry->handlerClass($context->name());
                $handler = $this->container->get($handlerClass);
                if (! $handler instanceof JobHandlerInterface) {
                    throw new \UnexpectedValueException('Registered job handler does not implement JobHandlerInterface.');
                }
                $handler->handle($context);
            } catch (Throwable $exception) {
                $jobError = $exception;
            }

            if ($jobError === null) {
                $task->acknowledge();
            } elseif ($jobError instanceof JobAttemptException || $jobError instanceof JobPayloadException || $jobError instanceof UnknownJobException || $attempt === null || $attempt >= $this->retry->maxAttempts()) {
                $task->fail('Tusk job failed');
                $this->logSafely('Job failed permanently.');
            } else {
                $task->retry($this->retry->delaySeconds());
                $this->logSafely('Job retry scheduled.');
            }
            $dispositionSucceeded = true;
        } catch (Throwable $exception) {
            $failure = $jobError ?? $exception;
        } finally {
            try {
                $this->lifecycle->jobEnd($jobError ?? $failure);
            } catch (Throwable $cleanupException) {
                if ($failure === null && ! ($jobError !== null && $dispositionSucceeded && $cleanupException === $jobError)) {
                    $failure = $jobError ?? $cleanupException;
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function logSafely(string $message): void
    {
        try {
            $this->logger?->error($message);
        } catch (Throwable) {
            // Logging cannot alter job disposition or exception precedence.
        }
    }
}
