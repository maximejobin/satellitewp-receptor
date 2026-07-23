<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'probe:list', description: 'List registered probes')]
final class ProbeListCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->app->probeRegistry();

        $rows = [];
        foreach ($registry->all() as $probe) {
            $rows[] = [
                $probe->name(),
                $probe->version(),
                $registry->isEnabled($probe->name()) ? 'yes' : 'no',
            ];
        }

        (new Table($output))
            ->setHeaders(['probe', 'version', 'enabled'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
