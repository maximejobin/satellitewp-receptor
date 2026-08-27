<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Http\PayloadValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keys:rebind', description: 'Point a site key at a new address after the site moves')]
final class KeysRebindCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('site_id', InputArgument::REQUIRED, 'Site UUID (X-SWP-Site)')
            ->addArgument('url', InputArgument::REQUIRED, 'The address the site now answers on');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteId = (string) $input->getArgument('site_id');

        if (!PayloadValidator::isUuid($siteId)) {
            $output->writeln('<error>site_id must be a UUID</error>');

            return Command::INVALID;
        }

        $origin = PayloadValidator::normalizeOrigin((string) $input->getArgument('url'));

        if ($origin === '') {
            $output->writeln('<error>url is empty</error>');

            return Command::INVALID;
        }

        $keys     = $this->app->keyStore();
        $previous = $keys->getOrigin($siteId);

        if (!$keys->setOrigin($siteId, $origin)) {
            $output->writeln("<error>No key registered for {$siteId}</error>");

            return Command::FAILURE;
        }

        $output->writeln($previous === null
            ? "Bound {$siteId} to <info>{$origin}</info>."
            : "Rebound {$siteId} from {$previous} to <info>{$origin}</info>.");
        $output->writeln('The site keeps its id, so its extraction history is preserved.');

        return Command::SUCCESS;
    }
}
