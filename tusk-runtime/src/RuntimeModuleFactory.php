<?php

declare(strict_types=1);

namespace Tusk\Runtime;

use Tusk\Runtime\Modules\RoadRunnerCapabilitiesModule;
use Tusk\Runtime\Modules\RoadRunnerGrpcModule;
use Tusk\Runtime\Modules\RuntimeModuleRegistry;

final class RuntimeModuleFactory
{
    public static function fromConfiguration(RuntimeConfiguration $configuration): RuntimeModuleRegistry
    {
        $modules = [];
        $capabilities = [];

        foreach ($configuration->modules() as $module) {
            if (str_starts_with($module, 'capabilities.')) {
                $capabilities[] = substr($module, strlen('capabilities.'));

                continue;
            }

            if ($module === 'grpc') {
                $modules[] = new RoadRunnerGrpcModule;
            }
        }

        if ($capabilities !== []) {
            array_unshift($modules, new RoadRunnerCapabilitiesModule($capabilities));
        }

        return new RuntimeModuleRegistry($modules);
    }
}
