<?php

namespace Tusk\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Core\Container\Container;

interface TestCapabilityInterface {}

final class TestCapability implements TestCapabilityInterface {}

final class ContainerRegistrationTest extends TestCase
{
    public function test_interface_can_bind_an_existing_capability_instance(): void
    {
        self::assertTrue(method_exists(ContainerInterface::class, 'instance'));

        $container = new Container;
        $capability = new TestCapability;

        $this->bindCapability($container, $capability);

        self::assertSame($capability, $container->get(TestCapabilityInterface::class));
    }

    private function bindCapability(ContainerInterface $container, object $capability): void
    {
        $container->instance(TestCapabilityInterface::class, $capability);
    }
}
