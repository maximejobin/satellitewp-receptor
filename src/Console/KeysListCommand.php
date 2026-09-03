<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keys:list', description: 'List registered site API keys (redacted)')]
final class KeysListCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->app->keyStore()->all() as $siteId => $entry) {
            $key    = (string) ($entry['api_key'] ?? '');
            $rows[] = [
                $siteId,
                $entry['origin'] ?? '',
                $key !== '' ? substr($key, 0, 6) . '…' : '',
                $entry['created_at'] ?? '',
                empty($entry['revoked']) ? 'active' : 'revoked',
            ];
        }

        if ($rows === []) {
            $output->writeln('No keys registered.');

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['site_id', 'origin', 'key', 'created_at', 'status'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
