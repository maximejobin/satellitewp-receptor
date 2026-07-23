<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keys:revoke', description: 'Revoke the API key of a site')]
final class KeysRevokeCommand extends Command
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

        if (!$this->app->keyStore()->revokeKey($siteId)) {
            $output->writeln("<error>No key found for {$siteId}</error>");

            return Command::FAILURE;
        }

        $output->writeln("Key revoked for {$siteId}.");

        return Command::SUCCESS;
    }
}
