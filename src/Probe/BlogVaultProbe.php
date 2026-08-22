<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Integration\BlogVaultClient;
use SatelliteWP\Xtractor\Integration\BlogVaultException;

/**
 * BlogVault v6 — the single agreed source for vulnerabilities, malware/hacked
 * status and backup state (SOURCE 12).
 *
 * Our site ids are UUIDs and BlogVault's are 32-hex strings with nothing in
 * common, so the site is matched on host. A site that is simply absent from the
 * BlogVault account is a normal outcome, not a failure: the probe reports "ok"
 * with linked=false and the rules turn that into "unknown".
 *
 * Everything reaching data/ is language-neutral (ids, versions, CVE ids,
 * counts, booleans, ISO timestamps) and password-free — see redactConnection().
 */
final class BlogVaultProbe extends AbstractProbe
{
    /** Site fields dropped before anything is written to disk. */
    private const array SECRET_CONNECTION_FIELDS = ['http_auth', 'sticky_ip'];

    public function __construct(private readonly ?BlogVaultClient $client = null)
    {
    }

    public function name(): string
    {
        return 'blogvault';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        if ($this->client === null) {
            return [
                'status' => ProbeResult::STATUS_ERROR,
                'errors' => ['BlogVault is not configured (blogvault.base_url / api_key)'],
            ];
        }

        $host = $site->host;
        if ($host === '') {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['No host in site context']];
        }

