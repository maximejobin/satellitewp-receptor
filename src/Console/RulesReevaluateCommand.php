<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Rules\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-scores every stored "done" extraction against the CURRENT rule
 * catalogue — no network, no re-probing, just RuleEngine::evaluate() run
 * again over the payload + probe results already on disk (same mechanism
 * `rules:evaluate` uses for one extraction; this just loops it over
 * everything so a rules.php edit can be seen across the whole real dataset
 * instead of one extraction at a time). Prints a before/after pastille
 * count per extraction whose result actually changed, and a totals line —
 * this is the "did my rule change do what I think it did, everywhere"
 * check (2026-09-07, user request).
 */
#[AsCommand(name: 'rules:reevaluate', description: 'Re-score every stored extraction against the current rule catalogue')]
final class RulesReevaluateCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site_id', InputArgument::OPTIONAL, 'Limit to one site (default: every site)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store     = $this->app->dataStore();
        $index     = $this->app->index();
        $engine    = $this->app->ruleEngine();
        $reference = $this->app->referenceData();

        $siteIdArg = $input->getArgument('site_id');
        $sites     = $siteIdArg !== null ? [['site_id' => $siteIdArg]] : $index->listSites();

        $scored  = 0;
        $changed = 0;
        foreach ($sites as $site) {
            $siteId = (string) $site['site_id'];
            foreach ($index->listExtractions($siteId) as $extraction) {
                if (($extraction['status'] ?? null) !== 'done') {
                    continue;
                }
                $extractionId = (string) $extraction['id'];
                $payload      = $store->readExtractionPayload($siteId, $extractionId);
                if ($payload === null) {
                    continue;
                }

                $before = $store->readFindings($siteId, $extractionId);
                $after  = $engine->evaluate(new Context(
                    $payload,
                    $store->readAllProbeResults($siteId, $extractionId),
                    $reference
                ));
                $after['site_id']       = $siteId;
                $after['extraction_id'] = $extractionId;
                $store->writeFindings($siteId, $extractionId, $after);
                $scored++;

                $diff = $this->diffLine($before, $after);
                if ($diff !== null) {
                    $changed++;
                    $output->writeln("{$siteId}/{$extractionId}  {$diff}");
                }
            }
        }

        $output->writeln("{$scored} extraction(s) re-scored, {$changed} changed.");

        return Command::SUCCESS;
    }

    /**
     * One "red 9->6, orange 19->21" style line per pastille that actually moved, or null when nothing did.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $after
     */
    private function diffLine(?array $before, array $after): ?string
    {
        $beforeCounts = (array) ($before['counts']['by_pastille'] ?? []);
        $afterCounts  = (array) ($after['counts']['by_pastille'] ?? []);

        $parts = [];
        foreach (['red', 'orange', 'blue', 'green', 'grey'] as $pastille) {
            $b = (int) ($beforeCounts[$pastille] ?? 0);
            $a = (int) ($afterCounts[$pastille] ?? 0);
            if ($b !== $a) {
                $parts[] = "{$pastille} {$b}->{$a}";
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }
}
