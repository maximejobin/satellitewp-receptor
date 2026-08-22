<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Reference\WordfenceIndex;

/**
 * Cross-references the site's installed core/plugins/themes (from the
 * extraction payload, carried on SiteContext) against the local Wordfence
 * Intelligence index — a second, independent vulnerability source alongside
 * BlogVault. Unlike every other probe, this one makes no network call: the
 * index is a daily-refreshed local cache (see WordfenceIndex::refresh(),
 * `wordfence:refresh`), because the upstream feed is a ~100+ MB full dump with
 * a strict rate limit, not a per-site API.
 *
 * Output deliberately mirrors BlogVaultProbe's core/plugins/themes shape so
 * the two can be merged for display (merge_vulnerabilities() in
 * src/Web/helpers.php) without a translation step.
 */
final class WordfenceProbe extends AbstractProbe
{
    public function __construct(private readonly ?WordfenceIndex $index = null)
    {
    }

    public function name(): string
    {
        return 'wordfence';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        if ($this->index === null) {
            return [
                'status' => ProbeResult::STATUS_ERROR,
                'errors' => ['Wordfence is not configured (wordfence.base_url / api_key)'],
            ];
        }
        // Distinct from BlogVault's "site not linked" (a normal outcome): here
        // every install needs the cache, so its absence is a real gap.
        if (!$this->index->isAvailable()) {
            return [
                'status' => ProbeResult::STATUS_ERROR,
                'errors' => ['Wordfence index has never been refreshed — run wordfence:refresh'],
            ];
        }

        // One sequential pass over the cache for every component this site has,
        // instead of a pass (or a full decode) per lookup.
        $this->index->preload(self::componentKeys($site));

        $core    = self::matchCore($this->index, $site->wpVersion);
        $plugins = self::matchComponents($this->index, 'plugin', $site->plugins);
        $themes  = self::matchComponents($this->index, 'theme', $site->themes);

        $total = count($core['vulnerabilities']) + $plugins['vulnerabilities_total'] + $themes['vulnerabilities_total'];

        $data = [
            'core'    => $core,
            'plugins' => $plugins,
            'themes'  => $themes,
            'vulnerabilities_total' => $total,
        ];

        return [
            'data'   => $data,
            'status' => $total > 0 ? ProbeResult::STATUS_WARN : ProbeResult::STATUS_OK,
        ];
    }

    /**
     * Every "type:slug" this site could match, for a single preload pass.
     *
     * @return list<string>
     */
    private static function componentKeys(SiteContext $site): array
    {
        $keys = ['core:wordpress'];

        foreach (['plugin' => $site->plugins, 'theme' => $site->themes] as $type => $items) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $slug = SoftwareCatalog::normalizeSlug($type, (string) ($item['slug'] ?? ''));
                if ($slug !== '') {
                    $keys[] = strtolower($type) . ':' . strtolower($slug);
                }
            }
        }

        return $keys;
    }

    /** @return array<string, mixed> */
    private static function matchCore(WordfenceIndex $index, ?string $wpVersion): array
    {
        $vulns = $wpVersion !== null ? $index->vulnerabilitiesFor('core', 'wordpress', $wpVersion) : [];

        return [
            'current_version' => $wpVersion,
            'vulnerabilities'  => array_values($vulns),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items raw payload.plugins / payload.themes
     * @return array<string, mixed>
     */
    private static function matchComponents(WordfenceIndex $index, string $type, array $items): array
    {
        $parsed     = [];
        $vulnerable = 0;
        $vulnTotal  = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rawSlug = (string) ($item['slug'] ?? '');
            $slug    = SoftwareCatalog::normalizeSlug($type, $rawSlug);
            $version = isset($item['version']) ? (string) $item['version'] : null;

            if ($slug === '' || $version === null) {
                continue;
            }

            $vulns = $index->vulnerabilitiesFor($type, $slug, $version);
            if ($vulns !== []) {
                $vulnerable++;
                $vulnTotal += count($vulns);
            }

            $parsed[] = [
                'slug'            => $slug,
                'name'            => (string) ($item['name'] ?? $slug),
                'current_version' => $version,
                'vulnerabilities' => array_values($vulns),
            ];
        }

        return [
            'total'                 => count($parsed),
            'vulnerable_count'      => $vulnerable,
            'vulnerabilities_total' => $vulnTotal,
            'items'                 => $parsed,
        ];
    }
}
