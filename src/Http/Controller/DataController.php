<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http\Controller;

use SatelliteWP\Manager\Reference\EndOfLife;
use SatelliteWP\Manager\Reference\WordPressVersions;

/** /status and the cross-site reference data pages under /data. */
final class DataController extends Controller
{
    /** @param array<string, string> $params */
    public function status(array $params): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $config        = $this->app->config;
        $eolFreshness  = (int) $config->get('data_sync_freshness.endoflife_seconds', 2 * 3600);
        $eol           = $this->app->endOfLife();
        $syncSources   = [
            'endoflife: wordpress'   => self::syncEntry($eol->refreshedAt('wordpress'), $eolFreshness),
            'endoflife: php'         => self::syncEntry($eol->refreshedAt('php'), $eolFreshness),
            'endoflife: mysql'       => self::syncEntry($eol->refreshedAt('mysql'), $eolFreshness),
            'endoflife: mariadb'     => self::syncEntry($eol->refreshedAt('mariadb'), $eolFreshness),
            'wordpress.org versions' => self::syncEntry(
                $this->app->wordPressVersions()->refreshedAt(),
                (int) $config->get('data_sync_freshness.wordpress_versions_seconds', 2 * 3600)
            ),
            'wordfence intelligence' => self::syncEntry(
                $this->app->wordfenceIndex()->refreshedAt(),
                (int) $config->get('data_sync_freshness.wordfence_seconds', 36 * 3600)
            ),
        ];

        $crmRepo = $this->app->crmRepository();
        $crm     = null;
        if ($crmRepo !== null) {
            try {
                $defaultFreshness = (int) $config->get('crm_sync_freshness.default_seconds', 86400);
                $overrides        = (array) $config->get('crm_sync_freshness.overrides', []);

                foreach ($crmRepo->lastSyncByTable() as $table => $syncedAt) {
                    $syncSources[$table] = self::syncEntry($syncedAt, (int) ($overrides[$table] ?? $defaultFreshness));
                }

                $crm = [
                    'unpaidClients'             => $crmRepo->clientsWithUnpaidSubscriptions(),
                    'emptyCompanyClients'       => $crmRepo->clientsWithEmptyCompany(),
                    'activeEmptyCompanyClients' => $crmRepo->clientsActiveWithEmptyCompany(),
                    'activeNoHubspotClients'    => $crmRepo->clientsActiveWithoutHubspotId(),
                    'activeNoTeamworkClients'   => $crmRepo->clientsActiveWithoutTeamworkId(),
                    'orphanSubscriptions'       => $crmRepo->countOrphanSubscriptions(),
                ];
            } catch (\PDOException $e) {
                $crm = ['error' => $this->app->errorLog()->recordThrowable('crm_db', $e)];
            }
        }

        $staleCount = count(array_filter($syncSources, static fn (array $s): bool => $s['stale']));

