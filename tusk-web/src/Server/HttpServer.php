<?php

namespace Tusk\Web\Server;

use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Tusk\Web\HttpKernel;

class HttpServer
{
    private $server;

    public function __construct(
        private HttpKernel $kernel,
        private string $host = '0.0.0.0',
        private int $port = 8000
    ) {}

    public function start(): void
    {
        $uri = "tcp://{$this->host}:{$this->port}";
        // Ensure context allows reuse port
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
            ],
        ]);

        $this->server = stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if (! $this->server) {
            throw new \RuntimeException("Failed to start server: $errstr ($errno)");
        }

        echo "Tusk Server running at http://{$this->host}:{$this->port}\n";

        // Simple blocking loop for v0.5
        while (true) {
            $conn = @stream_socket_accept($this->server, -1);
            if (! $conn) {
                continue;
            }

            try {
                $this->handleConnection($conn);
            } catch (\Throwable $e) {
                error_log('Connection error: '.$e->getMessage());
            } finally {
                if (is_resource($conn)) {
                    fclose($conn);
                }
            }
        }
    }

    private function handleConnection($conn): void
    {
        $buffer = '';
        // Read headers
        while (! feof($conn)) {
            $chunk = fread($conn, 1024);
            if ($chunk === false) {
                return;
            }
            $buffer .= $chunk;
            if (str_contains($buffer, "\r\n\r\n")) {
                break;
            }
        }

        if (empty($buffer)) {
            return;
        }

        $request = $this->parseRequest($buffer);

        try {
            $response = $this->kernel->handle($request);
        } catch (\Throwable $e) {
            $response = new Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error: '.$e->getMessage());
        }

        $this->sendResponse($conn, $response);
    }

    private function parseRequest(string $raw): ServerRequest
    {
        $parts = explode("\r\n\r\n", $raw, 2);
        $headerPart = $parts[0];
        $body = $parts[1] ?? '';

        $lines = explode("\r\n", $headerPart);
        $firstLine = (string) array_shift($lines);
        $tokens = explode(' ', $firstLine);
        $method = $tokens[0] !== '' ? $tokens[0] : 'GET';
        $uri    = isset($tokens[1]) && $tokens[1] !== '' ? $tokens[1] : '/';

        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ': ')) {
                [$key, $value] = explode(': ', $line, 2);
                $headers[$key] = $value;
            }
        }

        return new ServerRequest($method, $uri, $headers, $body);
    }

    private function sendResponse($conn, ResponseInterface $response): void
    {
        $statusText = $response->getReasonPhrase() ?: 'Unknown';

        $lines = ["HTTP/1.1 {$response->getStatusCode()} $statusText"];
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = "$name: $value";
            }
        }

        $body = (string) $response->getBody();
        $lines[] = 'Content-Length: '.strlen($body);
        $lines[] = 'Connection: close';
        $lines[] = '';

        $output = implode("\r\n", $lines)."\r\n".$body;

        @fwrite($conn, $output);
    }
}
