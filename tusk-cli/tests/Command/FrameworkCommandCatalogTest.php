<?php

declare(strict_types=1);

namespace Tusk\Cli\Tests\Command;

use PHPUnit\Framework\TestCase;
use Tusk\Cli\Command\FrameworkCommandCatalog;

final class FrameworkCommandCatalogTest extends TestCase
{
    public function test_catalog_names_include_all_framework_commands_without_building_commands(): void
    {
        $catalog = new FrameworkCommandCatalog(getcwd() ?: '.', [
            'make:migration' => static fn () => new \Symfony\Component\Console\Command\Command('make:migration'),
            'migrate:status' => static fn () => new \Symfony\Component\Console\Command\Command('migrate:status'),
            'migrate:rollback' => static fn () => new \Symfony\Component\Console\Command\Command('migrate:rollback'),
            'schema:sync' => static fn () => new \Symfony\Component\Console\Command\Command('schema:sync'),
        ]);

        self::assertSame([
            'build',
            'config:validate',
            'init',
            'make:controller',
            'make:entity',
            'run',
            'queue:work',
            'runtime:diagnostics',
            'make:migration',
            'migrate',
            'migrate:status',
            'migrate:rollback',
            'schema:sync',
        ], $catalog->getNames());
    }
}
