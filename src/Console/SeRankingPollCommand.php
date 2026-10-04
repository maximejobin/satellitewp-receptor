<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Console;

use SatelliteWP\Manager\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Polls pending SE Ranking audits now. ingest:process already does it every
 * run; this is the manual entry point (--force ignores the poll interval).
 */
#[AsCommand(name: 'seranking:poll', description: 'Check pending SE Ranking audits and store finished reports')]
final class SeRankingPollCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Poll every pending audit, even if checked recently');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Same lock as ingest:process, so a cron run never polls the same audit concurrently.
        $lock = fopen($this->app->config->get('data_dir') . '/ingest.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('ingest:process is running — try again in a moment.');

            return Command::FAILURE;
        }

        try {
            $lines = $this->app->auditPoller()->pollPending(time(), (bool) $input->getOption('force'));
            $output->writeln($lines === [] ? 'No pending audit due for a check.' : $lines);

            return Command::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
