<?php

namespace Tusk\Cli\Commands;

use Tusk\Cli\CommandInterface;
use Tusk\Cli\Generator\StubGenerator;

class MakeControllerCommand implements CommandInterface
{
    public function execute(array $args): int
    {
        if (empty($args)) {
            echo "Error: Controller name required.\n";
            echo "Usage: tusk make:controller <ControllerName>\n";

            return 1;
        }

        $name = $args[0];
        $className = basename(str_replace('\\', '/', $name));
        $namespace = 'App\\Http\\Controllers'; // Defaulting for simple usage
        if (str_contains($name, '\\')) {
            $namespace .= '\\'.dirname(str_replace('/', '\\', $name));
        }

        $stub = __DIR__.'/../../stubs/controller.stub';
        $target = getcwd().'/src/Http/Controllers/'.$name.'.php';

        $generator = new StubGenerator;

        // Let's create a temporary stub if it doesn't exist
        if (! file_exists($stub)) {
            $dir = dirname($stub);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($stub, "<?php\n\nnamespace {{ namespace }};\n\nuse Tusk\Web\Http\Request;\nuse Tusk\Web\Http\Response;\n\nclass {{ class }}\n{\n    public function index(Request \$request): Response\n    {\n        return new Response(200, [], 'Hello from {{ class }}');\n    }\n}\n");
        }

        $success = $generator->generate($stub, $target, [
            'namespace' => $namespace,
            'class' => $className,
        ]);

        if ($success) {
            echo "Controller created successfully at {$target}\n";

            return 0;
        } else {
            echo "Error: Could not create controller. File may already exist.\n";

            return 1;
        }
    }
}
