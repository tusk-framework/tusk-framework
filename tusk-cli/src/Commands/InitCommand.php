<?php

namespace Tusk\Cli\Commands;

use Tusk\Cli\CommandInterface;
use Tusk\Cli\Generator\ProjectGenerator;

class InitCommand implements CommandInterface
{
    public function execute(array $args): int
    {
        // Simple arg parsing: php tusk init <name> --type=<type>
        $name = $args[0] ?? null;
        $type = 'api';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--type=')) {
                $type = substr($arg, 7);
            }
        }

        if (!$name) {
            echo "Usage: php tusk init <name> [--type=api|micro]\n";
            return 1;
        }

        echo "Creating Tusk Project: $name\n";
        echo "Type: $type\n";

        try {
            $generator = new ProjectGenerator();
            $generator->generate($name, $type);

            echo "Project '$name' created successfully!\n";
            echo "1. cd $name\n";
            echo "2. composer install\n"; // Assume composer is available globally
            echo "3. docker-compose up\n";

            return 0;
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return 1;
        }
    }
}
