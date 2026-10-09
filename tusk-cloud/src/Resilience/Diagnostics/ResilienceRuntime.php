<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Diagnostics;

use Tusk\Cloud\Resilience\Configuration\ResilienceConfiguration;
use Tusk\Cloud\Resilience\Configuration\ResiliencePipelineResolver;
use Tusk\Cloud\Resilience\ResiliencePipelineBuilder;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;

final class ResilienceRuntime
{
    public function __construct(
        private readonly ResilienceConfiguration $configuration,
        private readonly ResiliencePipelineFactory $factory,
        private readonly ResilienceDiagnosticsRegistry $registry,
    ) {}

    public function pipeline(string $name): ResiliencePipelineBuilder
    {
        $builder = ResiliencePipelineResolver::resolve($name, $this->configuration, $this->factory);
        $policy = $this->configuration->policy($name);
        if ($policy !== null) {
            $this->registry->register($policy);
        }

        return $builder;
    }

    public function diagnostics(): ResilienceDiagnosticsSnapshot
    {
        return $this->registry->snapshot();
    }
}
