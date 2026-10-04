<?php

declare(strict_types=1);

namespace Tusk\Contracts\Observability;

use Throwable;

interface SpanInterface
{
    public function setAttribute(string $name, string|int|float|bool|null $value): void;

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function recordException(Throwable $exception, array $attributes = []): void;

    public function setStatus(string $status, ?string $description = null): void;

    public function end(?float $endTime = null): void;
}
