<?php

namespace Tests\Unit\Runtime\Adapters;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;
use Tusk\Runtime\Adapters\SwooleAdapter;

class UnsupportedRuntimeAdapterTest extends TestCase
{
    public function test_roadrunner_adapter_fails_loudly_until_implemented(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('RoadRunnerAdapter is not implemented yet.');

        (new RoadRunnerAdapter())->start($this->container(), static fn () => null);
    }

    public function test_swoole_adapter_fails_loudly_until_implemented(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SwooleAdapter is not implemented yet.');

        (new SwooleAdapter())->start($this->container(), static fn () => null);
    }

    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): object
            {
                throw new \RuntimeException('Not used in this test.');
            }

            public function has(string $id): bool
            {
                return false;
            }

            public function runHooks(string $attributeClass): void {}

            public function resetScope(string $scope): void {}
        };
    }
}
