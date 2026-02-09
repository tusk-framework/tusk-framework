<?php

namespace Tusk\Runtime\Supervisor;

use Tusk\Runtime\Process\ProcessManagerInterface;
use Tusk\Runtime\Process\PcntlProcessManager;
use Tusk\Runtime\Process\LoopProcessManager;
use Exception;

class Supervisor
{
    private ProcessManagerInterface $processManager;
    private array $workers = [];
    private bool $running = false;

    public function __construct(
        private int $workerCount = 1
    ) {
        // Auto-detect environment
        if (function_exists('pcntl_fork')) {
            $this->processManager = new PcntlProcessManager();
        } else {
            $this->processManager = new LoopProcessManager();
        }
    }

    /**
     * Starts the supervisor loop.
     * 
     * @param callable $workerLogic The closure to run inside each worker.
     */
    public function start(callable $workerLogic): void
    {
        $this->running = true;

        echo "[Supervisor] Starting with {$this->workerCount} workers...\n";

        // Spawn initial workers
        for ($i = 0; $i < $this->workerCount; $i++) {
            $this->spawnWorker($workerLogic);
        }

        // Monitor loop
        while ($this->running) {
            $exitedPid = $this->processManager->wait();

            if ($exitedPid) {
                echo "[Supervisor] Worker {$exitedPid} exited. Restarting...\n";
                unset($this->workers[$exitedPid]);
                $this->spawnWorker($workerLogic);
            }

            // If not concurrent (Windows loop), the spawn has already blocked and finished.
            // So we just break the loop to avoid infinite respawn in single-thread mode.
            if (!$this->processManager->supportsConcurrency()) {
                break;
            }

            usleep(100000); // 100ms tick

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    private function spawnWorker(callable $logic): void
    {
        try {
            $pid = $this->processManager->spawn($logic);
            $this->workers[$pid] = true;
        } catch (Exception $e) {
            echo "[Supervisor] Failed to spawn worker: " . $e->getMessage() . "\n";
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $signal = defined('SIGTERM') ? SIGTERM : 15;

        foreach (array_keys($this->workers) as $pid) {
            $this->processManager->signal($pid, $signal);
        }
    }
}
