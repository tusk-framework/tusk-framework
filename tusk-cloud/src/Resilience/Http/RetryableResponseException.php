<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Http;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class RetryableResponseException extends RuntimeException
{
    public function __construct(private readonly ResponseInterface $response)
    {
        parent::__construct(sprintf('HTTP request returned retryable status %d.', $response->getStatusCode()));
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }
}
