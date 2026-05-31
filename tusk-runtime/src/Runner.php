<?php

namespace Tusk\Runtime;

use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Tusk\Web\HttpKernel;

class Runner
{
    private HttpKernel $kernel;

    public function __construct(HttpKernel $kernel)
    {
        $this->kernel = $kernel;
    }

    public function run(): void
    {
        // Main Loop
        while (true) {
            $line = fgets(STDIN);
            if ($line === false) {
                break; // Pipe closed
            }

            $reqData = json_decode($line, true);
            if (! $reqData) {
                continue;
            }

            try {
                // Convert JSON to PSR-7 ServerRequest
                $request = new ServerRequest(
                    $reqData['method'] ?? 'GET',
                    new Uri($reqData['url'] ?? '/'),
                    $reqData['headers'] ?? [],
                    $reqData['body'] ?? ''
                );

                // Handle Request
                $response = $this->kernel->handle($request);

                // Send Response
                $this->send($response);

            } catch (\Throwable $e) {
                $this->sendError($e);
            }
        }
    }

    private function send(ResponseInterface $response): void
    {
        $payload = json_encode([
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => (string) $response->getBody(),
        ]);

        fwrite(STDOUT, $payload."\n");
    }

    private function sendError(\Throwable $e): void
    {
        $payload = json_encode([
            'status' => 500,
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => 'Internal Server Error: '.$e->getMessage()."\n".$e->getTraceAsString(),
        ]);

        fwrite(STDOUT, $payload."\n");
    }
}
