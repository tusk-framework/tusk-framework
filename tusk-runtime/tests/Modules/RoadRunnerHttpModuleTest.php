<?php

namespace Tusk\Runtime\Tests\Modules;

use PHPUnit\Framework\TestCase;
use Tusk\Runtime\Modules\RoadRunnerHttpModule;

final class RoadRunnerHttpModuleTest extends TestCase
{
    public function test_it_preserves_the_roadrunner_http_runtime_identity_and_stop_contract(): void
    {
        $module = new RoadRunnerHttpModule;

        $module->stop();

        self::assertSame('roadrunner.http', $module->name());
    }
}
