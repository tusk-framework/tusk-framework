<?php

declare(strict_types=1);

namespace Tusk\Config;

use DirectoryIterator;
use RuntimeException;

final class ProjectConfigurationLoader
{
    /** @return array<string, array<array-key, mixed>> */
    public static function load(string $basePath): array
    {
        $directory = realpath($basePath.'/config');
        if ($directory === false || ! is_dir($directory)) {
            return [];
        }

        $values = [];
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php' && ! $entry->isLink()) {
                $value = require $entry->getPathname();
                if (! is_array($value)) {
                    throw new RuntimeException("Config file must return an array: {$entry->getPathname()}");
                }
                $values[$entry->getBasename('.php')] = $value;
            }
        }

        return $values;
    }
}
