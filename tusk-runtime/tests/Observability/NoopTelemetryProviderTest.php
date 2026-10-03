<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Observability;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Contracts\Observability\SpanInterface;
use Tusk\Runtime\Observability\NoopTelemetryProvider;

final class NoopTelemetryProviderTest extends TestCase
{
    public function test_noop_provider_accepts_all_operations_without_throwing(): void
    {
        $provider = new NoopTelemetryProvider;
        $span = $provider->startSpan('test.span', ['safe' => true]);

        self::assertInstanceOf(SpanInterface::class, $span);

        $span->setAttribute('status', 200);
        $span->recordException(new RuntimeException('must not escape'));
        $span->setStatus('error', 'ignored');
        $span->end();

        $provider->increment('requests.total', 2, ['route' => '/']);
        $provider->observe('request.duration', 0.25, ['route' => '/']);
        $provider->flush();
        $provider->shutdown();

        self::assertTrue(true);
    }
}
