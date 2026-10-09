<?php

namespace Tusk\Validation;

final readonly class ValidationResult
{
    /** @var list<Violation> */
    private array $violations;

    /** @param iterable<Violation> $violations */
    public function __construct(iterable $violations = [])
    {
        $items = [];
        foreach ($violations as $violation) {
            if (!$violation instanceof Violation) {
                throw new \InvalidArgumentException('Validation results contain only violations.');
            }
            $items[] = $violation;
        }
        $this->violations = $items;
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /** @return list<Violation> */
    public function violations(): array
    {
        return $this->violations;
    }
}
