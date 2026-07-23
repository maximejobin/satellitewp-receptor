<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pipeline:run', description: 'Run the probe pipeline on an extraction (default: latest)')]
final class PipelineRunCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('site_id', InputArgument::REQUIRED, 'Site UUID')
            ->addArgument('extraction_id', InputArgument::OPTIONAL, 'Extraction id (default: latest)')
            ->addOption('probe', null, InputOption::VALUE_REQUIRED, 'Comma-separated probe names to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteId       = (string) $input->getArgument('site_id');
        $extractionId = $input->getArgument('extraction_id')
            ?? $this->app->dataStore()->latestExtractionId($siteId);

        if ($extractionId === null) {
            $output->writeln('<error>No extraction found for this site.</error>');

            return Command::FAILURE;
        }

        $only = $input->getOption('probe') !== null
            ? array_map('trim', explode(',', (string) $input->getOption('probe')))
            : null;

        $results = $this->app->pipeline()->run($siteId, $extractionId, $only);

        foreach ($results as $result) {
            $tag = match ($result->status) {
                'ok'    => '<info>ok</info>',
                'warn'  => '<comment>warn</comment>',
                default => '<error>' . $result->status . '</error>',
            };
            $output->writeln(sprintf('%-6s %s (%d ms)', $result->probe, $tag, $result->durationMs));

            foreach ($result->errors as $error) {
                $output->writeln("       <error>{$error}</error>");
            }
        }

        $output->writeln("Summary written for {$siteId}/{$extractionId}.");

        return Command::SUCCESS;
    }
}
