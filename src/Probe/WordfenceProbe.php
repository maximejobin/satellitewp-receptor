<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Probe;

use SatelliteWP\Manager\Catalog\SoftwareCatalog;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Reference\WordfenceIndex;

/**
 * Matches the site's core/plugins/themes against the local Wordfence index —
 * no network call: the upstream feed is a rate-limited full dump, cached
 * daily by `wordfence:refresh`. Output mirrors BlogVaultProbe's shape so both
 * sources merge without translation.
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
        return [
            'current_version' => $wpVersion,
            'vulnerabilities' => $index->vulnerabilitiesFor('core', 'wordpress', $wpVersion),
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
            $slug    = SoftwareCatalog::normalizeSlug($type, (string) ($item['slug'] ?? ''));
            $version = (string) ($item['version'] ?? '');

            // No version means nothing to match against — never "checked, clean".
            if ($slug === '' || $version === '') {
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
                'vulnerabilities' => $vulns,
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
