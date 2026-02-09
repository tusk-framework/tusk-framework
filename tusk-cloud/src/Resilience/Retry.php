<?php

namespace Tusk\Cloud\Resilience;

class Retry
{
    public function __construct(
        private int $maxAttempts = 3,
        private int $delayMilliseconds = 100,
        private array $retryableExceptions = [\Throwable::class]
    ) {
    }

    public function execute(callable $action): mixed
    {
        $attempts = 0;

        while (true) {
            try {
                return $action();
            } catch (\Throwable $e) {
                $attempts++;

                if ($attempts >= $this->maxAttempts || !$this->isRetryable($e)) {
                    throw $e;
                }

                usleep($this->delayMilliseconds * 1000);
            }
        }
    }

    private function isRetryable(\Throwable $e): bool
    {
        foreach ($this->retryableExceptions as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }
        return false;
    }
}
