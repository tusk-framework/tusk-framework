<?php

namespace Tusk\Runtime\Tests\Capabilities;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Runtime\Capabilities\CapabilityProviderInterface;
use Tusk\Contracts\Runtime\Capabilities\CapabilityUnavailableException;
use Tusk\Runtime\Capabilities\CapabilityRegistry;

final class CapabilityRegistryTest extends TestCase
{
    public function test_it_resolves_and_caches_a_capability_from_a_provider(): void
    {
        $provider = new RecordingCapabilityProvider;
        $registry = new CapabilityRegistry([$provider]);

        self::assertTrue($registry->has('example'));
        self::assertSame($provider->capability, $registry->get('example'));
        self::assertSame($provider->capability, $registry->get('example'));
        self::assertSame(1, $provider->provideCalls);
    }

    public function test_it_rejects_an_undeclared_capability_with_remediation(): void
    {
        $registry = new CapabilityRegistry([]);

        $this->expectException(CapabilityUnavailableException::class);
        $this->expectExceptionMessage('Register a provider or enable the matching runtime plugin');

        $registry->get('missing');
    }
}

final class RecordingCapabilityProvider implements CapabilityProviderInterface
{
    public object $capability;

    public int $provideCalls = 0;

    public function __construct()
    {
        $this->capability = new \stdClass;
    }

    public function supports(string $name): bool
    {
        return $name === 'example';
    }

    public function provide(string $name): object
    {
        $this->provideCalls++;

        return $this->capability;
    }
}
