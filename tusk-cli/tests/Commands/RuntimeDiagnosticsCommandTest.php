<?php

declare(strict_types=1);

namespace Tusk\Cli\Tests\Commands;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Cli\Commands\RuntimeDiagnosticsCommand;
use Tusk\Contracts\Observability\WorkerDiagnosticsInterface;
use Tusk\Contracts\Observability\WorkerDiagnosticsSnapshot;

final class RuntimeDiagnosticsCommandTest extends TestCase
{
    public function test_command_has_the_documented_name_and_description(): void
    {
        $attributes = (new ReflectionClass(RuntimeDiagnosticsCommand::class))->getAttributes(AsCommand::class);
        self::assertCount(1, $attributes);

        $command = $attributes[0]->newInstance();
        self::assertSame('runtime:diagnostics', $command->name);
        self::assertSame('Show persistent runtime diagnostics', $command->description);
    }

    public function test_human_output_identifies_a_local_standalone_snapshot(): void
    {
        $tester = new CommandTester(new RuntimeDiagnosticsCommand(new FixedDiagnostics));

        $tester->execute([]);

        self::assertStringContainsString('Local runtime diagnostics snapshot', $tester->getDisplay());
        self::assertStringContainsString('Lifecycle state: standalone', $tester->getDisplay());
        self::assertStringContainsString('This is not a remote worker health check.', $tester->getDisplay());
        self::assertStringContainsString('Requests: 4 total, 1 failed', $tester->getDisplay());
    }

    public function test_json_output_is_the_snapshot_schema_without_extra_payloads(): void
    {
        $tester = new CommandTester(new RuntimeDiagnosticsCommand(new FixedDiagnostics));

        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('standalone', $data['lifecycle_state']);
        self::assertSame(4, $data['requests_total']);
        self::assertArrayNotHasKey('headers', $data);
        self::assertArrayNotHasKey('body', $data);
        self::assertSame(array_keys($data), array_keys((new FixedDiagnostics)->snapshot()->toArray()));
    }

    public function test_command_has_a_standalone_diagnostics_fallback_without_a_runtime_module(): void
    {
        $tester = new CommandTester(new RuntimeDiagnosticsCommand);

        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('standalone', $data['lifecycle_state']);
        self::assertSame('unknown', $data['runtime']);
    }
}

final class FixedDiagnostics implements WorkerDiagnosticsInterface
{
    public function snapshot(): WorkerDiagnosticsSnapshot
    {
        return new WorkerDiagnosticsSnapshot(
            workerId: 'standalone',
            runtime: 'native',
            lifecycleState: 'standalone',
            startedAt: new DateTimeImmutable('2026-10-03T12:00:00+00:00'),
            uptimeSeconds: 2.5,
            requestsTotal: 4,
            requestFailures: 1,
            jobsTotal: 0,
            jobFailures: 0,
            inFlight: 0,
            requestDurationTotalSeconds: 1.0,
            requestDurationMaxSeconds: 0.5,
            jobDurationTotalSeconds: 0.0,
            jobDurationMaxSeconds: 0.0,
            currentMemoryBytes: 100,
            peakMemoryBytes: 200,
            requestScopeResets: 4,
            cleanupAnomalies: 0,
            telemetryFailures: 0,
            lastFailureAt: null,
            lastFailureCategory: null,
            lastFailureMessage: null,
        );
    }
}
