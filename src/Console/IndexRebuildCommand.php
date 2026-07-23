<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'index:rebuild', description: 'Regenerate the SQLite index from the data/ tree')]
final class IndexRebuildCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->app->index()->rebuildFrom($this->app->dataStore());

        $output->writeln("<info>Index rebuilt: {$count} extraction(s) indexed.</info>");

        return Command::SUCCESS;
    }
}
