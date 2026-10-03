<?php

declare(strict_types=1);

namespace Tusk\Runtime;

use InvalidArgumentException;
use Tusk\Config\Env;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Tusk\Runtime\Adapters\NativeLoopAdapter;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;

final class RuntimeAdapterFactory
{
    public static function create(?string $runtime = null): RuntimeAdapterInterface
    {
        $runtime = strtolower(trim($runtime ?? (string) Env::get('TUSK_RUNTIME', 'roadrunner')));
        $runtime = $runtime !== '' ? $runtime : 'roadrunner';

        return match ($runtime) {
            'roadrunner', 'rr' => new RoadRunnerAdapter(),
            'native' => new NativeLoopAdapter(),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported Tusk runtime "%s". Supported runtimes: roadrunner, native.',
                $runtime
            )),
        };
    }
}
