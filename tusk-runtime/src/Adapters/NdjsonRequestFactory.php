<?php

declare(strict_types=1);

namespace Tusk\Runtime\Adapters;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class NdjsonRequestFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ServerRequestInterface
    {
        $request = new ServerRequest(
            is_string($data['method'] ?? null) ? $data['method'] : 'GET',
            is_string($data['url'] ?? null) ? $data['url'] : '/',
            is_array($data['headers'] ?? null) ? $data['headers'] : [],
            Stream::create(is_string($data['body'] ?? null) ? $data['body'] : '')
        );

        if (is_array($data['query'] ?? null)) {
            $request = $request->withQueryParams($data['query']);
        }
        if (is_array($data['cookies'] ?? null)) {
            $request = $request->withCookieParams($data['cookies']);
        }
        if (array_key_exists('parsedBody', $data)) {
            $request = $request->withParsedBody($data['parsedBody']);
        }

        [$uploadedFiles, $temporaryPaths] = self::uploadedFiles($data['uploadedFiles'] ?? []);
        $request = $request->withUploadedFiles($uploadedFiles);

        return $request->withAttribute('_tusk_upload_paths', $temporaryPaths);
    }

    /**
     * @return array{status: int, headers: array<string, array<int, string>>, body: string}
     */
    public static function toArray(ResponseInterface $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => (string) $response->getBody(),
        ];
    }

    public static function cleanup(ServerRequestInterface $request): void
    {
        $paths = $request->getAttribute('_tusk_upload_paths', []);
        if (! is_array($paths)) {
            return;
        }

        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param mixed $rawFiles
     * @return array{0: array<string, array<int, UploadedFile>>, 1: string[]}
     */
    private static function uploadedFiles(mixed $rawFiles): array
    {
        if (! is_array($rawFiles)) {
            return [[], []];
        }

        $files = [];
        $temporaryPaths = [];
        foreach ($rawFiles as $field => $entries) {
            if (! is_string($field) || ! is_array($entries)) {
                continue;
            }

            $normalizedEntries = [];
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $path = $entry['tmp_name'] ?? null;
                if (! is_string($path) || $path === '' || ! is_file($path)) {
                    continue;
                }

                $error = is_int($entry['error'] ?? null) ? $entry['error'] : UPLOAD_ERR_OK;
                $size = is_int($entry['size'] ?? null) ? $entry['size'] : null;
                $normalizedEntries[] = new UploadedFile(
                    $path,
                    $size,
                    $error,
                    is_string($entry['name'] ?? null) ? $entry['name'] : null,
                    is_string($entry['type'] ?? null) ? $entry['type'] : null
                );
                $temporaryPaths[] = $path;
            }

            if ($normalizedEntries !== []) {
                $files[$field] = $normalizedEntries;
            }
        }

        return [$files, $temporaryPaths];
    }
}
