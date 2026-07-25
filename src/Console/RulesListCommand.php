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
        $this
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Display language (en, fr)', 'en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getOption('category');
        $t      = $this->app->translator((string) $input->getOption('lang'));

        $rows = [];
        foreach ($this->app->ruleEngine()->rules() as $rule) {
            if ($filter !== null && stripos($rule->category, (string) $filter) === false) {
                continue;
            }

            $rows[] = [
                $rule->id,
                $t->category($rule->category),
                $rule->source,
                $t->severity($rule->severity->value),
                $rule->threshold === null ? '' : (string) $rule->threshold,
                $t->title($rule->id),
            ];
        }

        (new Table($output))
            ->setHeaders(['id', $t->ui('category'), 'source', $t->ui('severity'), 'threshold', $t->ui('rule')])
            ->setRows($rows)
            ->render();

        $output->writeln(sprintf('%d rules in the catalogue.', count($rows)));

        return Command::SUCCESS;
    }
}
