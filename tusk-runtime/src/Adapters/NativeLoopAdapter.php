<?php

namespace Tusk\Runtime\Adapters;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Throwable;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;

class NativeLoopAdapter implements RuntimeAdapterInterface
{
    private bool $running = false;

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        $this->running = true;
        
        // Unbuffer stdout to ensure Go receives data immediately
        stream_set_write_buffer(STDOUT, 0);

        // Catch signals for graceful shutdown (requires ext-pcntl)
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            /** @phpstan-ignore-next-line */
            pcntl_signal(2 /* SIGINT */, [$this, 'stop']);
            /** @phpstan-ignore-next-line */
            pcntl_signal(15 /* SIGTERM */, [$this, 'stop']);
        }

        while ($this->running) {
            $line = fgets(STDIN);
            if ($line === false) {
                break; // End of pipe
            }

            $reqData = json_decode($line, true);
            if (!$reqData) {
                continue;
            }

            try {
                $method = $reqData['method'] ?? 'GET';
                $url = $reqData['url'] ?? '/';
                $headers = $reqData['headers'] ?? [];
                $bodyStr = $reqData['body'] ?? '';
                
                $bodyStream = Stream::create($bodyStr);
                
                $serverRequest = new ServerRequest(
                    $method,
                    $url,
                    $headers,
                    $bodyStream
                );
                
                if (isset($reqData['query'])) {
                    $serverRequest = $serverRequest->withQueryParams($reqData['query']);
                }
                if (isset($reqData['cookies'])) {
                    $serverRequest = $serverRequest->withCookieParams($reqData['cookies']);
                }
                if (isset($reqData['parsedBody'])) {
                    $serverRequest = $serverRequest->withParsedBody($reqData['parsedBody']);
                }
                // Uploaded files map requires creating UploadedFileInterface objects. 
                // For simplicity in v0.1 we'll ignore or pass them as parsed body if necessary,
                // since constructing PSR-7 uploaded files correctly requires complex array mappings.

                /** @var \Psr\Http\Message\ResponseInterface $response */
                $response = $requestHandler($serverRequest);
                
                $body = (string) $response->getBody();
                $resData = [
                    'status' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $body,
                ];

                fwrite(STDOUT, json_encode($resData) . "\n");

            } catch (Throwable $e) {
                $errorResponse = [
                    'status' => 500,
                    'headers' => ['Content-Type' => ['text/plain']],
                    'body' => "Internal Server Error: " . $e->getMessage(),
                ];
                fwrite(STDOUT, json_encode($errorResponse) . "\n");
            } finally {
                // Context Isolation: clean request scoped container services
                $container->resetScope('request');
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function getName(): string
    {
        return 'native';
    }
}
