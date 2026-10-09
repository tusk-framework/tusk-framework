<?php

declare(strict_types=1);

namespace Tusk\Cli\Commands;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Tusk\Cloud\Resilience\Configuration\ResilienceConfigurationLoader;
use Tusk\Config\ProjectConfigurationLoader;

final class ConfigValidateCommand extends Command
{
    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        parent::__construct();
        $this->basePath = $basePath ?? getcwd() ?: '.';
    }

    protected function configure(): void
    {
        $this->setName('config:validate')
            ->setDescription('Validate the application resilience configuration')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Select the resilience configuration profile');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $resilience = ProjectConfigurationLoader::loadResilience($this->basePath);

            $profileOption = $input->getOption('profile');
            if ($profileOption === null) {
                $profile = getenv('APP_ENV');
                $profile = is_string($profile) && trim($profile) !== '' ? trim($profile) : 'production';
            } elseif (is_string($profileOption)) {
                $profile = $profileOption;
            } else {
                throw new InvalidArgumentException('The --profile option must be a string.');
            }

            ResilienceConfigurationLoader::load($resilience, $profile);
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>Resilience configuration is invalid: '.$exception->getMessage().'</error>');

            return self::FAILURE;
        } catch (Throwable) {
            $output->writeln('<error>Project configuration could not be loaded; check the PHP files in config/.</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf('<info>Resilience configuration is valid (profile: %s).</info>', $profile));

        return self::SUCCESS;
    }
}
