<?php

namespace Tusk\Runtime\Tests;

use PHPUnit\Framework\TestCase;

final class AdapterLifecycleParityTest extends TestCase
{
    public function test_transports_do_not_reset_application_scopes(): void
    {
        $roadrunner = file_get_contents(__DIR__.'/../src/Adapters/RoadRunnerAdapter.php');
        $native = file_get_contents(__DIR__.'/../src/Adapters/NativeLoopAdapter.php');

        self::assertIsString($roadrunner);
        self::assertIsString($native);
        self::assertStringNotContainsString('resetScope(', $roadrunner);
        self::assertStringNotContainsString('resetScope(', $native);
    }
}
