<?php

namespace Tusk\Foundation;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Tusk\Contracts\Core\ApplicationInterface;
use Tusk\Core\Container\Container;
use Tusk\Web\HttpKernel;

class Application implements ApplicationInterface
{
    public function __construct(
        private string $basePath,
        private Container $container,
        private HttpKernel $httpKernel,
    ) {}

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
        throw new RuntimeException('No worker runtime is configured; worker support is not yet available.');
    }

    public function start(): void
    {
        $this->runWorker();
    }

    public function shutdown(): void
    {
        // The bootstrap owns no persistent runtime until one is installed.
    }
}
