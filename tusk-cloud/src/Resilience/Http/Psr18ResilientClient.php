<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Http;

use Closure;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Tusk\Cloud\Resilience\BulkheadPolicy;
use Tusk\Cloud\Resilience\CircuitBreakerPolicy;
use Tusk\Cloud\Resilience\RateLimitPolicy;
use Tusk\Cloud\Resilience\ResiliencePipeline;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\RetryPolicy;
use Tusk\Contracts\Cloud\Resilience\OperationContext;
use UnexpectedValueException;

final readonly class Psr18ResilientClient implements ClientInterface
{
    private ResiliencePipeline $pipeline;

    private ?Closure $fallback;

    /**
     * @var Closure(RequestInterface): OperationContext|null
     */
    private ?Closure $contextFactory;

    public function __construct(
        private ClientInterface $client,
        ResiliencePipelineFactory $pipelineFactory,
        private string $operation,
        private RetryPolicy $retryPolicy,
        private RequestReplayPolicy $replayPolicy,
        ?CircuitBreakerPolicy $circuitBreakerPolicy = null,
        ?BulkheadPolicy $bulkheadPolicy = null,
        ?RateLimitPolicy $rateLimitPolicy = null,
        ?callable $fallback = null,
        ?callable $contextFactory = null,
    ) {
        $this->fallback = $fallback === null ? null : Closure::fromCallable($fallback);
        $this->contextFactory = $contextFactory === null ? null : Closure::fromCallable($contextFactory);

        $retryPolicy = RetryPolicy::create(
            maxAttempts: $retryPolicy->maxAttempts(),
            backoffStrategy: $retryPolicy->backoffStrategy(),
            classifier: new HttpFailureClassifier($retryPolicy->classifier()),
            allowUnsafeRetries: false,
        );

        $builder = $pipelineFactory->pipeline($operation)->withRetry($retryPolicy);
        if ($circuitBreakerPolicy !== null) {
            $builder = $builder->withCircuitBreaker($circuitBreakerPolicy);
        }
        if ($bulkheadPolicy !== null) {
            $builder = $builder->withBulkhead($bulkheadPolicy);
        }
        if ($rateLimitPolicy !== null) {
            $builder = $builder->withRateLimit($rateLimitPolicy);
        }
        if ($this->fallback !== null) {
            $builder = $builder->withFallback($this->fallback);
        }

        $this->pipeline = $builder->build();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = $request->getBody();
        $bodyStartPosition = null;
        $bodyReplayable = false;
        $bodySize = null;

        try {
            $bodySize = $body->getSize();
        } catch (Throwable) {
            // Treat an unknown size like a non-empty body and require explicit replay opt-in.
        }

        try {
            $bodyReplayPermitted = $bodySize === 0 || $this->replayPolicy->allowsBodyReplay();
            if ($bodyReplayPermitted && $body->isSeekable()) {
                $position = $body->tell();
                if ($position >= 0) {
                    $bodyStartPosition = $position;
                    $bodyReplayable = true;
                }
            }
        } catch (Throwable) {
            // The request may still be sent once, but cannot safely be replayed.
        }

        $idempotentMethod = $this->replayPolicy->isIdempotentMethod($request->getMethod());
        $idempotencyKeys = $request->getHeader($this->replayPolicy->idempotencyKeyHeader());
        $hasIdempotencyKey = count($idempotencyKeys) === 1 && trim($idempotencyKeys[0]) !== '';
        $requestReplayAllowed = $bodyReplayable && (
            $idempotentMethod
            || ($this->retryPolicy->allowUnsafeRetries() && $hasIdempotencyKey)
        );

        $baseContext = $this->contextFactory === null
            ? OperationContext::create($this->operation)
            : ($this->contextFactory)($request);

        if (! $baseContext instanceof OperationContext) {
            throw new InvalidArgumentException('The HTTP context factory must return an OperationContext.');
        }

        $retryAllowed = $requestReplayAllowed
            && ($this->contextFactory === null || $baseContext->retryAllowed());

        $context = OperationContext::create(
            operation: $baseContext->operation(),
            deadline: $baseContext->deadline(),
            retryAllowed: $retryAllowed,
            metadata: $baseContext->metadata(),
            cancellationToken: $baseContext->cancellationToken(),
        );

        $attempt = 0;
        $send = function (OperationContext $attemptContext) use ($request, $body, $bodyStartPosition, &$attempt): ResponseInterface {
            if ($attempt > 0 && $bodyStartPosition !== null) {
                $body->seek($bodyStartPosition);
            }

            $attempt++;
            $response = $this->client->sendRequest($request);
            if (HttpFailureClassifier::isRetryableStatus($response->getStatusCode())) {
                throw new RetryableResponseException($response);
            }

            return $response;
        };

        try {
            $response = $this->pipeline->run($send, $context);
        } catch (RetryableResponseException $failure) {
            return $failure->response();
        }

        if (! $response instanceof ResponseInterface) {
            throw new UnexpectedValueException('The resilience fallback must return a PSR-7 response.');
        }

        return $response;
    }
}
