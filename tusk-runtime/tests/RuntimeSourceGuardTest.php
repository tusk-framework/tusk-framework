<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests;

use PHPUnit\Framework\TestCase;

final class RuntimeSourceGuardTest extends TestCase
{
    public function test_runtime_source_contains_only_the_roadrunner_transport_boundary(): void
    {
        $sourceRoot = realpath(__DIR__.'/../src');
        self::assertNotFalse($sourceRoot);

        $forbidden = [
            'NativeLoopAdapter',
            'NdjsonRequestFactory',
            'SwooleAdapter',
            'fgets(STDIN)',
            'fwrite(STDOUT)',
        ];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            foreach ($forbidden as $marker) {
                self::assertStringNotContainsString(
                    $marker,
                    $contents,
                    sprintf('Runtime source %s contains removed marker %s.', $file->getPathname(), $marker),
                );
            }
        }
    }
}
