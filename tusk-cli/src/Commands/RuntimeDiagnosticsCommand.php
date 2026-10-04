<?php

declare(strict_types=1);

namespace Tusk\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Contracts\Attributes\Service;
use Tusk\Contracts\Observability\WorkerDiagnosticsInterface;
use Tusk\Runtime\Observability\WorkerDiagnosticsCollector;

#[Service]
#[AsCommand('runtime:diagnostics', 'Show persistent runtime diagnostics')]
final class RuntimeDiagnosticsCommand extends Command
{
    private readonly WorkerDiagnosticsInterface $diagnostics;

    public function __construct(?WorkerDiagnosticsInterface $diagnostics = null)
    {
        parent::__construct();
        $this->diagnostics = $diagnostics ?? new WorkerDiagnosticsCollector;
    }

    protected function configure(): void
    {
        $this->setName('runtime:diagnostics')
            ->setDescription('Show persistent runtime diagnostics')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Render the stable diagnostics snapshot as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = $this->diagnostics->snapshot()->toArray();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $output->writeln('<info>Local runtime diagnostics snapshot</info>');
        $output->writeln('Worker: '.$data['worker_id']);
        $output->writeln('Runtime: '.$data['runtime']);
        $output->writeln('Lifecycle state: '.$data['lifecycle_state']);
        $output->writeln(sprintf(
            'Requests: %d total, %d failed; jobs: %d total, %d failed.',
            $data['requests_total'],
            $data['request_failures'],
            $data['jobs_total'],
            $data['job_failures'],
        ));
        $output->writeln(sprintf(
            'Memory: %d current, %d peak; telemetry failures: %d.',
            $data['current_memory_bytes'],
            $data['peak_memory_bytes'],
            $data['telemetry_failures'],
        ));
        $output->writeln('<comment>This is not a remote worker health check.</comment>');

        return self::SUCCESS;
    }
}
