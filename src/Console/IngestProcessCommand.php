<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Console;

use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Storage\Index;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The cron worker: runs only extractions an analyst queued, never mere
 * arrivals, so a push spends no probe quota; the slow work stays out of web
 * requests. Each run also polls the remote audits earlier runs left pending.
 *
 * Crontab: * * * * * php /path/to/bin/swpmgr ingest:process --requeue-stale=30
 */
#[AsCommand(name: 'ingest:process', description: 'Run the probe pipeline on queued extractions')]
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

            $failures = $this->pollAudits($output);
            $queued   = $index->queuedExtractions((int) $input->getOption('limit'));

            if ($queued === []) {
                $output->writeln('No queued extractions.');

                return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
            }

            foreach ($queued as $row) {
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

    /** @return int 1 when polling failed, so the run exits non-zero */
    private function pollAudits(OutputInterface $output): int
    {
        try {
            foreach ($this->app->auditPoller()->pollPending(time()) as $line) {
                $output->writeln("SE Ranking audit {$line}");
            }
        } catch (Throwable $e) {
            $output->writeln('<error>SE Ranking polling failed: ' . $e->getMessage() . '</error>');

            return 1;
        }

        return 0;
    }
}
