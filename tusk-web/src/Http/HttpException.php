<?php

namespace Tusk\Web\Http;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        private int $statusCode,
        string $message
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
