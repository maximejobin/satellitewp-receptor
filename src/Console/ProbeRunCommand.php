<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'probe:run', description: 'Run a single probe on an extraction and print its envelope')]
final class ProbeRunCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('probe', InputArgument::REQUIRED, 'Probe name (see probe:list)')
            ->addArgument('site_id', InputArgument::REQUIRED, 'Site UUID')
            ->addArgument('extraction_id', InputArgument::OPTIONAL, 'Extraction id (default: latest)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $probeName    = (string) $input->getArgument('probe');
        $siteId       = (string) $input->getArgument('site_id');
        $extractionId = $input->getArgument('extraction_id')
            ?? $this->app->dataStore()->latestExtractionId($siteId);

        if ($extractionId === null) {
            $output->writeln('<error>No extraction found for this site.</error>');

            return Command::FAILURE;
        }

        if (!$this->app->probeRegistry()->has($probeName)) {
            $output->writeln("<error>Unknown probe \"{$probeName}\" — see probe:list.</error>");

            return Command::INVALID;
        }

        $result = $this->app->pipeline()->runSingleProbe($siteId, $extractionId, $probeName);

        $output->writeln((string) json_encode(
            $result->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return $result->status === 'error' ? Command::FAILURE : Command::SUCCESS;
    }
}
