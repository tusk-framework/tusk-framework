<?php

namespace Tusk\Cli\Commands;

use Tusk\Cli\CommandInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Events\Queue\DatabaseQueue;
use Tusk\Events\Queue\QueueInterface;

class QueueWorkerCommand implements CommandInterface
{
    public function __construct(private ?ContainerInterface $container = null) {}

    public function execute(array $args): int
    {
        echo "Starting queue worker...\n";

        // Use container to resolve queue if available, otherwise default to DB Queue
        $queue = $this->container ?
            ($this->container->has(QueueInterface::class) ? $this->container->get(QueueInterface::class) : new DatabaseQueue)
            : new DatabaseQueue;

        $running = true;
        while ($running) {
            $job = $queue->pop();

            if ($job) {
                echo "Processing Job: {$job['job_class']} (ID: {$job['id']})\n";

                try {
                    $jobInstance = new $job['job_class']($job['payload']);

                    if (method_exists($jobInstance, 'handle')) {
                        // Ideally we inject dependencies from container
                        $jobInstance->handle();
                    }

                    $queue->complete($job['id']);
                    echo "Completed Job: {$job['job_class']} (ID: {$job['id']})\n";

                } catch (\Throwable $e) {
                    echo "Failed Job: {$job['job_class']} (ID: {$job['id']}) - {$e->getMessage()}\n";
                    $queue->fail($job['id'], $e);
                }
            } else {
                usleep(500000); // Wait half a second before polling again
            }
        }

        // @phpstan-ignore-next-line
        return 0;
    }
}
