<?php

namespace Tusk\Cli\Generator;

class StubGenerator
{
    public function generate(string $stubPath, string $targetPath, array $replacements): bool
    {
        if (! file_exists($stubPath)) {
            throw new \RuntimeException("Stub not found at {$stubPath}");
        }

        $content = file_get_contents($stubPath);

        foreach ($replacements as $search => $replace) {
            $content = str_replace('{{ '.$search.' }}', $replace, $content);
            $content = str_replace('{{'.$search.'}}', $replace, $content);
        }

        $dir = dirname($targetPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($targetPath)) {
            return false; // already exists
        }

        return file_put_contents($targetPath, $content) !== false;
    }
}
