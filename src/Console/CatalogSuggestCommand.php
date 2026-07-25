<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Enrich the catalogue with wp.org repository presence and a suggested licence.
 * A plugin/theme in the wp.org repo has a free version (suggest "free", an
 * analyst may refine to "mixed"); one absent from it is likely "premium".
 */
#[AsCommand(name: 'catalog:suggest', description: 'Check wp.org repo presence and suggest licences')]
final class CatalogSuggestCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('recheck', null, InputOption::VALUE_NONE, 'Re-check entries already checked');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $http = new Client(['timeout' => 15, 'http_errors' => false]);

        $updated = $this->app->softwareCatalog()->suggest(
            fn (string $type, string $slug): bool => $this->isOnWporg($http, $type, $slug),
            (bool) $input->getOption('recheck')
        );

        $output->writeln("<info>{$updated} entrie(s) checked against wp.org.</info>");

        return Command::SUCCESS;
    }

    private function isOnWporg(Client $http, string $type, string $slug): bool
    {
        $url = $type === 'theme'
            ? 'https://api.wordpress.org/themes/info/1.1/?action=theme_information&request[slug]=' . rawurlencode($slug)
            : 'https://api.wordpress.org/plugins/info/1.0/' . rawurlencode($slug) . '.json';

        try {
            $response = $http->get($url);
        } catch (GuzzleException) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $decoded = json_decode((string) $response->getBody(), true);

        // Both APIs answer null / {"error":...} when the slug is unknown.
        return is_array($decoded) && !isset($decoded['error']) && ($decoded['slug'] ?? null) !== null;
    }
}
