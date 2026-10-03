<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Runtime\Capabilities\CapabilityRegistryInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\RuntimeConfiguration;
use Tusk\Runtime\RuntimeModuleFactory;

final class RuntimeBootstrapIntegrationTest extends TestCase
{
    public function test_a_legacy_bootstrap_returning_null_keeps_the_default_runtime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tusk_bootstrap_');
        self::assertNotFalse($path);
        file_put_contents($path, "<?php\nreturn null;\n");

        try {
            $result = require $path;
            $configuration = RuntimeConfiguration::fromArray(is_array($result) ? $result : []);

            self::assertSame('roadrunner', $configuration->adapter());
            self::assertSame(['http'], $configuration->modules());
        } finally {
            unlink($path);
        }
    }

    public function test_declared_capability_modules_register_the_provider_neutral_registry(): void
    {
        $configuration = RuntimeConfiguration::fromArray([
            'runtime' => ['modules' => ['capabilities.kv', 'capabilities.metrics', 'grpc']],
        ]);
        $modules = RuntimeModuleFactory::fromConfiguration($configuration);
        $container = new Container;

        $modules->register($container);

        self::assertTrue($container->has(CapabilityRegistryInterface::class));
    }
}
