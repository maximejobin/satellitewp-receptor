<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Refresh server-side reference data (EOL tables) from endoflife.date.
 * Run on a schedule — the data changes rarely, and rule evaluation reads the
 * cache offline. Suggested crontab: weekly.
 */
#[AsCommand(name: 'reference:refresh', description: 'Refresh EOL reference data from endoflife.date')]
final class ReferenceRefreshCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $default = (array) $this->app->config->get('reference.products', ['php', 'wordpress']);

        $this->addOption(
            'product',
            null,
            InputOption::VALUE_REQUIRED,
            'Comma-separated endoflife.date products',
            implode(',', $default)
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $products = array_map('trim', explode(',', (string) $input->getOption('product')));

        try {
            $counts = $this->app->endOfLife()->refresh($products);
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        foreach ($counts as $product => $count) {
            $output->writeln("<info>{$product}</info> : {$count} cycles mis en cache.");
        }

        // The explicit per-version wordpress.org list (used by /data/wp-versions
        // for its secure/insecure/uptodate status) is a distinct source from
        // endoflife.date's per-branch cycles above, but it changes on the same
        // rhythm — every "wordpress" refresh keeps both in step with one command.
        if (in_array('wordpress', $products, true)) {
            try {
                $count = $this->app->wordPressVersions()->refresh();
                $output->writeln("<info>wordpress (versions explicites)</info> : {$count} versions mises en cache.");
            } catch (Throwable $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
