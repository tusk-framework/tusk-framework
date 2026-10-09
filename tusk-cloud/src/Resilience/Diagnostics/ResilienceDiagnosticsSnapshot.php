<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Diagnostics;

final readonly class ResilienceDiagnosticsSnapshot
{
    /**
     * @param  list<array{name: string, features: list<string>}>  $policies
     * @param  list<array{name: string, state: string}>  $circuits
     */
    public function __construct(
        private array $policies,
        private array $circuits,
        private bool $reportable = true,
    ) {}

    /** @return list<array{name: string, features: list<string>}> */
    public function policies(): array
    {
        return $this->policies;
    }

    /** @return list<array{name: string, state: string}> */
    public function circuits(): array
    {
        return $this->circuits;
    }

    public function reportable(): bool
    {
        return $this->reportable;
    }

    /** @return array{policies: list<array{name: string, features: list<string>}>, circuits: list<array{name: string, state: string}>} */
    public function toArray(): array
    {
        return ['policies' => $this->policies, 'circuits' => $this->circuits];
    }
}
