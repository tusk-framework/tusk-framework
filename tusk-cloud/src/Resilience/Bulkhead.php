<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;
use Tusk\Cloud\Resilience\Exception\BulkheadRejectedException;
use Tusk\Cloud\Resilience\Exception\BulkheadTimeoutException;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

/** A worker-local bounded semaphore configured by the first policy it receives. */
final class Bulkhead
{
    private int $active = 0;

    /** @var list<int> */
    private array $queue = [];

    private int $nextTicket = 0;

    private ?string $policyKey = null;

    public function __construct(private readonly ClockInterface $clock) {}

    public function run(callable $operation, OperationContext $context, BulkheadPolicy $policy): mixed
    {
        if ($context->deadline()?->isExpired($this->clock->nowMilliseconds()) === true) {
            throw new BulkheadTimeoutException('Operation deadline expired before bulkhead admission.');
        }

        $policyKey = $policy->maxConcurrent().':'.$policy->maxQueued();
        if ($this->policyKey !== null && $this->policyKey !== $policyKey) {
            throw new InvalidArgumentException('A bulkhead instance cannot be used with multiple policies.');
        }
        $this->policyKey ??= $policyKey;

        $ticket = null;

        if ($this->active < $policy->maxConcurrent() && $this->queue === []) {
            $this->active++;
        } else {
            if ($policy->maxQueued() === 0 || count($this->queue) >= $policy->maxQueued()) {
                throw new BulkheadRejectedException('Bulkhead capacity is full.');
            }

            if ($context->deadline() === null) {
                throw new BulkheadRejectedException('Queued bulkhead work requires an operation deadline.');
            }

            if ($this->nextTicket === PHP_INT_MAX) {
                throw new BulkheadRejectedException('Bulkhead queue ticket space is exhausted.');
            }

            $ticket = ++$this->nextTicket;
            $this->queue[] = $ticket;

            try {
                while (true) {
                    $now = $this->clock->nowMilliseconds();
                    $remaining = $context->deadline()->remainingMilliseconds($now);
                    if ($remaining === 0) {
                        throw new BulkheadTimeoutException('Operation deadline expired while waiting for bulkhead capacity.');
                    }

                    if ($this->isFirstTicket($ticket) && $this->active < $policy->maxConcurrent()) {
                        array_shift($this->queue);
                        $ticket = null;
                        $this->active++;
                        break;
                    }

                    $this->clock->sleepMilliseconds(min(1, $remaining));
                }
            } finally {
                if ($ticket !== null) {
                    $this->removeTicket($ticket);
                }
            }
        }

        try {
            return $operation($context);
        } finally {
            $this->active--;
        }
    }

    private function removeTicket(int $ticket): void
    {
        $position = array_search($ticket, $this->queue, true);
        if ($position !== false) {
            array_splice($this->queue, $position, 1);
        }
    }

    private function isFirstTicket(int $ticket): bool
    {
        foreach ($this->queue as $queuedTicket) {
            return $queuedTicket === $ticket;
        }

        return false;
    }
}
