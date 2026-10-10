<?php

declare(strict_types=1);

namespace Tusk\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\CommandCompiler;
use Tusk\Core\Container\CompilerPipeline;
use Tusk\Core\Container\ContainerCompiler;
use Tusk\Core\Container\ServiceScanner;
use Tusk\Web\Router\RouteCompiler;

final class BuildCommand extends Command
{
    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct('build');
    }

    protected function configure(): void
    {
        $this->setDescription('Compiles the Dependency Injection Container, Routes, and Commands.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Building Tusk Framework...</info>');
        $output->writeln('Compiling Container...');
        (new CompilerPipeline(new ServiceScanner, new ContainerCompiler))->compileAndDump(
            [$this->projectRoot], $this->projectRoot.'/.tusk/CompiledContainer.php',
        );
        $output->writeln('Compiling Routes...');
        (new RouteCompiler)->compileAndDump([$this->projectRoot], $this->projectRoot.'/.tusk/CompiledRouter.php');
        $output->writeln('Compiling Commands...');
        (new CommandCompiler)->compileAndDump([$this->projectRoot], $this->projectRoot.'/.tusk/CompiledCommandRegistry.php');
        $output->writeln('<info>Build completed successfully.</info>');

        return self::SUCCESS;
    }
}
