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
            } catch (\PDOException $e) {
                $crm = ['error' => $this->app->errorLog()->recordThrowable('crm_db', $e)];
            }
        }

        $result = ['syncSources' => $syncSources, 'crmConfigured' => $crmRepo !== null, 'crm' => $crm];
        $this->storeAlert(self::needsAttention($result));

        return $result;
    }

    /**
     * Stale or failed external sync, active clients missing a company,
     * HubSpot or Teamwork id, subscriptions linked to no website. Unpaid and
     * inactive clients are business follow-up, not an alert.
     *
     * @param array{syncSources: array<string, array{stale: bool}>, crm: array<string, mixed>|null} $collected
     */
    public static function needsAttention(array $collected): bool
    {
        foreach ($collected['syncSources'] as $source) {
            if ($source['stale']) {
                return true;
            }
        }
        $crm = $collected['crm'];
        if ($crm === null) {
            return false;
        }
        if (isset($crm['error'])) {
            return true;
        }

        return ($crm['activeEmptyCompanyClients'] ?? []) !== []
            || ($crm['activeNoHubspotClients'] ?? []) !== []
            || ($crm['activeNoTeamworkClients'] ?? []) !== []
            || (int) ($crm['orphanSubscriptions'] ?? 0) > 0;
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
