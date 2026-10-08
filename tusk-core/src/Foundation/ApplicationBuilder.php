<?php

namespace Tusk\Foundation;

use DirectoryIterator;
use RuntimeException;
use Tusk\Config\Repository;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Core\ApplicationInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Runtime\Jobs\JobHandlerScanner;
use Tusk\Runtime\RuntimeConfiguration;
use Tusk\Runtime\RuntimeModuleFactory;
use Tusk\Web\HttpKernel;
use Tusk\Web\Router\Router;
use Tusk\Web\Router\RouterInterface;

class ApplicationBuilder
{
    /** @var list<string> */
    private array $routes = [];

    /** @var list<string> */
    private array $providers = [];

    /** @var list<string> */
    private array $jobs = [];

    public function __construct(private string $basePath) {}

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function withRouting(?string $web = null, ?string $api = null): self
    {
        foreach ([$web, $api] as $path) {
            if ($path !== null) {
                $this->routes[] = $path;
            }
        }

        return $this;
    }

    public function withProviders(array $providers): self
    {
        foreach ($providers as $provider) {
            if (! is_string($provider)) {
                throw new RuntimeException('Provider paths must be strings.');
            }
            $this->providers[] = $provider;
        }

        return $this;
    }

    public function withJobs(string ...$directories): self
    {
        array_push($this->jobs, ...$directories);

        return $this;
    }

    public function create(): Application
    {
        $container = new Container;
        $router = new Router;
        $values = $this->loadConfig();
        if ($this->jobs !== []) {
            if (! array_key_exists('runtime', $values)) {
                $values['runtime'] = [];
            }
            if (is_array($values['runtime'])) {
                if (! array_key_exists('modules', $values['runtime'])) {
                    $values['runtime']['modules'] = ['http'];
                }
                if (is_array($values['runtime']['modules'])
                    && ! in_array('capabilities.jobs', $values['runtime']['modules'], true)) {
                    $values['runtime']['modules'][] = 'capabilities.jobs';
                }
            }
        }
        $config = new Repository($values);
        $runtimeConfiguration = RuntimeConfiguration::fromArray($values);
        $runtimeModules = RuntimeModuleFactory::fromConfiguration($runtimeConfiguration);
        $kernel = new HttpKernel($container, $router);
        $application = new Application(
            $this->basePath,
            $container,
            $kernel,
            runtimeModules: $runtimeModules,
            executionMode: $runtimeConfiguration->executionMode(),
            jobRetry: $runtimeConfiguration->jobRetry(),
        );

        foreach ([
            Container::class => $container,
            ContainerInterface::class => $container,
            Router::class => $router,
            RouterInterface::class => $router,
            Repository::class => $config,
            RuntimeConfiguration::class => $runtimeConfiguration,
            HttpKernel::class => $kernel,
            Application::class => $application,
            ApplicationInterface::class => $application,
        ] as $id => $instance) {
            $container->instance($id, $instance);
        }

        $root = realpath($this->basePath);
        $paths = array_map(
            fn (string $directory): string => preg_match('~^(?:[a-zA-Z]:[/\\\\]|/)~', $directory)
                ? $directory
                : ($root ?: $this->basePath).DIRECTORY_SEPARATOR.$directory,
            $this->jobs,
        );
        $registry = (new JobHandlerScanner)->scan($paths);
        $container->instance(JobHandlerRegistry::class, $registry);
        foreach ($registry->handlers() as $handlerClass) {
            $container->register($handlerClass);
        }

        foreach ($this->providers as $path) {
            $provider = require $this->resolveFile($path, 'Provider');
            if (! is_callable($provider)) {
                throw new RuntimeException("Provider file must return a callable: {$path}");
            }
            $provider($container);
        }

        foreach ($this->routes as $path) {
            $route = require $this->resolveFile($path, 'Route');
            if (! is_callable($route)) {
                throw new RuntimeException("Route file must return a callable receiving Router: {$path}");
            }
            $route($router);
        }

        return $application;
    }

    private function loadConfig(): array
    {
        $directory = realpath($this->basePath.'/config');
        if ($directory === false || ! is_dir($directory)) {
            return [];
        }

        $values = [];
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php' && ! $entry->isLink()) {
                $value = require $entry->getPathname();
                if (! is_array($value)) {
                    throw new RuntimeException("Config file must return an array: {$entry->getPathname()}");
                }
                $values[$entry->getBasename('.php')] = $value;
            }
        }

        return $values;
    }

    private function resolveFile(string $path, string $kind): string
    {
        $root = realpath($this->basePath);
        $file = $root === false ? false : realpath($root.'/'.$path);
        if ($root === false || $file === false || ! is_file($file)
            || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("{$kind} file not found in application base path: {$path}");
        }

        return $file;
    }
}
