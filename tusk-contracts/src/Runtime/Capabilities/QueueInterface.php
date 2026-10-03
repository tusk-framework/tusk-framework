<?php

namespace Tusk\Contracts\Runtime\Capabilities;

interface QueueInterface
{
    /**
     * Dispatches a message using the configured queue provider.
     *
     * @param array<string, string> $headers
     */
    public function dispatch(string $queue, string $name, string $payload, array $headers = []): QueueMessageInterface;

    /**
     * Creates a message without starting or managing a consumer.
     *
     * @param array<string, string> $headers
     */
    public function create(string $queue, string $name, string $payload, array $headers = []): QueueMessageInterface;
}
