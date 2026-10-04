<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http;

use SatelliteWP\Manager\App;

/** The active report contract file (config/reports/*.php, config `reports.bilan_de_sante`). */
final class ReportContract
{
    /** @return array<string, mixed> */
    public static function load(App $app): array
    {
        $file = (string) $app->config->get('reports.bilan_de_sante', dirname(__DIR__, 2) . '/config/reports/bilan-de-sante.php');

        return (array) require $file;
    }

    /**
     * Every 'observations' field — the sections a manual observation can be filed under.
     *
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    public static function observationSections(array $contract): array
    {
        $names = [];
        foreach ((array) ($contract['fields'] ?? []) as $name => $spec) {
            if (is_array($spec) && ($spec['type'] ?? null) === 'observations') {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
