<?php

namespace Tusk\Contracts\Runtime\Capabilities;

use RuntimeException;

final class CapabilityUnavailableException extends RuntimeException
{
    public function __construct(string $name, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'Capability "%s" is unavailable. Register a provider or enable the matching runtime plugin.',
                $name,
            ),
            0,
            $previous,
        );
    }
}
