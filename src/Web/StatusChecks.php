<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Web;

use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Support\AtomicFile;

/**
 * What /status shows, and whether any of it needs a look (the menu's
 * "Alert" tag). The tag is rendered on every page, so its verdict is cached:
 * the CRM part is a remote MySQL round trip.
 */
final class StatusChecks
{
    private const int ALERT_TTL_SECONDS = 300;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array{
     *     syncSources: array<string, array{synced_at: string|null, threshold_seconds: int, stale: bool}>,
     *     crmConfigured: bool,
     *     crm: array<string, mixed>|null
     * }
     */
    public function collect(): array
    {
        $config       = $this->app->config;
        $eolFreshness = (int) $config->get('data_sync_freshness.endoflife_seconds', 2 * 3600);
        $eol          = $this->app->endOfLife();
        $syncSources  = [
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
            } catch (\Throwable $e) {
                $crm = ['error' => $this->app->errorLog()->recordThrowable('crm_db', $e)];
            }
        }

        $result = ['syncSources' => $syncSources, 'crmConfigured' => $crmRepo !== null, 'crm' => $crm];
        $this->storeAlert(self::needsAttention($result));

        return $result;
    }

    /**
     * The checks that raise the menu's "Alert" tag, each with its count:
     * stale or failed external sync, active clients missing a company,
     * HubSpot or Teamwork id, subscriptions linked to no website. Unpaid and
     * inactive clients are business follow-up, not an alert.
     *
     * @param array{syncSources: array<string, array{stale: bool}>, crm: array<string, mixed>|null} $collected
     * @return list<array{label: string, count: int, detail: string}>
     */
    public static function alerts(array $collected): array
    {
        $alerts = [];
        $stale  = array_keys(array_filter($collected['syncSources'], static fn (array $s): bool => $s['stale']));
        if ($stale !== []) {
            $alerts[] = ['label' => 'External sync error', 'count' => count($stale), 'detail' => implode(', ', $stale)];
        }
        $crm = $collected['crm'];
        if ($crm === null) {
            return $alerts;
        }
        if (isset($crm['error'])) {
            $alerts[] = ['label' => 'CRM database unreachable', 'count' => 1, 'detail' => 'ref ' . $crm['error']];

            return $alerts;
        }
        foreach ([
            'activeEmptyCompanyClients' => 'Missing company (active)',
            'activeNoHubspotClients'    => 'Missing HubSpot ID (active)',
            'activeNoTeamworkClients'   => 'Missing Teamwork ID (active)',
        ] as $key => $label) {
            $count = count((array) ($crm[$key] ?? []));
            if ($count > 0) {
                $alerts[] = ['label' => $label, 'count' => $count, 'detail' => 'active clients only'];
            }
        }
        $orphans = (int) ($crm['orphanSubscriptions'] ?? 0);
        if ($orphans > 0) {
            $alerts[] = ['label' => 'Subscriptions not linked', 'count' => $orphans, 'detail' => 'no website assigned'];
        }

        return $alerts;
    }

    /** @param array{syncSources: array<string, array{stale: bool}>, crm: array<string, mixed>|null} $collected */
    public static function needsAttention(array $collected): bool
    {
        return self::alerts($collected) !== [];
    }

    /** The cached verdict, recomputed once it is older than ALERT_TTL_SECONDS. */
    public function alert(): bool
    {
        $cached = is_file($this->cacheFile()) ? json_decode((string) file_get_contents($this->cacheFile()), true) : null;
        if (is_array($cached) && is_bool($cached['alert'] ?? null)
            && time() - (int) ($cached['checked_at'] ?? 0) < self::ALERT_TTL_SECONDS) {
            return $cached['alert'];
        }

        return self::needsAttention($this->collect());
    }

    private function storeAlert(bool $alert): void
    {
        try {
            AtomicFile::write($this->cacheFile(), (string) json_encode(['checked_at' => time(), 'alert' => $alert]));
        } catch (\Throwable) {
            // A cache that cannot be written only costs a recomputation on the next page.
        }
    }

    private function cacheFile(): string
    {
        return (string) $this->app->config->get('data_dir') . '/reference/status-alert.json';
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
