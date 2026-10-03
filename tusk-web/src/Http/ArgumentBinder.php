<?php

declare(strict_types=1);

namespace Tusk\Web\Http;

use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Tusk\Contracts\Container\ContainerInterface;

final class ArgumentBinder
{
    /**
     * @return list<mixed>
     */
    public function bind(
        ReflectionMethod $method,
        ServerRequestInterface $request,
        array $routeParameters,
        ContainerInterface $container,
    ): array {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($parameter, $request, $routeParameters, $container);
        }

        return $arguments;
    }

    private function resolveParameter(
        ReflectionParameter $parameter,
        ServerRequestInterface $request,
        array $routeParameters,
        ContainerInterface $container,
    ): mixed {
        $type = $parameter->getType();
        $name = $parameter->getName();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $className = $type->getName();

            if ($className === Request::class) {
                return new Request($request);
            }

            if (is_a($className, ServerRequestInterface::class, true)) {
                return $request;
            }

            if ($container->has($className)) {
                return $container->get($className);
            }

            return $this->hydrate($className, $this->payload($request));
        }

        $value = $routeParameters[$name] ?? $this->payload($request)[$name] ?? $request->getQueryParams()[$name] ?? null;

        if ($value === null && $parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($value === null) {
            throw new HttpException(400, "Missing required argument: {$name}");
        }

        return $this->cast($value, $type, $name);
    }

    /** @return array<string, mixed> */
    private function payload(ServerRequestInterface $request): array
    {
        $payload = $request->getParsedBody();

        return is_array($payload) ? $payload : [];
    }

    /** @param array<string, mixed> $payload */
    private function hydrate(string $className, array $payload): object
    {
        if (! class_exists($className)) {
            throw new HttpException(500, "Unable to bind argument type: {$className}");
        }

        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (! array_key_exists($name, $payload)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new HttpException(422, "Missing request field: {$name}");
            }

            $arguments[] = $this->cast($payload[$name], $parameter->getType(), $name);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function cast(mixed $value, ?\ReflectionType $type, string $name): mixed
    {
        if (! $type instanceof ReflectionNamedType || ! $type->isBuiltin()) {
            return $value;
        }

        try {
            return match ($type->getName()) {
                'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? throw new \ValueError,
                'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? throw new \ValueError,
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new \ValueError,
                'string' => is_scalar($value) ? (string) $value : throw new \ValueError,
                'array' => is_array($value) ? $value : throw new \ValueError,
                default => $value,
            };
        } catch (\ValueError) {
            throw new HttpException(422, "Invalid value for argument: {$name}");
        }
    }
}
