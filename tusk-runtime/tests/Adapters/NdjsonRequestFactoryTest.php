<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Adapters;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Tusk\Runtime\Adapters\NdjsonRequestFactory;

final class NdjsonRequestFactoryTest extends TestCase
{
    /** @var string[] */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function test_it_converts_request_fields_and_uploads(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tusk-upload-');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;

        $request = NdjsonRequestFactory::fromArray([
            'method' => 'POST',
            'url' => '/documents',
            'headers' => ['X-Trace' => ['abc']],
            'cookies' => ['session' => 'cookie-value'],
            'query' => ['page' => '2'],
            'body' => 'raw-body',
            'parsedBody' => ['title' => 'Report'],
            'uploadedFiles' => [
                'document' => [[
                    'name' => 'report.txt',
                    'type' => 'text/plain',
                    'tmp_name' => $path,
                    'error' => UPLOAD_ERR_OK,
                    'size' => 11,
                ]],
            ],
        ]);

        $this->assertSame('abc', $request->getHeaderLine('X-Trace'));
        $this->assertSame(['session' => 'cookie-value'], $request->getCookieParams());
        $this->assertSame(['page' => '2'], $request->getQueryParams());
        $this->assertSame(['title' => 'Report'], $request->getParsedBody());
        $this->assertSame('raw-body', (string) $request->getBody());

        $files = $request->getUploadedFiles();
        self::assertArrayHasKey('document', $files);
        self::assertCount(1, $files['document']);
        self::assertInstanceOf(UploadedFileInterface::class, $files['document'][0]);
        self::assertSame('report.txt', $files['document'][0]->getClientFilename());
    }

    public function test_invalid_upload_metadata_is_ignored(): void
    {
        $request = NdjsonRequestFactory::fromArray([
            'uploadedFiles' => [
                'document' => [['name' => 'missing.txt', 'tmp_name' => '']],
            ],
        ]);

        self::assertSame([], $request->getUploadedFiles());
    }

    public function test_response_conversion_preserves_status_headers_and_body(): void
    {
        $result = NdjsonRequestFactory::toArray(new Response(201, ['X-Trace' => 'abc'], 'created'));

        self::assertSame(201, $result['status']);
        self::assertSame(['abc'], $result['headers']['X-Trace']);
        self::assertSame('created', $result['body']);
    }
}
