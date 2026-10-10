<?php

declare(strict_types=1);

namespace Tusk\Cli\Commands;

use Doctrine\Migrations\Generator\Exception\NoChangesDetected;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MakeMigrationCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->setName('make:migration')->setDescription('Generate a migration from the ORM schema diff')
            ->addArgument('description', InputArgument::OPTIONAL, 'Short migration description', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->safeExecute($input, $output, 'Migration generation', function () use ($input, $output): int {
            $factory = $this->projectFactory()->create();
            $description = trim((string) $input->getArgument('description'));
            $suffix = $description === '' ? '' : preg_replace('/[^A-Za-z0-9]+/', '', ucwords($description));
            $class = 'Version'.gmdate('YmdHis').$suffix;
            $namespace = array_key_first($factory->getConfiguration()->getMigrationDirectories());
            if (! is_string($namespace)) {
                throw new \RuntimeException('No migration namespace is configured.');
            }
            try {
                $path = $factory->getDiffGenerator()->generate($namespace.'\\'.$class, null, false, null, 120, true, false);
                if ($description !== '') {
                    $contents = file_get_contents($path);
                    if ($contents !== false) {
                        $contents = str_replace("return '';", 'return '.var_export($description, true).';', $contents);
                        file_put_contents($path, $contents);
                    }
                }
            } catch (NoChangesDetected) {
                $output->writeln('<comment>No schema changes detected; no migration file was created.</comment>');

                return self::SUCCESS;
            }
            $output->writeln('<info>Migration generated:</info> '.$path);

            return self::SUCCESS;
        });
    }
}
