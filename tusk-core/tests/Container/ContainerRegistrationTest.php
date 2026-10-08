<?php

namespace Tusk\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Attributes\AsJob;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Jobs\JobContext;
use Tusk\Contracts\Runtime\Jobs\JobHandlerInterface;
use Tusk\Core\Container\Container;

interface TestCapabilityInterface {}

final class TestCapability implements TestCapabilityInterface {}

#[AsJob('test.job')]
final class RegisteredJob implements JobHandlerInterface
{
    public function handle(JobContext $job): void {}
}

#[AsJob('invalid.job')]
final class InvalidRegisteredJob {}

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

    public function test_as_job_registration_uses_job_scope(): void
    {
        $container = new Container;
        $container->register(RegisteredJob::class);

        self::assertTrue($container->has(RegisteredJob::class));
        self::assertSame('job', $container->export()['scopes'][RegisteredJob::class]);
        self::assertInstanceOf(RegisteredJob::class, $container->get(RegisteredJob::class));
    }

    public function test_as_job_registration_requires_handler_contract(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Container)->register(InvalidRegisteredJob::class);
    }

    private function bindCapability(ContainerInterface $container, object $capability): void
    {
        $container->instance(TestCapabilityInterface::class, $capability);
    }
}
