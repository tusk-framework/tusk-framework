<?php

namespace Tusk\Foundation;

use FilesystemIterator;
use Psr\Http\Message\ServerRequestInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tusk\Cloud\Health\HealthCheckRegistry;
use Tusk\Cloud\Health\ResilienceConfigurationHealthCheck;
use Tusk\Cloud\Resilience\Configuration\ResilienceConfiguration;
use Tusk\Cloud\Resilience\Configuration\ResilienceConfigurationLoader;
use Tusk\Cloud\Resilience\Diagnostics\EngineResilienceReporter;
use Tusk\Cloud\Resilience\Diagnostics\ResilienceDiagnosticsRegistry;
use Tusk\Cloud\Resilience\Diagnostics\ResilienceRuntime;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\SystemClock;
use Tusk\Config\ProjectConfigurationLoader;
use Tusk\Config\Repository;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Core\ApplicationInterface;
use Tusk\Contracts\Observability\WorkerLifecycleCheckpointInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Runtime\Jobs\JobHandlerScanner;
use Tusk\Runtime\RuntimeConfiguration;
use Tusk\Runtime\RuntimeModuleFactory;
use Tusk\Validation\ConstraintValidator;
use Tusk\Validation\CustomValidatorInterface;
use Tusk\Validation\CustomValidatorRegistry;
use Tusk\Validation\Metadata\ValidationMetadataCompiler;
use Tusk\Validation\Metadata\ValidationMetadataRegistry;
use Tusk\Validation\Validator;
use Tusk\Validation\ValidatorInterface;
use Tusk\Web\Attribute\Controller;
use Tusk\Web\Attribute\Route;
use Tusk\Web\Http\ArgumentBinder;
use Tusk\Web\Http\Request;
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

    /** @var list<string> */
    private array $controllers = ['app/Controller'];

    /** @var array<string, list<class-string<CustomValidatorInterface>>> */
    private array $validators = [];

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

    public function withControllers(string ...$directories): self
    {
        $this->controllers = array_values(array_unique([...$this->controllers, ...$directories]));

        return $this;
    }

    public function withValidator(string $dtoClass, string $validatorClass): self
    {
        if (in_array($validatorClass, $this->validators[$dtoClass] ?? [], true)) {
            throw new \InvalidArgumentException("Validator {$validatorClass} is already registered for {$dtoClass}.");
        }
        $this->validators[$dtoClass][] = $validatorClass;

        return $this;
    }

    public function create(): Application
    {
        $values = $this->loadConfig();
        $resilienceValues = $values['resilience'] ?? [];
        if (! is_array($resilienceValues)) {
            throw new RuntimeException('Configuration at resilience must be an array.');
        }
        $profile = getenv('APP_ENV');
        $profile = is_string($profile) && trim($profile) !== '' ? $profile : 'production';
        $resilience = ResilienceConfigurationLoader::load($resilienceValues, $profile);
        $stateStore = new InMemoryStateStore;
        $diagnosticsRegistry = new ResilienceDiagnosticsRegistry;
        $resilienceReporter = EngineResilienceReporter::fromEnvironment($diagnosticsRegistry);
        $resilienceFactory = new ResiliencePipelineFactory(new SystemClock, $stateStore, registry: $diagnosticsRegistry, reporter: $resilienceReporter);
        $resilienceRuntime = new ResilienceRuntime($resilience, $resilienceFactory, $diagnosticsRegistry);
        $healthChecks = new HealthCheckRegistry;
        $healthChecks->register(new ResilienceConfigurationHealthCheck);

        $container = new Container;
        $metadataRegistry = new ValidationMetadataRegistry;
        $customValidatorRegistry = new CustomValidatorRegistry;
        $container->instance(ResilienceDiagnosticsRegistry::class, $diagnosticsRegistry);
        $container->instance(EngineResilienceReporter::class, $resilienceReporter);
        $container->instance(WorkerLifecycleCheckpointInterface::class, $resilienceReporter);
        $container->instance(ResiliencePipelineFactory::class, $resilienceFactory);
        $container->instance(ResilienceRuntime::class, $resilienceRuntime);
        $router = new Router;
        $root = realpath($this->basePath);
        $controllerPaths = array_map(
            fn (string $directory): string => preg_match('~^(?:[a-zA-Z]:[/\\\\]|/)~', $directory)
                ? $directory
                : ($root ?: $this->basePath).DIRECTORY_SEPARATOR.$directory,
            array_values(array_unique($this->controllers)),
        );
        $controllerClasses = $this->discoverControllers($controllerPaths);
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
        $validator = new Validator(new ConstraintValidator);
        $kernel = new HttpKernel($container, $router, new ArgumentBinder($validator, $metadataRegistry, $customValidatorRegistry));
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
            ResilienceConfiguration::class => $resilience,
            HealthCheckRegistry::class => $healthChecks,
            HttpKernel::class => $kernel,
            ValidationMetadataRegistry::class => $metadataRegistry,
            CustomValidatorRegistry::class => $customValidatorRegistry,
            ValidatorInterface::class => $validator,
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

        foreach ($controllerClasses as $controllerClass) {
            if (! $container->has($controllerClass)) {
                $container->register($controllerClass, 'singleton');
            }
        }

        $this->registerDiscoveredRoutes($router, $controllerClasses);

        $this->compileRouteMetadata($router, $container, $metadataRegistry);
        $this->registerValidators($container, $customValidatorRegistry);
        $metadataRegistry->seal();
        $customValidatorRegistry->seal();

        return $application;
    }

    private function compileRouteMetadata(Router $router, Container $container, ValidationMetadataRegistry $registry): void
    {
        $compiler = new ValidationMetadataCompiler;
        $compiled = [];
        foreach ($router->controllerActions() as $action) {
            $method = new ReflectionMethod($action['controller'], $action['method']);
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                $class = $type->getName();
                if ($class === Request::class || is_a($class, ServerRequestInterface::class, true)
                    || $container->has($class) || isset($compiled[$class])) {
                    continue;
                }
                $registry->register($class, $compiler->compile($class));
                $compiled[$class] = true;
            }
        }
    }

    private function registerValidators(Container $container, CustomValidatorRegistry $registry): void
    {
        foreach ($this->validators as $dtoClass => $validatorClasses) {
            if (! class_exists($dtoClass)) {
                throw new RuntimeException("Custom validator DTO class does not exist: {$dtoClass}.");
            }
            foreach ($validatorClasses as $validatorClass) {
                if (! class_exists($validatorClass) || ! is_subclass_of($validatorClass, CustomValidatorInterface::class)) {
                    throw new RuntimeException("Custom validator {$validatorClass} for {$dtoClass} must implement ".CustomValidatorInterface::class.'.');
                }
                if (! (new ReflectionClass($validatorClass))->isInstantiable()) {
                    throw new RuntimeException("Custom validator {$validatorClass} for {$dtoClass} must be instantiable.");
                }
                if (! $container->has($validatorClass)) {
                    $container->register($validatorClass, 'singleton');
                }
                $scope = $container->export()['scopes'][$validatorClass] ?? null;
                if (! in_array($scope, ['request', 'prototype'], true)) {
                    try {
                        $validator = $container->get($validatorClass);
                    } catch (\Throwable $exception) {
                        throw new RuntimeException("Unable to resolve custom validator {$validatorClass} for {$dtoClass}: {$exception->getMessage()}", 0, $exception);
                    }
                    if (! $validator instanceof CustomValidatorInterface) {
                        throw new RuntimeException("Custom validator service {$validatorClass} for {$dtoClass} must implement ".CustomValidatorInterface::class.'.');
                    }
                }
                $registry->registerFactory($dtoClass, static function () use ($container, $validatorClass, $dtoClass): CustomValidatorInterface {
                    $resolved = $container->get($validatorClass);
                    if (! $resolved instanceof CustomValidatorInterface) {
                        throw new RuntimeException("Custom validator service {$validatorClass} for {$dtoClass} must implement ".CustomValidatorInterface::class.'.');
                    }

                    return $resolved;
                });
            }
        }
    }

    /** @param list<string> $directories
     * @return list<class-string>
     */
    private function discoverControllers(array $directories): array
    {
        $classes = [];
        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => ! $file->isLink()
                    && ! ($file->isDir() && in_array($file->getFilename(), ['vendor', '.git', '.superpowers', '.worktrees', '.tusk'], true)),
            ));
            $files = iterator_to_array($iterator, false);
            usort($files, static fn (\SplFileInfo $left, \SplFileInfo $right): int => strcmp($left->getPathname(), $right->getPathname()));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                try {
                    $declaredClasses = $this->classesIn($file->getPathname());
                    $isIncluded = in_array(realpath($file->getPathname()), get_included_files(), true);
                    $loadedClasses = array_filter($declaredClasses, static fn (string $class): bool => class_exists($class));
                    $missingClasses = array_filter($declaredClasses, static fn (string $class): bool => ! class_exists($class));
                    if (! $isIncluded && $loadedClasses !== [] && $missingClasses !== []) {
                        throw new RuntimeException('Controller file declares an already-loaded class and cannot be safely included: '.$file->getPathname());
                    }
                    if (! $isIncluded && $loadedClasses === [] && $declaredClasses !== []) {
                        require_once $file->getPathname();
                    }
                    foreach ($declaredClasses as $class) {
                        if (! class_exists($class)) {
                            continue;
                        }
                        if ((new \ReflectionClass($class))->getAttributes(Controller::class) !== []) {
                            $classes[] = $class;
                        }
                    }
                } catch (\Throwable $exception) {
                    throw new RuntimeException('Failed to discover controller file '.$file->getPathname().': '.$exception->getMessage(), 0, $exception);
                }
            }
        }
        $classes = array_values(array_unique($classes));
        sort($classes, SORT_STRING);

        return $classes;
    }

    /** @return list<class-string> */
    private function classesIn(string $path): array
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException("Unable to read controller file: {$path}");
        }

        $tokens = token_get_all($source, TOKEN_PARSE);
        $namespace = '';
        $classes = [];
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($cursor = $index + 1; isset($tokens[$cursor]) && $tokens[$cursor] !== ';' && $tokens[$cursor] !== '{'; $cursor++) {
                    if (is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $tokens[$cursor][1];
                    }
                }
            }
            if ($token[0] !== T_CLASS) {
                continue;
            }
            $previous = $index - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                $previous--;
            }
            if ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_NEW, T_DOUBLE_COLON], true)) {
                continue;
            }
            for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
                if (! is_array($tokens[$cursor]) || in_array($tokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($tokens[$cursor][0] === T_STRING) {
                    $classes[] = $namespace === '' ? $tokens[$cursor][1] : $namespace.'\\'.$tokens[$cursor][1];
                }
                break;
            }
        }

        return $classes;
    }

    private function loadConfig(): array
    {
        return ProjectConfigurationLoader::load($this->basePath);
    }

    /** @param list<class-string> $controllerClasses */
    private function registerDiscoveredRoutes(Router $router, array $controllerClasses): void
    {
        $routes = [];
        foreach ($controllerClasses as $class) {
            $reflection = new \ReflectionClass($class);
            $prefixAttributes = $reflection->getAttributes(Controller::class);
            $prefix = $prefixAttributes[0]->newInstance()->prefix;
            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $route = $attribute->newInstance();
                    foreach ($route->methods as $httpMethod) {
                        $path = '/'.trim(trim($prefix, '/').'/'.trim($route->path, '/'), '/');
                        $path = $path === '/' ? '/' : rtrim($path, '/');
                        $key = strtoupper($httpMethod).' '.$path;
                        if ($router->hasRoute(strtoupper($httpMethod), $path)) {
                            throw new RuntimeException("Duplicate controller route {$key}: explicit route conflicts with {$class}::{$method->getName()}.");
                        }
                        if (isset($routes[$key])) {
                            throw new RuntimeException("Duplicate discovered controller route {$key}: {$routes[$key]} and {$class}::{$method->getName()}.");
                        }
                        $routes[$key] = $class.'::'.$method->getName();
                    }
                }
            }
        }

        $router->registerControllers($controllerClasses);
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
