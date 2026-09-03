<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sites:list', description: 'List known sites')]
final class SitesListCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('search', null, InputOption::VALUE_REQUIRED, 'Filter by URL, name or site_id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sites = $this->app->index()->listSites($input->getOption('search'));

        if ($sites === []) {
            $output->writeln('No sites.');

            return Command::SUCCESS;
        }

        $rows = array_map(static fn (array $s): array => [
            $s['site_id'],
            $s['site_url'] ?? '',
            $s['name'] ?? '',
            $s['last_extraction_received_at'] ?? '',
            $s['extraction_count'],
            $s['last_extraction_status'] ?? '',
        ], $sites);

        (new Table($output))
            ->setHeaders(['site_id', 'url', 'name', 'last extraction', 'extractions', 'last status'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
