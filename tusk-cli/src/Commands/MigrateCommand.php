<?php

namespace Tusk\Cli\Commands;

use Tusk\Cli\CommandInterface;
use Tusk\Data\Migration\Migrator;

class MigrateCommand implements CommandInterface
{
    public function execute(array $args): int
    {
        $migrationsPath = getcwd().'/database/migrations';

        if (! is_dir($migrationsPath)) {
            echo "Migrations directory not found at {$migrationsPath}\n";

            return 1;
        }

        $migrator = new Migrator($migrationsPath);

        try {
            $migrator->run();

            return 0;
        } catch (\Exception $e) {
            echo 'Migration failed: '.$e->getMessage()."\n";

            return 1;
        }
    }
}
