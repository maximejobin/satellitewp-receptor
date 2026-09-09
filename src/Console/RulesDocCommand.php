<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use ReflectionFunction;
use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Rules\RuleCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders config/rules.php as a readable Markdown reference — one entry per
 * rule with its category/source/severity/threshold, its French pass/fail
 * sentences, AND the exact PHP of its check() closure (pulled straight from
 * the file via reflection on the real Closure, not retyped by hand).
 *
 * This replaces the old hand-maintained HTML catalogue artifact (2026-09-07,
 * user: "j'ai le goût de scrapper ton catalogue... rends le tout efficace")
 * — that file needed a manual edit, in prose I wrote by hand, every time a
 * rule changed, which is exactly the kind of drift risk this project's own
 * golden rules warn about elsewhere. This command has nothing to keep in
 * sync: it reads the live catalogue, so `php bin/xtractor rules:doc >
 * docs/rules-catalog.md` after any change is the whole workflow, and the
 * code block for each rule IS the logic — no paraphrase to double check
 * against the source.
 */
#[AsCommand(name: 'rules:doc', description: 'Render the rule catalogue as Markdown (writes to stdout)')]
final class RulesDocCommand extends Command
{
    private const string CATALOG = __DIR__ . '/../../config/rules.php';

    /** Letter prefix -> human label, same grouping as the section banners inside config/rules.php itself. */
    private const array GROUPS = [
        'A'  => 'TLS / SSL',
        'B'  => 'En-têtes HTTP & réseau',
        'C'  => 'DNS & disponibilité',
        'D'  => 'Délivrabilité e-mail (DNS)',
        'W'  => 'Domaine (WHOIS/RDAP)',
        'PS' => 'Performance (Lighthouse/PageSpeed)',
        'F'  => 'Versions, mises à jour & fin de vie',
        'G'  => 'PHP & serveur',
        'H'  => 'Base de données',
        'I'  => 'Autoload / cache objet',
        'J'  => 'Cron',
        'K'  => 'Configuration & durcissement',
        'L'  => 'Système de fichiers',
        'M'  => 'Utilisateurs & accès',
        'BV' => 'BlogVault',
        'WF' => 'Wordfence Intelligence',
        'X'  => 'Exposition (sondes passives)',
    ];

    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rules = RuleCatalog::load(self::CATALOG);
        $fr    = $this->app->translator('fr');
        $lines = file(self::CATALOG);

        $grouped = [];
        foreach ($rules as $rule) {
            preg_match('/^[A-Z]+/', $rule->id, $m);
            $grouped[$m[0] ?? '?'][] = $rule;
        }

        $out   = [];
        $out[] = '# Catalogue des règles — SatelliteWP Xtractor';
        $out[] = '';
        $out[] = '**Généré, pas écrit à la main** — ne pas éditer ce fichier directement, les';
        $out[] = 'modifications seraient perdues au prochain export. La vérité vit dans';
        $out[] = '`config/rules.php` (et `config/lang/{fr,en}.php` pour les textes) ; pour';
        $out[] = 'republier cette page après un changement de règle :';
        $out[] = '';
        $out[] = '```';
        $out[] = 'php bin/xtractor rules:doc > docs/rules-catalog.md';
        $out[] = '```';
        $out[] = '';
        $out[] = sprintf('%d règles, %d groupes.', count($rules), count($grouped));
        $out[] = '';

        foreach ($grouped as $prefix => $groupRules) {
            $label = self::GROUPS[$prefix] ?? $prefix;
            $out[] = "## {$prefix}. {$label}";
            $out[] = '';

            foreach ($groupRules as $rule) {
                $refl      = new ReflectionFunction($rule->check);
                $startLine = $refl->getStartLine();
                $endLine   = $refl->getEndLine();
                $snippet   = ($startLine !== false && $endLine !== false)
                    ? implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1))
                    : '// source introuvable';

                $threshold = $rule->threshold === null ? '—' : (string) $rule->threshold;

                $out[] = "### {$rule->id} — {$fr->title($rule->id)}";
                $out[] = '';
                $out[] = "- **Catégorie :** {$rule->category} · **Source :** {$rule->source} · "
                    . "**Sévérité de base :** {$fr->severity($rule->severity->value)} · **Seuil configurable :** {$threshold}";
                // Not resolved against a real finding — 'observed'/'threshold' are
                // fed back their own placeholder name so the template renders
                // literally (any other {named} value used by a specific rule's
                // template, e.g. {eol_date}, falls through Translator's own
                // "leave unknown placeholders as-is" behaviour the same way).
                $template = ['id' => $rule->id, 'observed' => '{observed}', 'threshold' => '{threshold}', 'data' => []];
                $out[] = "- **Réussite (FR) :** " . ($fr->message($template + ['status' => 'pass']) ?? '_(pas de texte dédié — voir le code)_');
                $out[] = "- **Échec (FR) :** " . ($fr->message($template + ['status' => 'fail']) ?? '_(pas de texte dédié — voir le code)_');
                $out[] = '';
                $out[] = '```php';
                $out[] = rtrim($snippet);
                $out[] = '```';
                $out[] = '';
            }
        }

        $output->write(implode("\n", $out) . "\n", false);

        return Command::SUCCESS;
    }
}
