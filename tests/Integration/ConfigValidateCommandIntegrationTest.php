<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ConfigValidateCommandIntegrationTest extends TestCase
{
    public function test_config_validate_is_available_before_application_compilation(): void
    {
        $basePath = dirname(__DIR__, 2);
        $output = [];
        $status = -1;
        exec('php '.escapeshellarg($basePath.'/bin/tusk').' config:validate', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('Resilience configuration is valid', implode("\n", $output));
    }
}
