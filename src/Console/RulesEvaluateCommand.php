<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Rules\Context;
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

        $this->renderTable($output, $findings, (bool) $input->getOption('all'));

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $findings */
    private function renderTable(OutputInterface $output, array $findings, bool $showAll): void
    {
        $rows = [];
        foreach ($findings['findings'] as $finding) {
            if (!$showAll && $finding['status'] !== 'fail') {
                continue;
            }

            $status = match ($finding['status']) {
                'fail'    => '<error>' . $finding['severity_label'] . '</error>',
                'pass'    => '<info>conforme</info>',
                'na'      => '<comment>n/a</comment>',
                default   => 'indéterminé',
            };

            $rows[] = [
                $finding['id'],
                $finding['category'],
                $status,
                $finding['title'],
                $finding['message'] ?? $finding['detail'] ?? '',
            ];
        }

        if ($rows !== []) {
            (new Table($output))
                ->setHeaders(['id', 'catégorie', 'verdict', 'règle', 'message'])
                ->setRows($rows)
                ->render();
        } elseif (!$showAll) {
            $output->writeln('<info>Aucun constat en échec.</info>');
        }

        $counts   = $findings['counts'];
        $severity = $counts['by_severity'];

        $output->writeln(sprintf(
            "\n%d règles évaluées — <error>%d en échec</error> (C:%d É:%d M:%d I:%d), %d conformes, %d n/a, %d indéterminées",
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