        try {
            $listed = $this->client->get('sites', ['filters' => ['url:contains' => $host]]);
        } catch (BlogVaultException $e) {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => [$e->getMessage()]];
        }

        $match = self::matchSite($listed, $host);
        if ($match === null) {
            return [
                'target' => $host,
                'data'   => ['linked' => false],
                'status' => ProbeResult::STATUS_OK,
            ];
        }

        $id = (string) $match['id'];

        try {
            $raw = [
                'site'     => $this->client->get("sites/{$id}"),
                'core'     => $this->client->get('sites/wp/wordpress-core', $this->wpQuery($id)),
                'plugins'  => $this->client->get('sites/wp/plugins', $this->wpQuery($id)),
                'themes'   => $this->client->get('sites/wp/themes', $this->wpQuery($id)),
                'scan'     => $this->client->get("sites/{$id}/security/scanner/detections/summary"),
                'users'    => $this->client->get('sites/wp/users', ['site_ids' => [$id], 'perPage' => 100]),
                'settings' => $this->client->get("sites/{$id}/settings"),
            ];

            // Only worth six more calls when there is something to remediate:
            // the summary above is counts only ("files.total: 1"), never the
            // actual file/script/etc — an analyst cannot act on a count.
            if (self::needsRemediationDetail($raw)) {
                $raw += $this->fetchRemediationDetail($id);
            }
        } catch (BlogVaultException $e) {
            return ['target' => $host, 'status' => ProbeResult::STATUS_ERROR, 'errors' => [$e->getMessage()]];
        }

        $data = self::parse($raw);

        return [
            'target' => $host,
            'data'   => $data,
            'status' => self::statusFor($data),
        ];
    }

    /**
     * Six detail calls, only issued when the scan summary shows something
     * unresolved. A clean site never pays for these.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchRemediationDetail(string $id): array
    {
        return [
            'scan_files'        => $this->client->get("sites/{$id}/security/scanner/detections/files", ['perPage' => 20]),
            'scan_scripts'      => $this->client->get("sites/{$id}/security/scanner/detections/scripts", ['perPage' => 20]),
            'scan_plugins'      => $this->client->get("sites/{$id}/security/scanner/detections/plugins", ['perPage' => 20]),
            'scan_cron_jobs'    => $this->client->get("sites/{$id}/security/scanner/detections/cron-jobs", ['perPage' => 20]),
            'scan_redirections' => $this->client->get("sites/{$id}/security/scanner/detections/redirections", ['perPage' => 20]),
            'snapshots'         => $this->client->get("sites/{$id}/snapshots", ['perPage' => 5]),
        ];
    }

    /** @param array<string, array<string, mixed>> $raw */
    private static function needsRemediationDetail(array $raw): bool
    {
        $status     = (string) ($raw['site']['site']['security']['scanner']['status'] ?? '');
        $detections = (array) ($raw['scan']['detections'] ?? []);

        $unresolved = 0;
        foreach ($detections as $counts) {
            $unresolved += max(0, (int) ($counts['total'] ?? 0) - (int) ($counts['marked_safe'] ?? 0));
        }

        return $status === 'hacked' || $unresolved > 0;
    }

    /** @return array<string, mixed> */
    private function wpQuery(string $id): array
    {
        // include_vulnerabilities is what unlocks the CVE payload — without it
        // these endpoints return versions only.
        return ['site_ids' => [$id], 'include_vulnerabilities' => 'true', 'perPage' => 100];
    }

    /**
     * Exact-host match inside a "url:contains" result — "contains" can return
     * neighbours such as staging.example.com when asked for example.com.
     *
     * @param array<string, mixed> $listed
     * @return array<string, mixed>|null
     */
    public static function matchSite(array $listed, string $host): ?array
    {
        foreach ((array) ($listed['sites'] ?? []) as $site) {
            if (!is_array($site)) {
                continue;
            }
            foreach (['url', 'home_url'] as $field) {
                $siteHost = parse_url((string) ($site[$field] ?? ''), PHP_URL_HOST);
                if (is_string($siteHost) && strcasecmp($siteHost, $host) === 0) {
                    return $site;
                }
            }
        }

        return null;
    }

    /**
     * Pure: turns the seven raw v6 responses into the neutral probe data.
     *
     * @param array<string, array<string, mixed>> $raw
     * @return array<string, mixed>
     */
    public static function parse(array $raw): array
    {
        $site    = (array) ($raw['site']['site'] ?? []);
        $core    = self::parseCore($raw['core'] ?? [], $site);
        $plugins = self::parseComponents($raw['plugins'] ?? [], 'plugins');
        $themes  = self::parseComponents($raw['themes'] ?? [], 'themes');

        return [
            'linked'  => true,
            'site'    => [
                'id'          => (string) ($site['id'] ?? ''),
                'url'         => (string) ($site['url'] ?? ''),
                'title'       => (string) ($site['title'] ?? ''),
                'locked'      => (bool) ($site['locked'] ?? false),
                'multisite'   => (bool) ($site['multisite'] ?? false),
                'woocommerce' => isset($site['woocommerce']) ? (bool) $site['woocommerce'] : null,
                'connection'  => self::redactConnection((array) ($site['connection'] ?? [])),
            ],
            'health'  => [
                'score'  => self::intOrNull($site['health']['score'] ?? null),
                'status' => self::stringOrNull($site['health']['status'] ?? null),
            ],
            'server'  => [
                'hosting'        => self::stringOrNull($site['server']['hosting'] ?? null),
                'php_version'    => self::stringOrNull($site['server']['php_version'] ?? null),
                'mysql_version'  => self::stringOrNull($site['server']['mysql_version'] ?? null),
            ],
            'sync'    => [
                'last_sync_at'     => self::stringOrNull($site['sync']['last_sync_at'] ?? null),
                'last_sync_status' => self::stringOrNull($site['sync']['last_sync_status'] ?? null),
                'paused'           => (bool) ($site['sync']['paused'] ?? false),
            ],
            'scanner'  => self::parseScanner($site, $raw['scan'] ?? [], $raw),
            'firewall' => self::parseFirewall($site),
            'backups'  => self::parseBackups($site),
            'core'     => $core,
            'plugins'  => $plugins,
            'themes'   => $themes,
            'vulnerabilities_total' => count($core['vulnerabilities'])
                + $plugins['vulnerabilities_total']
                + $themes['vulnerabilities_total'],
            'users'     => self::parseUsers($raw['users'] ?? []),
            'hardening' => self::parseHardening($raw['settings'] ?? []),
        ];
    }

    /**
     * GET /sites/{id} hands back the site's own HTTP basic-auth password in
     * clear text, plus its sticky IP. Neither may ever reach data/ or the UI.
     *
     * @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    public static function redactConnection(array $connection): array
    {
        foreach (self::SECRET_CONNECTION_FIELDS as $field) {
            unset($connection[$field]);
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $scan
     * @param array<string, mixed> $raw the full raw response set — the six
     *     remediation-detail calls are only present when needsRemediationDetail()
     *     triggered them
     * @return array<string, mixed>
     */
    private static function parseScanner(array $site, array $scan, array $raw = []): array
    {
        $scanner    = (array) ($site['security']['scanner'] ?? []);
        $detections = (array) ($scan['detections'] ?? []);

        $unresolved = 0;
        $kinds      = [];
        foreach ($detections as $kind => $counts) {
            $total  = (int) ($counts['total'] ?? 0);
            $safe   = (int) ($counts['marked_safe'] ?? 0);
            $kinds[$kind] = ['total' => $total, 'marked_safe' => $safe];
            $unresolved += max(0, $total - $safe);
        }

        $result = [
            'enabled'             => (bool) ($site['security']['enabled'] ?? false),
            'status'              => self::stringOrNull($scanner['status'] ?? null),
            'malware_detected_at' => self::stringOrNull($scanner['malware_detected_at'] ?? null),
            'last_check_at'       => self::stringOrNull($scanner['last_check_at'] ?? null),
            'next_check_at'       => self::stringOrNull($scanner['next_check_at'] ?? null),
            'detections'          => $kinds,
            'unresolved_count'    => $unresolved,
        ];

        if (isset($raw['scan_files'])) {
            $result['remediation'] = self::parseRemediationDetail($raw);
        }

        return $result;
    }

    /**
     * The six detail calls turn "1 detection" into "clean ./wp-includes/class-wp-hook.php".
     * Only present when needsRemediationDetail() triggered the extra fetches.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function parseRemediationDetail(array $raw): array
    {
        return [
            'files'           => self::parseDetectionList($raw['scan_files'] ?? [], 'files'),
            'scripts'         => self::parseDetectionList($raw['scan_scripts'] ?? [], 'scripts'),
            'plugins'         => self::parseDetectionList($raw['scan_plugins'] ?? [], 'plugins'),
            'cron_jobs'       => self::parseDetectionList($raw['scan_cron_jobs'] ?? [], 'cron_jobs'),
            'redirections'    => self::parseDetectionList($raw['scan_redirections'] ?? [], 'redirections'),
            'clean_snapshots' => self::parseCleanSnapshots($raw['snapshots'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private static function parseDetectionList(array $response, string $key): array
    {
        $items  = (array) ($response[$key] ?? []);
        $parsed = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Shape varies by kind (a file has "path", a redirection has
            // "source"/"target", …) — carry whatever fields v6 sent, minus
            // the opaque internal "id" nobody downstream needs.
            unset($item['id']);
            $item['detected_at'] = self::stringOrNull($item['detected_at'] ?? null);
            $parsed[] = $item;
        }

        return $parsed;
    }

    /**
     * Snapshots the scanner did NOT find hacked — candidates to restore to.
     * Newest first, which is already the API's own ordering.
     *
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private static function parseCleanSnapshots(array $response): array
    {
        $snapshots = (array) ($response['snapshots'] ?? []);
        $clean     = [];

        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot)) {
                continue;
            }
            $status = self::stringOrNull($snapshot['security']['scanner']['status'] ?? null);
            if ($status === 'hacked') {
                continue;
            }
            $clean[] = [
                'id'         => self::stringOrNull($snapshot['id'] ?? null),
                'created_at' => self::stringOrNull($snapshot['created_at'] ?? null),
                'status'     => self::stringOrNull($snapshot['status'] ?? null),
            ];
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private static function parseFirewall(array $site): array
    {
        $firewall = (array) ($site['security']['firewall'] ?? []);

        return [
            'enabled'        => (bool) ($firewall['enabled'] ?? false),
            'mode'           => self::stringOrNull($firewall['mode'] ?? null),
            'advanced'       => (bool) ($firewall['advanced'] ?? false),
            'bot_protection' => (bool) ($firewall['bot_protection']['enabled'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private static function parseBackups(array $site): array
    {
        $backups  = (array) ($site['backups'] ?? []);
        $snapshot = (array) ($backups['latest_snapshot'] ?? []);
        $takenAt  = self::stringOrNull($snapshot['created_at'] ?? null);

        return [
            'enabled'             => (bool) ($backups['enabled'] ?? false),
            'real_time'           => (bool) ($backups['real_time'] ?? false),
            'retention_days'      => self::intOrNull($backups['retention_days'] ?? null),
            'available_snapshots' => self::intOrNull($backups['available_snapshots'] ?? null),
            'latest_snapshot'     => [
                'id'         => self::stringOrNull($snapshot['id'] ?? null),
                'created_at' => $takenAt,
                'status'     => self::stringOrNull($snapshot['status'] ?? null),
                'age_days'   => self::ageInDays($takenAt),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private static function parseCore(array $response, array $site): array
    {
        $core = (array) (($response['sites'][0]['wp']['core'] ?? null) ?? $site['wp']['core'] ?? []);

        return [
            'current_version'  => self::stringOrNull($core['current_version'] ?? null),
            'latest_version'   => self::stringOrNull($core['latest_version'] ?? null),
            'update_available' => (bool) ($core['update_available'] ?? false),
            'locked'           => (bool) ($core['locked'] ?? false),
            'vulnerable'       => (bool) ($core['security']['vulnerable'] ?? $core['vulnerable'] ?? false),
            'vulnerabilities'  => self::parseVulnerabilities($core['security']['vulnerabilities'] ?? []),
        ];
    }

    /**
     * Plugins and themes share a shape; $key selects which list to read.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private static function parseComponents(array $response, string $key): array
    {
        $items      = (array) ($response['sites'][0]['wp'][$key] ?? []);
        $parsed     = [];
        $outdated   = 0;
        $vulnerable = 0;
        $malicious  = 0;
        $vulnTotal  = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $vulns = self::parseVulnerabilities($item['security']['vulnerabilities'] ?? []);
            $isVulnerable = (bool) ($item['security']['vulnerable'] ?? $item['vulnerable'] ?? false);
            $isMalicious  = (bool) ($item['malicious'] ?? false);
            $hasUpdate    = (bool) ($item['update_available'] ?? false);

            $outdated   += $hasUpdate ? 1 : 0;
            $vulnerable += $isVulnerable ? 1 : 0;
            $malicious  += $isMalicious ? 1 : 0;
            $vulnTotal  += count($vulns);

            $parsed[] = [
                'slug'             => self::stringOrNull($item['slug'] ?? null),
                'name'             => self::stringOrNull($item['name'] ?? null),
                'current_version'  => self::stringOrNull($item['current_version'] ?? null),
                'latest_version'   => self::stringOrNull($item['latest_version'] ?? null),
                'update_available' => $hasUpdate,
                'active'           => (bool) ($item['active'] ?? false),
                'malicious'        => $isMalicious,
                'vulnerable'       => $isVulnerable,
                'vulnerabilities'  => $vulns,
            ];
        }

        return [
            'total'                 => count($parsed),
            'update_available'      => $outdated,
            'vulnerable_count'      => $vulnerable,
            'malicious_count'       => $malicious,
            'vulnerabilities_total' => $vulnTotal,
            'items'                 => $parsed,
        ];
    }

    /**
     * @param mixed $vulnerabilities
     * @return list<array<string, mixed>>
     */
    private static function parseVulnerabilities(mixed $vulnerabilities): array
    {
        $parsed = [];
        foreach ((array) $vulnerabilities as $vuln) {
            if (!is_array($vuln)) {
                continue;
            }
            $parsed[] = [
                'id'              => self::stringOrNull($vuln['vulnerability_id'] ?? null),
                'title'           => self::stringOrNull($vuln['title'] ?? null),
                'cve_id'          => self::stringOrNull($vuln['cve_id'] ?? null),
                'cvss_rating'     => self::stringOrNull($vuln['cvss_rating'] ?? null),
                'cvss_score'      => isset($vuln['cvss_score']) ? (float) $vuln['cvss_score'] : null,
                'patched_version' => self::stringOrNull($vuln['patched_version'] ?? null),
                'published_at'    => self::stringOrNull($vuln['published_at'] ?? null),
                'virtual_patch'   => (bool) ($vuln['virtual_patch']['applied'] ?? false),
            ];
        }

        return $parsed;
    }

    /**
     * Usernames and roles only — emails stay out of data/.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private static function parseUsers(array $response): array
    {
        $users  = (array) ($response['sites'][0]['wp']['users'] ?? []);
        $items  = [];
        $admins = 0;
        $unprotectedAdmins = 0;
        $defaultLogins     = 0;

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $role  = self::stringOrNull($user['role'] ?? null);
            $twoFa = self::stringOrNull($user['two_fa_status'] ?? null);
            $isDefault = (bool) ($user['default_login'] ?? false);

            if ($role === 'administrator') {
                $admins++;
                // v6 reports not_applied | pending | applied.
                $unprotectedAdmins += $twoFa === 'applied' ? 0 : 1;
            }
            $defaultLogins += $isDefault ? 1 : 0;

            $items[] = [
                'username'      => self::stringOrNull($user['username'] ?? null),
                'role'          => $role,
                'two_fa_status' => $twoFa,
                'default_login' => $isDefault,
            ];
        }

        return [
            'total'                    => count($items),
            'administrators'           => $admins,
            'administrators_without_2fa' => $unprotectedAdmins,
            'default_logins'           => $defaultLogins,
            'items'                    => $items,
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private static function parseHardening(array $response): array
    {
        $settings = (array) ($response['settings'] ?? []);
        $wpConfig = (array) ($settings['security_hardening']['wp_config'] ?? []);

        return [
            'wp_auto_updates'     => (bool) ($settings['wp_auto_updates']['enabled'] ?? false),
            'disable_file_editor' => (bool) ($settings['security_hardening']['disable_file_editor']['enabled'] ?? false),
            'block_file_modifications' => (bool) ($settings['security_hardening']['block_file_modifications']['enabled'] ?? false),
            'disallow_file_edit'  => self::declaredFlag($wpConfig['disallow_file_edit'] ?? []),
            'disallow_file_mods'  => self::declaredFlag($wpConfig['disallow_file_mods'] ?? []),
        ];
    }

    /**
     * @param mixed $flag
     * @return array{declared: bool, value: bool|null}
     */
    private static function declaredFlag(mixed $flag): array
    {
        $flag = (array) $flag;

        return [
            'declared' => (bool) ($flag['declared'] ?? false),
            'value'    => isset($flag['value']) ? (bool) $flag['value'] : null,
        ];
    }

    /**
     * warn as soon as BlogVault knows something the analyst should act on;
     * the rules decide how severe each of those actually is.
     *
     * @param array<string, mixed> $data
     */
    public static function statusFor(array $data): string
    {
        if (($data['linked'] ?? false) !== true) {
            return ProbeResult::STATUS_OK;
        }

        $concerning = ($data['scanner']['status'] ?? null) === 'hacked'
            || ($data['scanner']['unresolved_count'] ?? 0) > 0
            || ($data['vulnerabilities_total'] ?? 0) > 0
            || ($data['backups']['enabled'] ?? false) !== true;

        return $concerning ? ProbeResult::STATUS_WARN : ProbeResult::STATUS_OK;
    }

    private static function ageInDays(?string $timestamp): ?int
    {
        if ($timestamp === null) {
            return null;
        }
        $when = strtotime($timestamp);

        return $when === false ? null : (int) floor((time() - $when) / 86400);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
