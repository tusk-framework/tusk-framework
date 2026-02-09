<?php

namespace Tusk\Runtime\Process;

use RuntimeException;

class PcntlProcessManager implements ProcessManagerInterface
{
    public function __construct()
    {
        if (!function_exists('pcntl_fork')) {
            throw new RuntimeException("PCNTL extension is required for PcntlProcessManager.");
        }
    }

    public function spawn(callable $workerLogic): int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException("Failed to fork process.");
        }

        if ($pid > 0) {
            // Parent process returns the child PID
            return $pid;
        } else {
            // Child process executes logic and exits
            try {
                $workerLogic();
                exit(0);
            } catch (\Throwable $e) {
                // Log error
                exit(1);
            }
        }
    }

    public function wait(): ?int
    {
        $pid = pcntl_wait($status, WNOHANG);
        return $pid > 0 ? $pid : null;
    }

    public function signal(int $pid, int $signal): void
    {
        posix_kill($pid, $signal);
    }

    public function supportsConcurrency(): bool
    {
        return true;
    }
}
