<?php

namespace Tusk\Cli\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Contracts\Attributes\Service;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Events\Queue\QueueInterface;

#[Service]
#[AsCommand('queue:work', 'Start the queue worker')]
class QueueWorkerCommand extends Command
{
    public function __construct(
        private ContainerInterface $container,
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
        $output->writeln('<info>Starting queue worker...</info>');
        $this->logger?->info('Starting queue worker...');

        $queue = $this->container->get(QueueInterface::class);

        while (true) {
            $job = $queue->pop();

            if ($job) {
                $jobClass = $job['job_class'];
                $output->writeln("Processing Job: {$jobClass} (ID: {$job['id']})");
                $this->logger?->info("Processing Job: {$jobClass}", ['id' => $job['id']]);

                try {
                    $jobInstance = $this->container->has($jobClass)
                        ? $this->container->get($jobClass)
                        : new $jobClass($job['payload']);

                    if (method_exists($jobInstance, 'handle')) {
                        $jobInstance->handle($job['payload']);
                    }

                    $queue->complete($job['id']);
                    $output->writeln("<info>Completed Job: {$jobClass} (ID: {$job['id']})</info>");
                    $this->logger?->info("Completed Job: {$jobClass}", ['id' => $job['id']]);

                } catch (\Throwable $e) {
                    $output->writeln("<error>Failed Job: {$jobClass} (ID: {$job['id']}) - {$e->getMessage()}</error>");
                    $this->logger?->error("Failed Job: {$jobClass}", ['id' => $job['id'], 'error' => $e]);
                    $queue->fail($job['id'], $e);
                }
            } else {
                usleep(500_000); // 0.5s before next poll
            }
        }

        /** @phpstan-ignore-next-line */
        return self::SUCCESS;
    }
}
