<?php

namespace Tusk\Cli\Commands;

use Tusk\Cli\CommandInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Kernel;

class RunCommand implements CommandInterface
{
    public function execute(array $args): int
    {
        $file = $args[0] ?? null;

        if (!$file) {
            echo "Usage: php tusk run <file>\n";
            return 1;
        }

        $filePath = realpath($file);

        if (!$filePath || !file_exists($filePath)) {
            echo "File not found: {$file}\n";
            return 1;
        }

        echo "Tusk Framework v0.1.0\n";
        echo "Starting application: {$file}\n";

        $container = new Container();
        $kernel = new Kernel($container);

        require_once $filePath;

        $kernel->start();

        return 0;
    }
}
