<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Refresh the local Wordfence Intelligence vulnerability index. Run on a
 * schedule — suggested crontab: daily. The API is strictly rate-limited (the
 * feed is a ~100+ MB full dump, not a per-site call), so this must never run
 * more than roughly once a day; site scans always read the local cache.
 */
#[AsCommand(name: 'wordfence:refresh', description: 'Refresh the Wordfence Intelligence vulnerability index')]
final class WordfenceRefreshCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    /**
     * Each feed is a ~100 MB JSON document decoded whole, then the second is
     * decoded while the first's index is held — the 128M CLI default dies and
     * 512M is too tight. A higher or unlimited setting is left alone.
     */
    private const string MIN_MEMORY_LIMIT = '1G';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        self::raiseMemoryLimit();

        try {
            $result = $this->app->wordfenceIndex()->refresh();
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln("<info>production</info> : {$result['production']} vulnérabilités reçues.");
        $output->writeln("<info>scanner</info>    : {$result['scanner']} vulnérabilités reçues.");
        $output->writeln("<info>index</info>      : {$result['index_entries']} entrées (plugin/thème/core).");

        foreach ($result['errors'] as $error) {
            $output->writeln("<comment>{$error}</comment>");
        }

        // Rebuild the SQLite index from whatever the cache now holds, catalogue side included.
        $vulnCount    = $this->app->catalogIndex()->rebuildVulnerabilities(
            (string) $this->app->config->get('data_dir') . '/reference/wordfence.json'
        );
        $catalogCount = $this->app->catalogIndex()->rebuildCatalog($this->app->softwareCatalog());
        $output->writeln("<info>reindex</info>    : {$vulnCount} vulnérabilité(s), {$catalogCount} entrée(s) catalogue.");

        return $result['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private static function raiseMemoryLimit(): void
    {
        $current = self::toBytes((string) ini_get('memory_limit'));

        // -1 (unlimited) reads as < 0 and must never be clamped down.
        if ($current >= 0 && $current < self::toBytes(self::MIN_MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MIN_MEMORY_LIMIT);
        }
    }

    /** PHP ini shorthand ("512M", "1G", "-1") to bytes; -1 stays -1. */
    private static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit   = strtoupper(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'G'     => $number * 1024 ** 3,
            'M'     => $number * 1024 ** 2,
            'K'     => $number * 1024,
            default => $number,
        };
    }
}
