<?php

namespace Tusk\Web;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\MiddlewarePipeline;
use Tusk\Web\Router\RouterInterface;

class HttpKernel implements RequestHandlerInterface
{
    /** @var string[] */
    private array $globalMiddleware = [];

    public function __construct(
        private ContainerInterface $container,
        private RouterInterface $router
    ) {}

    public function addMiddleware(string $middlewareClass): self
    {
        $this->globalMiddleware[] = $middlewareClass;
        return $this;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $method = $request->getMethod();
            $uri = $request->getUri()->getPath();

            $match = $this->router->match($method, $uri);

            // Core handler that finally executes the Controller
            $coreHandler = new class($this->container, $match) implements RequestHandlerInterface {
                public function __construct(
                    private ContainerInterface $container,
                    private ?\Tusk\Web\Router\RouteMatch $match
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    if (!$this->match) {
                        return new Response(404, [], 'Not Found');
                    }

                    $controllerClass = $this->match->controller;
                    $method = $this->match->method;

                    $controller = $this->container->get($controllerClass);
                    $tuskRequest = new \Tusk\Web\Http\Request($request);
                    $response = $controller->$method(...[...array_values($this->match->params), $tuskRequest]);

                    if (is_array($response)) {
                        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($response));
                    }

                    if (is_string($response)) {
                        return new Response(200, ['Content-Type' => 'text/html'], $response);
                    }

                    if ($response instanceof ResponseInterface) {
                        return $response;
                    }

                    return new Response(500, [], 'Invalid controller response type');
                }
            };

            $pipeline = new MiddlewarePipeline($coreHandler);

            // Pipe Global Middleware
            foreach ($this->globalMiddleware as $middlewareClass) {
                $pipeline->pipe($this->container->get($middlewareClass));
            }

            // Pipe Route Specific Middleware
            if ($match && !empty($match->middleware)) {
                foreach ($match->middleware as $middlewareClass) {
                    $pipeline->pipe($this->container->get($middlewareClass));
                }
            }

            return $pipeline->handle($request);
        } catch (\Throwable $e) {
            // Server Log
            error_log(sprintf("[%s] %s in %s:%d\nStack trace:\n%s", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));

            // If it is a request asking for JSON, return JSON
            if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
                return new Response(500, ['Content-Type' => 'application/json'], json_encode([
                    'error' => 'Internal Server Error',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]));
            }

            // User-friendly HTML Output
            $debug = defined('WP_DEBUG') ? WP_DEBUG : false;
            $traceHtml = '';
            $consoleJs = '';

            if ($debug) {
                $traceHtml = "
                    <div class='debug-info'>
                        <h3>Error Details (Debug Mode):</h3>
                        <p><strong>Exception:</strong> " . get_class($e) . "</p>
                        <p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                        <p><strong>File:</strong> " . $e->getFile() . " on line " . $e->getLine() . "</p>
                        <details>
                            <summary>Stack Trace</summary>
                            <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>
                        </details>
                    </div>
                ";

                $jsonError = json_encode([
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                
                $consoleJs = "<script>console.error('Tusk Engine Error:', {$jsonError});</script>";
            }

            $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en-US">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>500 - Internal Server Error</title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                        background: #f8f9fa;
                        color: #202124;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        min-height: 100vh;
                        margin: 0;
                        padding: 20px;
                    }
                    .error-container {
                        background: #fff;
                        border-radius: 12px;
                        box-shadow: 0 4px 24px rgba(0,0,0,0.08);
                        max-width: 800px;
                        width: 100%;
                        padding: 40px;
                    }
                    h1 { color: #dc3545; font-size: 32px; margin-top: 0; }
                    p { font-size: 16px; line-height: 1.6; color: #5f6368; }
                    .debug-info {
                        margin-top: 30px;
                        padding: 20px;
                        background: #f1f3f4;
                        border-radius: 8px;
                        border-left: 4px solid #dc3545;
                        overflow-x: auto;
                    }
                    .debug-info h3 { margin-top: 0; font-size: 18px; color: #202124; }
                    .debug-info p { margin: 8px 0; font-size: 14px; color: #3c4043; }
                    pre {
                        background: #202124;
                        color: #e8eaed;
                        padding: 16px;
                        border-radius: 6px;
                        font-size: 13px;
                        overflow-x: auto;
                    }
                    summary {
                        cursor: pointer;
                        font-weight: 600;
                        color: #1a73e8;
                        margin-top: 16px;
                    }
                </style>
            </head>
            <body>
                <div class="error-container">
                    <h1>Oops! Something went wrong.</h1>
                    <p>The server encountered an unexpected condition that prevented it from fulfilling the request.</p>
                    <p>The technical team has already been notified via system logs.</p>
                    {$traceHtml}
                </div>
                {$consoleJs}
            </body>
            </html>
            HTML;

            return new Response(500, ['Content-Type' => 'text/html; charset=utf-8'], $html);
        }
    }
}
