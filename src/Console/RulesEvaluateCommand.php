<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Rules\Context;
use SatelliteWP\Xtractor\Rules\Translator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-evaluates the rule catalogue against a stored extraction. Makes no network
 * calls, so it is cheap to run after editing thresholds or the catalogue.
 */
#[AsCommand(name: 'rules:evaluate', description: 'Evaluate the rule catalogue against an extraction')]
final class RulesEvaluateCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('site_id', InputArgument::REQUIRED, 'Site UUID')
            ->addArgument('extraction_id', InputArgument::OPTIONAL, 'Extraction id (default: latest)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show passing and non-applicable rules too')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Display language (en, fr)', 'en')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print findings.json instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteId       = (string) $input->getArgument('site_id');
        $store        = $this->app->dataStore();
        $extractionId = $input->getArgument('extraction_id') ?? $store->latestExtractionId($siteId);

        if ($extractionId === null) {
            $output->writeln('<error>No extraction found for this site.</error>');

            return Command::FAILURE;
        }

        $payload = $store->readExtractionPayload($siteId, $extractionId);
        if ($payload === null) {
            $output->writeln("<error>Extraction {$extractionId} not found.</error>");

            return Command::FAILURE;
        }

        $findings = $this->app->ruleEngine()->evaluate(
            new Context(
                $payload,
                $store->readAllProbeResults($siteId, $extractionId),
                $this->app->referenceData()
            )
        );
        $findings['site_id']       = $siteId;
        $findings['extraction_id'] = $extractionId;

        $store->writeFindings($siteId, $extractionId, $findings);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                $findings,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return Command::SUCCESS;
        }

        $this->renderTable(
            $output,
            $findings,
            $this->app->translator((string) $input->getOption('lang')),
            (bool) $input->getOption('all')
        );

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $findings */
    private function renderTable(OutputInterface $output, array $findings, Translator $t, bool $showAll): void
    {
        $rows = [];
        foreach ($findings['findings'] as $finding) {
            if (!$showAll && $finding['status'] !== 'fail') {
                continue;
            }

            $status = match ($finding['status']) {
                'fail'    => '<error>' . $t->severity($finding['severity']) . '</error>',
                'pass'    => '<info>' . $t->status('pass') . '</info>',
                'na'      => '<comment>' . $t->status('na') . '</comment>',
                default   => $t->status('unknown'),
            };

            $rows[] = [
                $finding['id'],
                $t->category($finding['category']),
                $status,
                $t->title($finding['id']),
                $t->message($finding) ?? '',
            ];
        }

        if ($rows !== []) {
            (new Table($output))
                ->setHeaders(['id', $t->ui('category'), $t->ui('status'), $t->ui('rule'), $t->ui('observation')])
                ->setRows($rows)
                ->render();
        }

        $counts   = $findings['counts'];
        $severity = $counts['by_severity'];

        $output->writeln(sprintf(
            "\n%d rules — <error>%d failing</error> (C:%d E:%d M:%d I:%d), %d pass, %d n/a, %d unknown",
            $counts['total'],
            $counts['fail'],
            $severity['C'],
            $severity['E'],
            $severity['M'],
            $severity['I'],
            $counts['pass'],
            $counts['na'],
            $counts['unknown']
        ));
    }
}
