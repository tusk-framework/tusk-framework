<?php

namespace Tusk\Cli\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Contracts\Attributes\Service;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Events\Queue\DatabaseQueue;
use Tusk\Events\Queue\QueueInterface;

#[Service]
#[AsCommand('queue:work', 'Start the queue worker')]
class QueueWorkerCommand extends Command
{
    public function __construct(
        private ?ContainerInterface $container = null,
        private ?LoggerInterface $logger = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('queue:work')
             ->setDescription('Start processing jobs on the queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info>Starting queue worker...</info>");
        if ($this->logger) {
            $this->logger->info('Starting queue worker...');
        }

        // Use container to resolve queue if available, otherwise default to DB Queue
        $queue = $this->container ?
            ($this->container->has(QueueInterface::class) ? $this->container->get(QueueInterface::class) : new DatabaseQueue)
            : new DatabaseQueue;

        $running = true;
        while ($running) {
            $job = $queue->pop();

            if ($job) {
                $output->writeln("Processing Job: {$job['job_class']} (ID: {$job['id']})");
                if ($this->logger) {
                    $this->logger->info("Processing Job: {$job['job_class']}", ['id' => $job['id']]);
                }

                try {
                    $jobClass = $job['job_class'];
                    $payload = $job['payload'];
                    
                    if ($this->container && $this->container->has($jobClass)) {
                        $jobInstance = $this->container->get($jobClass);
                        if (method_exists($jobInstance, 'setPayload')) {
                            $jobInstance->setPayload($payload);
                        }
                    } else {
                        $jobInstance = new $jobClass($payload);
                    }

                    if (method_exists($jobInstance, 'handle')) {
                        // Pass payload to handle just in case it doesn't use setPayload
                        $jobInstance->handle($payload);
                    }

                    $queue->complete($job['id']);
                    $output->writeln("<info>Completed Job: {$jobClass} (ID: {$job['id']})</info>");
                    if ($this->logger) {
                        $this->logger->info("Completed Job: {$jobClass}", ['id' => $job['id']]);
                    }

                } catch (\Throwable $e) {
                    $output->writeln("<error>Failed Job: {$job['job_class']} (ID: {$job['id']}) - {$e->getMessage()}</error>");
                    if ($this->logger) {
                        $this->logger->error("Failed Job: {$job['job_class']}", [
                            'id' => $job['id'],
                            'error' => $e
                        ]);
                    }
                    $queue->fail($job['id'], $e);
                }
            } else {
                usleep(500000); // Wait half a second before polling again
            }
        }

        /** @phpstan-ignore-next-line */
        return self::SUCCESS;
    }
}
