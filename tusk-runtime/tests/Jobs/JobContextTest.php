<?php

namespace Tusk\Runtime\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Runtime\Jobs\JobContext;
use Tusk\Contracts\Runtime\Jobs\JobPayloadException;

final class JobContextTest extends TestCase
{
    public function test_exposes_message_metadata_and_json_object(): void
    {
        $job = new JobContext('id-1', 'emails', 'send.mail', '{"id":42,"nested":{"ok":true}}', ['trace' => 'abc']);

        self::assertSame('id-1', $job->id());
        self::assertSame('emails', $job->queue());
        self::assertSame('send.mail', $job->name());
        self::assertSame('{"id":42,"nested":{"ok":true}}', $job->payload());
        self::assertSame(['trace' => 'abc'], $job->headers());
        self::assertSame(['id' => 42, 'nested' => ['ok' => true]], $job->jsonPayload());
    }

    public function test_rejects_invalid_json_without_exposing_payload(): void
    {
        try {
            (new JobContext('id', 'q', 'job', '{secret:invalid}', []))->jsonPayload();
            self::fail('Expected invalid JSON rejection');
        } catch (JobPayloadException $exception) {
            self::assertStringContainsString('JSON', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function test_rejects_scalar_and_list_json(): void
    {
        foreach (['null', '42', '"text"', '[1,2]'] as $payload) {
            try {
                (new JobContext('id', 'q', 'job', $payload, []))->jsonPayload();
                self::fail('Expected object rejection for '.$payload);
            } catch (JobPayloadException $exception) {
                self::assertStringContainsString('object', $exception->getMessage());
            }
        }
    }
}
