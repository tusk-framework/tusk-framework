<?php

namespace Tusk\Cloud\Resilience\Exception;

class CircuitOpenException extends \RuntimeException
{
    public function __construct(string $message = "Circuit is OPEN. Request rejected.")
    {
        parent::__construct($message);
    }
}
