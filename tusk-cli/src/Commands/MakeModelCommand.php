<?php

namespace Tusk\Cli\Commands;

use Tusk\Cli\CommandInterface;
use Tusk\Cli\Generator\StubGenerator;

class MakeModelCommand implements CommandInterface
{
    public function execute(array $args): int
    {
        if (empty($args)) {
            echo "Error: Model name required.\n";
            echo "Usage: tusk make:model <ModelName>\n";

            return 1;
        }

        $name = $args[0];
        $className = basename(str_replace('\\', '/', $name));
        $namespace = 'App\\Models';
        if (str_contains($name, '\\')) {
            $namespace .= '\\'.dirname(str_replace('/', '\\', $name));
        }

        $stub = __DIR__.'/../../stubs/model.stub';
        $target = getcwd().'/src/Models/'.$name.'.php';

        $generator = new StubGenerator;

        if (! file_exists($stub)) {
            $dir = dirname($stub);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($stub, "<?php\n\nnamespace {{ namespace }};\n\nuse Tusk\Data\Model\Model;\n\nclass {{ class }} extends Model\n{\n    protected array \$fillable = [];\n}\n");
        }

        $success = $generator->generate($stub, $target, [
            'namespace' => $namespace,
            'class' => $className,
        ]);

        if ($success) {
            echo "Model created successfully at {$target}\n";

            return 0;
        } else {
            echo "Error: Could not create model. File may already exist.\n";

            return 1;
        }
    }
}
