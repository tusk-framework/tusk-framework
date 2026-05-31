<?php

namespace Tusk\Contracts\Events;

interface QueueableEventInterface
{
    /**
     * Determines the name of the queue the event should be dispatched to.
     * Return null to use the default queue.
     */
    public function getQueueName(): ?string;

    /**
     * The number of seconds to delay the event's processing.
     * Return 0 to process immediately.
     */
    public function getDelay(): int;
}
