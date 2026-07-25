<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'catalog:set', description: 'Set the licence of a catalogued plugin/theme')]
final class CatalogSetCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'plugin | theme')
            ->addArgument('slug', InputArgument::REQUIRED, 'wp.org-style slug')
            ->addArgument('license', InputArgument::REQUIRED, 'free | premium | mixed | unknown');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type    = (string) $input->getArgument('type');
        $slug    = (string) $input->getArgument('slug');
        $license = (string) $input->getArgument('license');

        if (!in_array($license, SoftwareCatalog::LICENSES, true)) {
            $output->writeln('<error>License must be one of: ' . implode(', ', SoftwareCatalog::LICENSES) . '</error>');

            return Command::INVALID;
        }

        if (!$this->app->softwareCatalog()->setLicense($type, $slug, $license)) {
            $output->writeln("<error>No catalogue entry for {$type} \"{$slug}\".</error>");

            return Command::FAILURE;
        }

        $output->writeln("<info>{$type} {$slug} → {$license}</info>");

        return Command::SUCCESS;
    }
}
