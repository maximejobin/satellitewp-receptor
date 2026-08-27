<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Http\PayloadValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keys:add', description: 'Register (or replace) the API key for a site')]
final class KeysAddCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('site_id', InputArgument::REQUIRED, 'Site UUID (X-SWP-Site)')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'Explicit API key (generated when omitted)')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Human-readable label')
            ->addOption('origin', null, InputOption::VALUE_REQUIRED, 'Site address to bind the key to (defaults to the first extraction received)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteId = (string) $input->getArgument('site_id');

        if (!PayloadValidator::isUuid($siteId)) {
            $output->writeln('<error>site_id must be a UUID</error>');

            return Command::INVALID;
        }

        $origin = $input->getOption('origin');
        $origin = is_string($origin) && $origin !== ''
            ? PayloadValidator::normalizeOrigin($origin)
            : null;

        $key = $this->app->keyStore()->addKey(
            $siteId,
            $input->getOption('key'),
            $input->getOption('label'),
            $origin
        );

        $output->writeln("API key for {$siteId} (shown once — paste it into the plugin's Pairing screen,");
        $output->writeln('or define it as SWP_API_KEY in wp-config.php):');
        $output->writeln("<info>{$key}</info>");

        $output->writeln($origin === null
            ? 'Not bound to an address yet: the first extraction received will bind it.'
            : "Bound to <info>{$origin}</info>; extractions from any other address are refused.");

        return Command::SUCCESS;
    }
}
