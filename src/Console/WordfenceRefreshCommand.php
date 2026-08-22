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
     * Each feed variant is a single JSON document of 78-117 MB, and decoding
     * one costs several times that in PHP arrays. The usual CLI default of
     * 128M dies with an opaque "Allowed memory size exhausted" fatal, so the
     * command raises its own ceiling rather than depending on the caller
     * remembering `-d memory_limit=…`. An already-higher (or unlimited)
     * setting is left alone.
     *
     * Sized from a real measurement, not a guess: decoding the 78 MB scanner
     * feed peaks at 284 MB and still holds 274 MB once its index is built —
     * and refresh() then decodes production (~1.5x larger) while holding that.
     * 512M was measurably too tight.
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
