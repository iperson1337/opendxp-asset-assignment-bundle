<?php

namespace Iperson1337\AssetAssignmentBundle\Command;

use Iperson1337\AssetAssignmentBundle\Service\AssetAssignmentService;
use Iperson1337\AssetAssignmentBundle\Service\ExecutionLockService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-assignment:execute',
    description: 'Execute asset assignment process for a configuration'
)]
class ExecuteAssignmentCommand extends Command
{
    public function __construct(
        private readonly AssetAssignmentService $assetAssignmentService,
        private readonly ExecutionLockService $executionLockService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'config_name',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'Names of configurations that should be executed'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate execution without making changes'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configNames = $input->getArgument('config_name');
        $dryRun = $input->getOption('dry-run');
        $verbose = $input->getOption('verbose');

        if (empty($configNames)) {
            $io->error('No configuration name provided');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->warning('Dry run mode is not yet implemented. Skipping execution.');
            return Command::SUCCESS;
        }

        foreach ($configNames as $configName) {
            $io->section("Executing asset assignment for configuration '{$configName}'");

            if (!$this->executionLockService->tryAcquire($configName)) {
                $io->error("Execution already running for '{$configName}'. Another process holds the lock.");
                return Command::FAILURE;
            }

            try {
                if ($verbose) {
                    $io->text('Initializing execution...');
                }
                $this->assetAssignmentService->initializeExecution($configName);

                if ($verbose) {
                    $io->text('Starting execution...');
                }
                $success = $this->assetAssignmentService->startExecution($configName);

                if ($success) {
                    $io->success("Asset assignment for '{$configName}' completed successfully");
                } else {
                    $io->error("Asset assignment for '{$configName}' failed. Check logs for details.");
                }
            } catch (\Throwable $e) {
                $io->error("Error executing '{$configName}': " . $e->getMessage());
                if ($verbose) {
                    $io->text($e->getFile() . ':' . $e->getLine());
                }
                return Command::FAILURE;
            } finally {
                $this->executionLockService->release($configName);
            }
        }

        return Command::SUCCESS;
    }
}
