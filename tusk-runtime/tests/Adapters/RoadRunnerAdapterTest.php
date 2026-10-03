<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;

final class RoadRunnerAdapterTest extends TestCase
{
    public function test_it_identifies_as_roadrunner_and_can_be_stopped_before_start(): void
    {
        $adapter = new RoadRunnerAdapter();

        $adapter->stop();

        self::assertSame('roadrunner', $adapter->getName());
    }
}
