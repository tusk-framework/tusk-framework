<?php

namespace Tusk\Runtime\Tests;

use PHPUnit\Framework\TestCase;

final class AdapterLifecycleParityTest extends TestCase
{
    public function test_roadrunner_transport_does_not_reset_application_scopes_or_use_ndjson_stdio(): void
    {
        $roadrunner = file_get_contents(__DIR__.'/../src/Adapters/RoadRunnerAdapter.php');

        self::assertIsString($roadrunner);
        self::assertStringNotContainsString('resetScope(', $roadrunner);
        self::assertStringNotContainsString('STDIN', $roadrunner);
        self::assertStringNotContainsString('STDOUT', $roadrunner);
        self::assertStringNotContainsString('NDJSON', $roadrunner);
    }

    public function test_legacy_transport_source_files_are_removed(): void
    {
        self::assertFileDoesNotExist(__DIR__.'/../src/Adapters/NativeLoopAdapter.php');
        self::assertFileDoesNotExist(__DIR__.'/../src/Adapters/NdjsonRequestFactory.php');
        self::assertFileDoesNotExist(__DIR__.'/../src/Adapters/SwooleAdapter.php');
    }
}
