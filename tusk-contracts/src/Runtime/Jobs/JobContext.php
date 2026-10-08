<?php

namespace Tusk\Contracts\Runtime\Jobs;

use JsonException;

final class JobContext
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $id,
        private readonly string $queue,
        private readonly string $name,
        private readonly string $payload,
        private readonly array $headers,
    ) {}

    public function id(): string { return $this->id; }

    public function queue(): string { return $this->queue; }

    public function name(): string { return $this->name; }

    public function payload(): string { return $this->payload; }

    /** @return array<string, string> */
    public function headers(): array { return $this->headers; }

    /** @return array<string, mixed> */
    public function jsonPayload(): array
    {
        try {
            $decoded = json_decode($this->payload, false, 512, JSON_THROW_ON_ERROR);
            if (! is_object($decoded)) {
                throw new JobPayloadException('Job payload must be a JSON object.');
            }

            return json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JobPayloadException('Job payload must be valid JSON object.', 0, $exception);
        }
    }
}
