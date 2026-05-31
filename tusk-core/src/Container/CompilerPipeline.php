<?php

namespace Tusk\Core\Container;

class CompilerPipeline
{
    public function __construct(
        private ServiceScanner $scanner,
        private ContainerCompiler $compiler
    ) {}

    /**
     * Scans directories, compiles the container, and dumps the PHP code to a file.
     *
     * @param array<string> $directories Directories to scan for #[Service] classes
     * @param string $outputPath Path to save the CompiledContainer.php
     * @param string $namespace Namespace for the generated class
     * @param string $className Name of the generated class
     */
    public function compileAndDump(array $directories, string $outputPath, string $namespace = 'Tusk\Compiled', string $className = 'CompiledContainer'): void
    {
        $definitions = $this->scanner->scan($directories);
        $code = $this->compiler->compile($definitions, $namespace, $className);
        
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        file_put_contents($outputPath, $code);
    }
}
