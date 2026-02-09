<?php

namespace Tusk\Runtime;

use Tusk\Web\HttpKernel;
use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;

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
            if (!$reqData) {
                continue;
            }

            try {
                // Convert JSON to Tusk Request
                $request = new Request(
                    $reqData['method'] ?? 'GET',
                    $reqData['url'] ?? '/',
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

    private function send(Response $response): void
    {
        $payload = json_encode([
            'status' => $response->statusCode,
            'headers' => $response->headers,
            'body' => (string) $response->body,
        ]);

        fwrite(STDOUT, $payload . "\n");
    }

    private function sendError(\Throwable $e): void
    {
        $payload = json_encode([
            'status' => 500,
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => "Internal Server Error: " . $e->getMessage() . "\n" . $e->getTraceAsString(),
        ]);

        fwrite(STDOUT, $payload . "\n");
    }
}
