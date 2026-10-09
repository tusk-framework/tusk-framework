<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Controller;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tusk\Cloud\Controller\HealthController;
use Tusk\Cloud\Health\HealthCheckRegistry;
use Tusk\Cloud\Health\ResilienceConfigurationHealthCheck;
use Tusk\Web\Http\Request;

final class HealthControllerTest extends TestCase
{
    public function test_readiness_reports_validated_configuration(): void
    {
        $registry = new HealthCheckRegistry;
        $registry->register(new ResilienceConfigurationHealthCheck);
        $response = (new HealthController($registry))->ready(new Request(new ServerRequest('GET', '/health/ready')));
        $report = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('UP', $report['status']);
        self::assertSame('UP', $report['checks']['resilience_configuration']);
    }

    public function test_liveness_stays_up_without_running_health_checks(): void
    {
        $registry = new ExplodingHealthCheckRegistry;
        $response = (new HealthController($registry))->live(new Request(new ServerRequest('GET', '/health/live')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'UP'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, $registry->calls);
    }
}

final class ExplodingHealthCheckRegistry extends HealthCheckRegistry
{
    public int $calls = 0;

    public function runChecks(): array
    {
        $this->calls++;
        throw new \RuntimeException('Liveness must not execute readiness checks.');
    }
}
