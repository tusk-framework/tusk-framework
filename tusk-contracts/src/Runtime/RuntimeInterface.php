<?php

namespace Tusk\Contracts\Runtime;

use Tusk\Contracts\Core\ApplicationInterface;

interface RuntimeInterface
{
    /**
     * Executes the persistent application lifecycle.
     */
    public function handle(ApplicationInterface $app): void;

    /**
     * Returns the runtime engine name (e.g., 'roadrunner', 'swoole', 'native').
     */
    public function getEngine(): string;
}
