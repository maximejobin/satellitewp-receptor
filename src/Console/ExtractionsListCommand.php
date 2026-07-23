<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'extractions:list', description: 'List extractions of a site with probe statuses')]
final class ExtractionsListCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site_id', InputArgument::REQUIRED, 'Site UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteId = (string) $input->getArgument('site_id');
        $index  = $this->app->index();

        $extractions = $index->listExtractions($siteId);

        if ($extractions === []) {
            $output->writeln('No extractions for this site.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($extractions as $extraction) {
            $probes = [];
            foreach ($index->listProbeRuns($siteId, (string) $extraction['id']) as $run) {
                $probes[] = $run['probe'] . ':' . $run['status'];
            }

            $rows[] = [
                $extraction['id'],
                $extraction['received_at'],
                $extraction['wp_version'] ?? '',
                $extraction['php_version'] ?? '',
                $extraction['status'],
                implode(' ', $probes),
            ];
        }

        (new Table($output))
            ->setHeaders(['extraction', 'received_at', 'wp', 'php', 'status', 'probes'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
