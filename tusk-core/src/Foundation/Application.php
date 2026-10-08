<?php

namespace Tusk\Foundation;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tusk\Contracts\Core\ApplicationInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;
use Tusk\Runtime\Kernel;
use Tusk\Runtime\Jobs\JobRetryConfiguration;
use Tusk\Runtime\Modules\RuntimeModuleRegistry;
use Tusk\Web\HttpKernel;

class Application implements ApplicationInterface
{
    private ?Kernel $runtimeKernel = null;

    public function __construct(
        private string $basePath,
        private Container $container,
        private HttpKernel $httpKernel,
        private ?RoadRunnerAdapter $roadRunnerAdapter = null,
        ?RuntimeModuleRegistry $runtimeModules = null,
        private readonly ?string $executionMode = null,
        private readonly ?JobRetryConfiguration $jobRetry = null,
    ) {
        $this->runtimeModules = $runtimeModules ?? new RuntimeModuleRegistry;
        $this->runtimeModules->register($this->container);
        if ($this->jobRetry !== null) {
            $this->container->instance(JobRetryConfiguration::class, $this->jobRetry);
        }
    }

    private readonly RuntimeModuleRegistry $runtimeModules;

    public static function configure(string $basePath): ApplicationBuilder
    {
        return new ApplicationBuilder($basePath);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->httpKernel->handle($request);
    }

    public function runWorker(): void
    {
        $this->runtimeKernel ??= new Kernel(
            $this->container,
            $this->roadRunnerAdapter ?? new RoadRunnerAdapter(mode: $this->executionMode ?? strtolower(trim((string) \Tusk\Config\Env::get('RR_MODE', 'http')))),
            modules: $this->runtimeModules,
        );
        $this->runtimeKernel->run([$this, 'handle']);
    }

    public function start(): void
    {
        $this->runWorker();
    }

    public function shutdown(): void
    {
        $this->runtimeKernel?->shutdown();
    }

    public function executionMode(): string
    {
        return $this->executionMode ?? 'http';
    }

    public function jobRetry(): ?JobRetryConfiguration
    {
        return $this->jobRetry;
    }
}
