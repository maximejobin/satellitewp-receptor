<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Storage\Index;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The cron entry point: processes pending extractions.
 * Crontab: * * * * * php /path/to/bin/xtractor ingest:process
 */
#[AsCommand(name: 'ingest:process', description: 'Run the probe pipeline on pending extractions')]
final class IngestProcessCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max extractions to process', '10')
            ->addOption('requeue-stale', null, InputOption::VALUE_REQUIRED, 'Requeue "running" older than N minutes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = fopen($this->app->config->get('data_dir') . '/ingest.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('Another ingest:process is running — nothing to do.');

            return Command::SUCCESS;
        }

        try {
            $index = $this->app->index();

            if (($stale = $input->getOption('requeue-stale')) !== null) {
                $requeued = $index->requeueStale((int) $stale);
                if ($requeued > 0) {
                    $output->writeln("Requeued {$requeued} stale extraction(s).");
                }
            }

            $pending = $index->pendingExtractions((int) $input->getOption('limit'));

            if ($pending === []) {
                $output->writeln('No pending extractions.');

                return Command::SUCCESS;
            }

            $failures = 0;
            foreach ($pending as $row) {
                $siteId       = (string) $row['site_id'];
                $extractionId = (string) $row['id'];
                $output->write("Processing {$siteId}/{$extractionId} … ");

                try {
                    $results  = $this->app->pipeline()->run($siteId, $extractionId);
                    $statuses = array_map(static fn ($r) => $r->probe . ':' . $r->status, $results);
                    $output->writeln('<info>done</info> [' . implode(' ', $statuses) . ']');
                } catch (Throwable $e) {
                    $failures++;
                    $index->setExtractionStatus($siteId, $extractionId, Index::STATUS_ERROR);
                    $output->writeln('<error>failed: ' . $e->getMessage() . '</error>');
                }
            }

            return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
