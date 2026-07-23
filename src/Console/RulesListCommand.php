<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rules:list', description: 'List the rule catalogue')]
final class RulesListCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getOption('category');

        $rows = [];
        foreach ($this->app->ruleEngine()->rules() as $rule) {
            if ($filter !== null && stripos($rule->category, (string) $filter) === false) {
                continue;
            }

            $rows[] = [
                $rule->id,
                $rule->category,
                $rule->source,
                $rule->severity->label(),
                $rule->threshold === null ? '' : (string) $rule->threshold,
                $rule->title,
            ];
        }

        (new Table($output))
            ->setHeaders(['id', 'catégorie', 'source', 'sévérité', 'seuil', 'règle'])
            ->setRows($rows)
            ->render();

        $output->writeln(sprintf('%d règles au catalogue.', count($rows)));

        return Command::SUCCESS;
    }
}
