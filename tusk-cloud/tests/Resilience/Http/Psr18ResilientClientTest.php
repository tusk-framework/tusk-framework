<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience\Http;

use Closure;
use Fiber;
use GuzzleHttp\Psr7\PumpStream;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tusk\Cloud\Resilience\Backoff\FixedBackoff;
use Tusk\Cloud\Resilience\BulkheadPolicy;
use Tusk\Cloud\Resilience\CircuitBreakerPolicy;
use Tusk\Cloud\Resilience\Deadline;
use Tusk\Cloud\Resilience\Exception\BulkheadRejectedException;
use Tusk\Cloud\Resilience\Exception\CircuitOpenException;
use Tusk\Cloud\Resilience\Exception\OperationCancelledException;
use Tusk\Cloud\Resilience\Exception\ResilienceDeadlineExceededException;
use Tusk\Cloud\Resilience\Http\Psr18ResilientClient;
use Tusk\Cloud\Resilience\Http\RequestReplayPolicy;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\RateLimitPolicy;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\RetryPolicy;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Contracts\Cloud\Resilience\CancellationTokenInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class Psr18ResilientClientTest extends TestCase
{
    public function test_retries_a_transient_response_for_an_idempotent_request(): void
    {
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static function () use (&$responses): ResponseInterface {
            return array_shift($responses);
        });
        $client = $this->resilientClient($inner, retryPolicy: $this->retryPolicy(2));

        $response = $client->sendRequest(new Request('GET', 'https://example.test'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $inner->calls);
    }

    public function test_returns_the_exact_last_transient_response_after_retry_exhaustion(): void
    {
        $first = new Response(503);
        $last = new Response(503, [], 'last body');
        $responses = [$first, $last];
        $inner = new RecordingPsr18Client(static function () use (&$responses): ResponseInterface {
            return array_shift($responses);
        });
        $client = $this->resilientClient($inner, retryPolicy: $this->retryPolicy(2));

        $response = $client->sendRequest(new Request('GET', 'https://example.test'));

        self::assertSame($last, $response);
        self::assertSame(2, $inner->calls);
    }

    public function test_returns_an_ordinary_client_error_without_retrying(): void
    {
        $response = new Response(404);
        $inner = new RecordingPsr18Client(static fn (): ResponseInterface => $response);
        $client = $this->resilientClient($inner, retryPolicy: $this->retryPolicy(3));

        self::assertSame($response, $client->sendRequest(new Request('GET', 'https://example.test')));
        self::assertSame(1, $inner->calls);
    }

    public function test_retries_a_network_exception_for_an_idempotent_request(): void
    {
        $request = new Request('GET', 'https://example.test');
        $inner = new RecordingPsr18Client(function (RequestInterface $request, int $attempt): ResponseInterface {
            if ($attempt === 1) {
                throw $this->networkException($request);
            }

            return new Response(200);
        });
        $client = $this->resilientClient($inner, retryPolicy: $this->retryPolicy(2));

        self::assertSame(200, $client->sendRequest($request)->getStatusCode());
        self::assertSame(2, $inner->calls);
    }

    public function test_does_not_retry_an_unsafe_method_without_idempotency_key(): void
    {
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static fn (): ResponseInterface => array_shift($responses));
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(2, allowUnsafeRetries: true),
            replayPolicy: RequestReplayPolicy::create(allowBodyReplay: true),
        );

        self::assertSame(503, $client->sendRequest(new Request('POST', 'https://example.test', [], Stream::create('payload')))->getStatusCode());
        self::assertSame(1, $inner->calls);
    }

    public function test_does_not_retry_an_unsafe_method_when_policy_does_not_authorize_it(): void
    {
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static fn (): ResponseInterface => array_shift($responses));
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(2),
            replayPolicy: RequestReplayPolicy::create(allowBodyReplay: true),
        );
        $request = new Request('POST', 'https://example.test', ['Idempotency-Key' => 'charge-123'], Stream::create('payload'));

        self::assertSame(503, $client->sendRequest($request)->getStatusCode());
        self::assertSame(1, $inner->calls);
    }

    public function test_does_not_retry_an_unsafe_method_with_ambiguous_idempotency_keys(): void
    {
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static function () use (&$responses): ResponseInterface {
            return array_shift($responses);
        });
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(2, allowUnsafeRetries: true),
        );
        $request = new Request(
            'POST',
            'https://example.test',
            ['Idempotency-Key' => ['charge-1', 'charge-2']],
        );

        self::assertSame(503, $client->sendRequest($request)->getStatusCode());
        self::assertSame(1, $inner->calls);
    }

    public function test_context_factory_can_veto_retries_for_an_idempotent_request(): void
    {
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static function () use (&$responses): ResponseInterface {
            return array_shift($responses);
        });
        $context = OperationContext::create('http.request', retryAllowed: false);
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(2),
            contextFactory: static fn (RequestInterface $request): OperationContext => $context,
        );

        self::assertSame(503, $client->sendRequest(new Request('GET', 'https://example.test'))->getStatusCode());
        self::assertSame(1, $inner->calls);
    }

    public function test_retries_an_unsafe_method_only_with_policy_key_and_replayable_body(): void
    {
        $bodyReads = [];
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static function (RequestInterface $request) use (&$bodyReads, &$responses): ResponseInterface {
            $bodyReads[] = $request->getBody()->getContents();

            return array_shift($responses);
        });
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(2, allowUnsafeRetries: true),
            replayPolicy: RequestReplayPolicy::create(allowBodyReplay: true),
        );
        $request = new Request('POST', 'https://example.test', ['Idempotency-Key' => 'charge-123'], Stream::create('payload'));

        self::assertSame(200, $client->sendRequest($request)->getStatusCode());
        self::assertSame(2, $inner->calls);
        self::assertSame(['payload', 'payload'], $bodyReads);
    }

    public function test_does_not_replay_a_non_seekable_request_body(): void
    {
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static fn (): ResponseInterface => array_shift($responses));
        $stream = new PumpStream(static fn (int $length): string => 'payload');
        self::assertFalse($stream->isSeekable());
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(2),
            replayPolicy: RequestReplayPolicy::create(allowBodyReplay: true),
        );

        self::assertSame(503, $client->sendRequest(new Request('GET', 'https://example.test', [], $stream))->getStatusCode());
        self::assertSame(1, $inner->calls);
    }

    public function test_does_not_retry_when_an_empty_body_stream_has_no_readable_position(): void
    {
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static function () use (&$responses): ResponseInterface {
            return array_shift($responses);
        });
        $body = new PumpStream(static fn (int $length): string => '', ['size' => 0]);
        self::assertSame(0, $body->getSize());
        self::assertFalse($body->isSeekable());
        $client = $this->resilientClient($inner, retryPolicy: $this->retryPolicy(2));

        self::assertSame(503, $client->sendRequest(new Request('GET', 'https://example.test', [], $body))->getStatusCode());
        self::assertSame(1, $inner->calls);
    }

    public function test_does_not_replay_a_non_empty_body_without_explicit_body_replay_policy(): void
    {
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static fn (): ResponseInterface => array_shift($responses));
        $client = $this->resilientClient($inner, retryPolicy: $this->retryPolicy(2));
        $request = new Request('PUT', 'https://example.test', [], Stream::create('payload'));

        self::assertSame(503, $client->sendRequest($request)->getStatusCode());
        self::assertSame(1, $inner->calls);
    }

    public function test_restores_the_original_body_position_before_each_replay(): void
    {
        $bodyReads = [];
        $responses = [new Response(503), new Response(200)];
        $inner = new RecordingPsr18Client(static function (RequestInterface $request) use (&$bodyReads, &$responses): ResponseInterface {
            $bodyReads[] = $request->getBody()->getContents();

            return array_shift($responses);
        });
        $body = Stream::create('payload');
        $body->seek(2);
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(2, allowUnsafeRetries: true),
            replayPolicy: RequestReplayPolicy::create(allowBodyReplay: true),
        );
        $request = new Request('POST', 'https://example.test', ['Idempotency-Key' => 'charge-123'], $body);

        self::assertSame(200, $client->sendRequest($request)->getStatusCode());
        self::assertSame(['yload', 'yload'], $bodyReads);
    }

    public function test_propagates_terminal_psr18_request_exception_unchanged(): void
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
        $inner = new RecordingPsr18Client(static function () use ($failure): never {
            throw $failure;
        });
        $client = $this->resilientClient($inner, retryPolicy: $this->retryPolicy(3));

        try {
            $client->sendRequest($request);
            self::fail('The request exception was swallowed.');
        } catch (RequestExceptionInterface $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $inner->calls);
    }

    public function test_expired_deadline_prevents_dispatch(): void
    {
        $inner = new RecordingPsr18Client(static fn (): ResponseInterface => new Response(200));
        $context = OperationContext::create('http.request', deadline: new Deadline(0));
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(2),
            contextFactory: static fn (RequestInterface $request): OperationContext => $context,
        );

        try {
            $client->sendRequest(new Request('GET', 'https://example.test'));
            self::fail('An expired operation was dispatched.');
        } catch (ResilienceDeadlineExceededException) {
            self::assertSame(0, $inner->calls);
        }
    }

    public function test_cancellation_prevents_dispatch(): void
    {
        $inner = new RecordingPsr18Client(static fn (): ResponseInterface => new Response(200));
        $context = OperationContext::create(
            'http.request',
            cancellationToken: new class implements CancellationTokenInterface
            {
                public function isCancellationRequested(): bool
                {
                    return true;
                }
            },
        );
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(2),
            contextFactory: static fn (RequestInterface $request): OperationContext => $context,
        );

        try {
            $client->sendRequest(new Request('GET', 'https://example.test'));
            self::fail('A cancelled operation was dispatched.');
        } catch (OperationCancelledException) {
            self::assertSame(0, $inner->calls);
        }
    }

    public function test_circuit_breaker_opens_after_a_transient_response_and_rejects_next_call(): void
    {
        $inner = new RecordingPsr18Client(static fn (): ResponseInterface => new Response(503));
        $client = $this->resilientClient(
            $inner,
            retryPolicy: $this->retryPolicy(1),
            circuitBreakerPolicy: CircuitBreakerPolicy::create(failureThreshold: 1),
        );

        self::assertSame(503, $client->sendRequest(new Request('GET', 'https://example.test'))->getStatusCode());

        try {
            $client->sendRequest(new Request('GET', 'https://example.test'));
            self::fail('An open circuit allowed another request.');
        } catch (CircuitOpenException) {
            self::assertSame(1, $inner->calls);
        }
    }

    public function test_bulkhead_rejects_concurrent_call_and_releases_after_completion(): void
    {
        $inner = new RecordingPsr18Client(static function (RequestInterface $request, int $attempt): ResponseInterface {
            if ($attempt === 1) {
                Fiber::suspend();
            }

            return new Response(200);
        });
        $client = $this->resilientClient(
            $inner,
            bulkheadPolicy: BulkheadPolicy::create(maxConcurrent: 1),
        );
        $fiber = new Fiber(static fn (): ResponseInterface => $client->sendRequest(new Request('GET', 'https://example.test')));
        $fiber->start();
        self::assertTrue($fiber->isSuspended());

        try {
            $client->sendRequest(new Request('GET', 'https://example.test'));
            self::fail('Bulkhead admitted a concurrent request above its limit.');
        } catch (BulkheadRejectedException) {
            self::assertSame(1, $inner->calls);
        }

        $fiber->resume();
        self::assertSame(200, $fiber->getReturn()->getStatusCode());
        self::assertSame(200, $client->sendRequest(new Request('GET', 'https://example.test'))->getStatusCode());
        self::assertSame(2, $inner->calls);
    }

    public function test_rate_limit_state_is_shared_across_calls_on_the_same_client(): void
    {
        $clock = new FakeClock;
        $inner = new RecordingPsr18Client(static fn (): ResponseInterface => new Response(200));
        $client = $this->resilientClient(
            $inner,
            rateLimitPolicy: RateLimitPolicy::create(capacity: 1, refillPerSecond: 1, maxWaitMilliseconds: 1_000),
            clock: $clock,
        );

        $client->sendRequest(new Request('GET', 'https://example.test'));
        $client->sendRequest(new Request('GET', 'https://example.test'));

        self::assertSame(1_000, $clock->nowMilliseconds());
        self::assertSame(2, $inner->calls);
    }

    public function test_explicit_fallback_must_return_a_response(): void
    {
        $failure = new LogicException('application failure');
        $inner = new RecordingPsr18Client(static function () use ($failure): never {
            throw $failure;
        });
        $client = $this->resilientClient(
            $inner,
            fallback: static fn (): ResponseInterface => new Response(200, [], 'fallback'),
        );

        $response = $client->sendRequest(new Request('GET', 'https://example.test'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fallback', (string) $response->getBody());
    }

    public function test_rejects_non_response_fallback_result(): void
    {
        $inner = new RecordingPsr18Client(static function (): never {
            throw new LogicException('application failure');
        });
        $client = $this->resilientClient($inner, fallback: static fn (): string => 'not a response');

        $this->expectException(\UnexpectedValueException::class);
        $client->sendRequest(new Request('GET', 'https://example.test'));
    }

    public function test_replay_policy_defaults_to_standard_idempotent_methods_and_no_body_replay(): void
    {
        $policy = RequestReplayPolicy::create();

        foreach (['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'DELETE'] as $method) {
            self::assertTrue($policy->isIdempotentMethod($method));
        }

        self::assertFalse($policy->isIdempotentMethod('POST'));
        self::assertFalse($policy->isIdempotentMethod('get'));
        self::assertSame('Idempotency-Key', $policy->idempotencyKeyHeader());
        self::assertFalse($policy->allowsBodyReplay());
    }

    public function test_replay_policy_allows_explicit_body_replay(): void
    {
        self::assertTrue(RequestReplayPolicy::create(allowBodyReplay: true)->allowsBodyReplay());
    }

    public function test_replay_policy_rejects_blank_idempotency_header_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestReplayPolicy::create(idempotencyKeyHeader: '  ');
    }

    public function test_replay_policy_rejects_blank_method_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestReplayPolicy::create(idempotentMethods: ['GET', '  ']);
    }

    private function retryPolicy(int $maxAttempts = 1, bool $allowUnsafeRetries = false): RetryPolicy
    {
        return RetryPolicy::create(
            maxAttempts: $maxAttempts,
            backoffStrategy: FixedBackoff::create(),
            allowUnsafeRetries: $allowUnsafeRetries,
        );
    }

    private function resilientClient(
        RecordingPsr18Client $inner,
        ?RetryPolicy $retryPolicy = null,
        ?RequestReplayPolicy $replayPolicy = null,
        ?CircuitBreakerPolicy $circuitBreakerPolicy = null,
        ?BulkheadPolicy $bulkheadPolicy = null,
        ?RateLimitPolicy $rateLimitPolicy = null,
        ?callable $fallback = null,
        ?callable $contextFactory = null,
        ?FakeClock $clock = null,
        ?InMemoryStateStore $stateStore = null,
    ): Psr18ResilientClient {
        return new Psr18ResilientClient(
            client: $inner,
            pipelineFactory: new ResiliencePipelineFactory($clock ?? new FakeClock, $stateStore ?? new InMemoryStateStore),
            operation: 'http.request',
            retryPolicy: $retryPolicy ?? $this->retryPolicy(),
            replayPolicy: $replayPolicy ?? RequestReplayPolicy::create(),
            circuitBreakerPolicy: $circuitBreakerPolicy,
            bulkheadPolicy: $bulkheadPolicy,
            rateLimitPolicy: $rateLimitPolicy,
            fallback: $fallback,
            contextFactory: $contextFactory,
        );
    }

    private function networkException(RequestInterface $request): NetworkExceptionInterface
    {
        return new class($request) extends RuntimeException implements NetworkExceptionInterface
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
    }
}

final class RecordingPsr18Client implements ClientInterface
{
    public int $calls = 0;

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var Closure(RequestInterface, int): ResponseInterface */
    private readonly Closure $responder;

    public function __construct(callable $responder)
    {
        $this->responder = Closure::fromCallable($responder);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;
        $this->requests[] = $request;

        return ($this->responder)($request, $this->calls);
    }
}
