<?php

namespace Tusk\Runtime\Adapters;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Throwable;
use JsonException;

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

            $serverRequest = null;
            try {
                $reqData = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($reqData)) {
                    throw new \InvalidArgumentException('NDJSON request must be an object');
                }
                $serverRequest = NdjsonRequestFactory::fromArray($reqData);

                /** @var \Psr\Http\Message\ResponseInterface $response */
                $response = $requestHandler($serverRequest);

                fwrite(STDOUT, json_encode(NdjsonRequestFactory::toArray($response), JSON_THROW_ON_ERROR) . "\n");

            } catch (JsonException|\InvalidArgumentException $e) {
                error_log('Invalid NDJSON request: ' . $e->getMessage());
                fwrite(STDOUT, json_encode([
                    'status' => 400,
                    'headers' => ['Content-Type' => ['application/json']],
                    'body' => '{"error":"Bad Request"}',
                ], JSON_THROW_ON_ERROR) . "\n");

            } catch (Throwable $e) {
                error_log('Native worker request failed: ' . $e->getMessage());
                $errorResponse = [
                    'status' => 500,
                    'headers' => ['Content-Type' => ['application/json']],
                    'body' => '{"error":"Internal Server Error"}',
                ];
                fwrite(STDOUT, json_encode($errorResponse, JSON_THROW_ON_ERROR) . "\n");
            } finally {
                if ($serverRequest !== null) {
                    NdjsonRequestFactory::cleanup($serverRequest);
                }
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
