<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'catalog:list', description: 'List catalogued plugins/themes and their licensing')]
final class CatalogListCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by type (plugin, theme)')
            ->addOption('needs-license', null, InputOption::VALUE_NONE, 'Only entries that likely need a paid licence');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->app->softwareCatalog()->all($input->getOption('type'), (bool) $input->getOption('needs-license')) as $e) {
            $effective = SoftwareCatalog::effectiveLicense($e);
            $rows[] = [
                $e['type'],
                $e['slug'],
                $e['name'],
                $e['license'] === SoftwareCatalog::LICENSE_UNKNOWN && $e['suggested']
                    ? $e['suggested'] . ' (suggested)'
                    : $e['license'],
                $e['source'],
                SoftwareCatalog::needsLicense($e) ? '<comment>licence?</comment>' : '',
            ];
        }

        if ($rows === []) {
            $output->writeln('Catalogue is empty (no extraction processed yet).');

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['type', 'slug', 'name', 'license', 'source', ''])
            ->setRows($rows)
            ->render();

        $output->writeln(sprintf('%d entries.', count($rows)));

        return Command::SUCCESS;
    }
}
