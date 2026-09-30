<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds CatalogIndex from its two JSON sources on demand (wordfence:refresh
 * does it daily) — first-time bootstrap, or right after catalog:set/suggest.
 */
#[AsCommand(name: 'catalog:reindex', description: 'Rebuild the catalogue/vulnerability cross-reference index')]
final class CatalogReindexCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vulnCount = $this->app->catalogIndex()->rebuildVulnerabilities(
            (string) $this->app->config->get('data_dir') . '/reference/wordfence.json'
        );
        $catalogCount = $this->app->catalogIndex()->rebuildCatalog($this->app->softwareCatalog());

        $output->writeln("<info>vulnerabilities</info> : {$vulnCount} row(s) indexed.");
        $output->writeln("<info>catalogue</info>       : {$catalogCount} entrie(s) indexed.");

        return Command::SUCCESS;
    }
}