        $this->render('status', [
            'title'            => 'Status',
            'nav'              => 'status',
            'tooltip'          => true,
            'extractionCounts' => $this->app->index()->statusCounts(),
            'syncSources'      => $syncSources,
            'syncTotals'       => [
                'total'    => count($syncSources),
                'upToDate' => count($syncSources) - $staleCount,
                'stale'    => $staleCount,
            ],
            'crmConfigured'    => $crmRepo !== null,
            'crm'              => $crm,
        ]);
    }

    /**
     * Every explicit WordPress release wordpress.org's stable-check knows,
     * with its own verdict; endoflife.date only supplies the branch's release
     * date. ~900 rows, so the table stays client-side.
     *
     * @param array<string, string> $params
     */
    public function wordPressVersions(array $params): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $eol  = $this->app->endOfLife();
        $rows = [];
        foreach ($this->app->wordPressVersions()->all() as $version => $rawStatus) {
            $rows[] = [
                'version'        => (string) $version,
                'branch'         => EndOfLife::branch((string) $version),
                'status'         => WordPressVersions::status((string) $rawStatus),
                'branchReleased' => $eol->cycleFor('wordpress', (string) $version)['releaseDate'] ?? null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        $this->render('data-wp-versions', [
            'title'          => 'WordPress versions',
            'nav'            => 'data-wp-versions',
            'dataTables'     => true,
            'rows'           => $rows,
            'refreshedAt'    => $this->app->wordPressVersions()->refreshedAt(),
            'eolRefreshedAt' => $eol->refreshedAt('wordpress'),
        ]);
    }

    /** @param array<string, string> $params */
    public function phpVersions(array $params): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $eol    = $this->app->endOfLife();
        $cycles = $eol->cycles('php');
        usort($cycles, static fn (array $a, array $b): int => version_compare((string) ($b['cycle'] ?? '0'), (string) ($a['cycle'] ?? '0')));

        $this->render('data-php-versions', [
            'title'       => 'PHP versions',
            'nav'         => 'data-php-versions',
            'dataTables'  => true,
            'cycles'      => $cycles,
            'eol'         => $eol,
            'refreshedAt' => $eol->refreshedAt('php'),
        ]);
    }

    /** @param array<string, string> $params */
    public function databases(array $params): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $eol    = $this->app->endOfLife();
        $cycles = [];
        foreach (['mysql', 'mariadb'] as $engine) {
            foreach ($eol->cycles($engine) as $cycle) {
                $cycle['engine'] = $engine;
                $cycles[]        = $cycle;
            }
        }
        usort($cycles, static function (array $a, array $b): int {
            $engineCmp = strcmp((string) $a['engine'], (string) $b['engine']);

            return $engineCmp !== 0
                ? $engineCmp
                : version_compare((string) ($b['cycle'] ?? '0'), (string) ($a['cycle'] ?? '0'));
        });

        $this->render('data-databases', [
            'title'              => 'Databases',
            'nav'                => 'data-databases',
            'dataTables'         => true,
            'cycles'             => $cycles,
            'eol'                => $eol,
            'mysqlRefreshedAt'   => $eol->refreshedAt('mysql'),
            'mariadbRefreshedAt' => $eol->refreshedAt('mariadb'),
        ]);
    }

    /**
     * Only the table shell: the ~84k-row catalogue is served page by page by
     * vulnerabilitiesSearch().
     *
     * @param array<string, string> $params
     */
    public function vulnerabilities(array $params): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $this->render('data-vulnerabilities', [
            'title'       => 'Vulnerabilities (Wordfence Intelligence)',
            'nav'         => 'data-vulnerabilities',
            'dataTables'  => true,
            'tooltip'     => true,
            'available'   => $this->app->wordfenceIndex()->isAvailable(),
            'refreshedAt' => $this->app->wordfenceIndex()->refreshedAt(),
            'ignoredCount' => count($this->app->ignoredVulnerabilities()),
        ]);
    }

    /**
     * Datatables server-side endpoint, backed by CatalogIndex's SQLite table
     * so column sorting doesn't mean buffering the whole filtered cache.
     *
     * @param array<string, string> $params
     */
    public function vulnerabilitiesSearch(array $params): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $length = (int) ($_GET['length'] ?? 25);
        $length = $length > 0 ? min($length, 100) : 25;

        // Column index -> SQL column, in data-vulnerabilities.php's <thead>
        // order; unlisted columns fall back to the default sort.
        $sortColumns = [0 => 'name', 2 => 'type', 3 => 'cve_id', 4 => 'title', 5 => 'published_at', 6 => 'cvss_score'];
        $orderColumn = $sortColumns[(int) ($_GET['order'][0]['column'] ?? 5)] ?? 'published_at';

        $result = $this->app->catalogIndex()->searchVulnerabilities(
            (string) ($_GET['search']['value'] ?? ''),
            max(0, (int) ($_GET['start'] ?? 0)),
            $length,
            $orderColumn,
            (string) ($_GET['order'][0]['dir'] ?? 'desc'),
            $this->app->ignoredVulnerabilities(),
            match ((string) ($_GET['ignored'] ?? '')) {
                'only'  => 'only',
                'hide'  => 'hide',
                default => 'all',
            }
        );

        $this->response->json([
            'draw'            => (int) ($_GET['draw'] ?? 0),
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => self::vulnerabilityRows($result['rows']),
        ]);
    }

    /**
     * DataTables inserts each cell as HTML and Wordfence titles and names are
     * third-party text: every cell is escaped here. Column 9 keeps the raw row
     * for the JSON dialog, which only ever sets it as textContent.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<mixed>>
     */
    public static function vulnerabilityRows(array $rows): array
    {
        require_once dirname(__DIR__, 2) . '/Web/helpers.php';

        $byType = ['core' => ['WP', 'WordPress core'], 'plugin' => ['P', 'Plugin'], 'theme' => ['T', 'Theme']];

        return array_map(static function (array $row) use ($byType): array {
            $type      = (string) ($row['type'] ?? '');
            [$abbr, $typeLabel] = $byType[$type] ?? [strtoupper($type !== '' ? $type : '?'), $type !== '' ? $type : 'Unknown'];
            $published = (string) ($row['published_at'] ?? '');
            $rating    = is_string($row['cvss_rating'] ?? null) ? $row['cvss_rating'] : null;
            $patched   = is_array($row['patched_versions'] ?? null)
                ? implode(', ', array_map('strval', array_filter($row['patched_versions'], 'is_scalar')))
                : '';
            $ignored   = !empty($row['ignored'])
                ? ' <span class="badge badge-muted" title="Excluded from findings and reports (config: vulnerabilities.ignored)">Ignored</span>'
                : '';

            return [
                '<div>' . e($row['name'] ?? $row['slug'] ?? '') . $ignored . '</div>'
                    . '<div class="muted mono" style="font-size:.8rem">' . e($row['slug'] ?? '') . '</div>',
                e($row['slug'] ?? ''),
                '<span class="badge badge-muted" title="' . e($typeLabel) . '">' . e($abbr) . '</span>',
                e($row['cve_id'] ?? '—'),
                e($row['title'] ?? ''),
                $published !== '' ? e(substr($published, 0, 10)) : '—',
                is_numeric($row['cvss_score'] ?? null) ? cvss_badge($row['cvss_score'], $rating) : '—',
                e($rating),
                !empty($row['patched']) && $patched !== '' ? e($patched) : '—',
                $row,
                null,
            ];
        }, $rows);
    }

    /** @return array{synced_at: string|null, threshold_seconds: int, stale: bool} */
    private static function syncEntry(?string $syncedAt, int $thresholdSeconds): array
    {
        return [
            'synced_at'         => $syncedAt,
            'threshold_seconds' => $thresholdSeconds,
            'stale'             => $syncedAt === null || time() - (int) strtotime($syncedAt) > $thresholdSeconds,
        ];
    }
}
