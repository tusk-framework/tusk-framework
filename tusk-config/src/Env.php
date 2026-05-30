<?php

namespace Tusk\Config;

class Env
{
    public static function load(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                if (! array_key_exists($name, $_SERVER) && ! array_key_exists($name, $_ENV)) {
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }

    public static function get(string $key, $default = null)
    {
        $value = getenv($key);

        if ($value === false) {
            return $_ENV[$key] ?? $default;
        }

        return $value;
    }
}
