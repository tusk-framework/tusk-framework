<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience\Http;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tusk\Cloud\Resilience\Http\HttpFailureClassifier;
use Tusk\Cloud\Resilience\Http\RetryableResponseException;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class HttpFailureClassifierTest extends TestCase
{
    #[DataProvider('retryableStatusCodes')]
    public function test_transient_http_statuses_are_retryable(int $status): void
    {
        $classifier = new HttpFailureClassifier;
        $failure = new RetryableResponseException(new Response($status));

        self::assertTrue($classifier->classify($failure, OperationContext::create('http.request'))->isRetryable());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function retryableStatusCodes(): iterable
    {
        yield 'request timeout' => [408];
        yield 'too early' => [425];
        yield 'rate limited' => [429];
        yield 'server error' => [500];
        yield 'last server error' => [599];
    }

    #[DataProvider('terminalStatusCodes')]
    public function test_non_transient_statuses_are_terminal(int $status): void
    {
        $classifier = new HttpFailureClassifier;
        $failure = new RetryableResponseException(new Response($status));

        self::assertFalse($classifier->classify($failure, OperationContext::create('http.request'))->isRetryable());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function terminalStatusCodes(): iterable
    {
        yield 'bad request' => [400];
        yield 'not found' => [404];
        yield 'last client error' => [499];
        yield 'outside server error range' => [600];
    }

    public function test_network_exceptions_are_retryable(): void
    {
        $request = new Request('GET', 'https://example.test');
        $failure = new class($request) extends RuntimeException implements NetworkExceptionInterface
        {
            public function __construct(private readonly RequestInterface $request)
            {
                parent::__construct('network unavailable');
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };

        self::assertTrue((new HttpFailureClassifier)->classify($failure, OperationContext::create('http.request'))->isRetryable());
    }

    public function test_ordinary_request_exceptions_are_terminal(): void
    {
        $request = new Request('GET', 'https://example.test');
        $failure = new class($request) extends RuntimeException implements RequestExceptionInterface
        {
            public function __construct(private readonly RequestInterface $request)
            {
                parent::__construct('invalid request');
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };

        self::assertFalse((new HttpFailureClassifier)->classify($failure, OperationContext::create('http.request'))->isRetryable());
    }

    public function test_unknown_failures_are_delegated_to_the_inner_classifier(): void
    {
        $expected = FailureDecision::terminal(circuitFailure: false);
        $inner = new class($expected) implements FailureClassifierInterface
        {
            public function __construct(private readonly FailureDecision $decision) {}

            public function classify(\Throwable $failure, OperationContext $context): FailureDecision
            {
                return $this->decision;
            }
        };

        $actual = (new HttpFailureClassifier($inner))->classify(
            new RuntimeException('application failure'),
            OperationContext::create('http.request'),
        );

        self::assertSame($expected, $actual);
    }
}
