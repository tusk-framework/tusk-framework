<?php

namespace Tusk\Runtime\Jobs;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tusk\Contracts\Attributes\AsJob;

final class JobHandlerScanner
{
    /** @param list<string> $directories */
    public function scan(array $directories): JobHandlerRegistry
    {
        $handlers = [];
        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                throw new RuntimeException('Job directory not found: '.$directory);
            }
            $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), ['vendor', '.git', '.superpowers', '.worktrees'], true)),
            ));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                foreach ($this->classesIn($file->getPathname()) as $class) {
                    if (! class_exists($class)) {
                        require_once $file->getPathname();
                    }
                    $reflection = new \ReflectionClass($class);
                    foreach ($reflection->getAttributes(AsJob::class) as $attribute) {
                        $name = trim($attribute->newInstance()->name);
                        if (isset($handlers[$name])) {
                            throw new RuntimeException('Duplicate job name: '.$name);
                        }
                        $handlers[$name] = $class;
                    }
                }
            }
        }

        return new JobHandlerRegistry($handlers);
    }

    /** @return list<class-string> */
    private function classesIn(string $path): array
    {
        $tokens = token_get_all(file_get_contents($path), TOKEN_PARSE);
        $namespace = '';
        $classes = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i])) {
                continue;
            }
            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            }
            if ($tokens[$i][0] !== T_CLASS) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if (! is_array($tokens[$j]) || $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if ($tokens[$j][0] === T_STRING) {
                    $classes[] = $namespace === '' ? $tokens[$j][1] : $namespace.'\\'.$tokens[$j][1];
                }
                break;
            }
        }

        return $classes;
    }
}
