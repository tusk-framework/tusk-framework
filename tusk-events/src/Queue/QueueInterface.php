<?php

namespace Tusk\Events\Queue;

interface QueueInterface
{
    public function push(string $jobClass, array $payload = []): void;

    public function pop(): ?array;

    public function complete(int|string $jobId): void;

    public function fail(int|string $jobId, \Throwable $e): void;
}
