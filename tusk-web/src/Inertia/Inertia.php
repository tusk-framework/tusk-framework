<?php

namespace Tusk\Web\Inertia;

class Inertia
{
    private static array $sharedProps = [];
    private static ?string $version = null;

    public static function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            self::$sharedProps = array_merge(self::$sharedProps, $key);
        } else {
            self::$sharedProps[$key] = $value;
        }
    }

    public static function getShared(): array
    {
        return self::$sharedProps;
    }

    public static function version(?string $version): void
    {
        self::$version = $version;
    }

    public static function getVersion(): ?string
    {
        return self::$version;
    }
}
