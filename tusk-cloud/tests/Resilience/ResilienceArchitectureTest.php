<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ResilienceArchitectureTest extends TestCase
{
    public function test_production_resilience_core_uses_injected_time_and_has_no_static_properties(): void
    {
        $sourceDirectory = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Resilience';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory));
        $violations = [];

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertNotFalse($source);

            $isSystemClock = $file->getFilename() === 'SystemClock.php';
            if (! $isSystemClock && preg_match('/\b(?:microtime|hrtime|usleep|sleep)\s*\(/', $source) === 1) {
                $violations[] = $file->getPathname().': direct wall-clock or sleep call';
            }

            if (preg_match('/\bstatic\s+(?!function\b|fn\b)[^;()]*\$\w+\s*(?:=|;)/', $source) === 1) {
                $violations[] = $file->getPathname().': static property';
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
