<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Reference;

use RuntimeException;
use SatelliteWP\Xtractor\Integration\WordfenceClient;
use SatelliteWP\Xtractor\Integration\WordfenceException;

/**
 * Wordfence Intelligence vulnerability data, cached locally under
 * data/reference/wordfence.json as **JSON Lines** (one component per line,
 * `{"k":"plugin:woocommerce","v":[…]}`) so a site scan can stream the handful
 * of components it needs instead of decoding all ~18 000. Server-side reference data (mirrors
 * EndOfLife's role for endoflife.date): refreshed on a schedule by
 * `wordfence:refresh` (suggested: daily — the feed changes continuously and
 * the API is strictly rate-limited), matched offline during a site's scan.
 *
 * The two raw feeds ("production" ~117 MB, "scanner" ~78 MB, confirmed live)
 * are never stored as-is: refresh() reduces them to a compact index keyed by
 * "{type}:{slug}" (type: plugin|theme|core), which is what actually gets
 * matched against a site's installed plugins/themes/core version.
 */
final class WordfenceIndex
{
    /**
     * Memo of components already read off disk this run, "type:slug" =>
     * vulnerabilities. Only the components actually looked up are held — the
     * full cache is never loaded into memory during a site scan.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $loaded = [];

    public function __construct(
        private readonly string $cacheFile,
        private readonly ?WordfenceClient $client = null,
    ) {
    }

    /**
     * Vulnerabilities whose affected_versions range includes $version, for
     * one component. Empty when the cache has not been refreshed yet, the
     * component is unknown to Wordfence, or the version does not fall in any
     * recorded range.
     *
     * @return list<array<string, mixed>>
     */
    public function vulnerabilitiesFor(string $type, string $slug, ?string $version): array
    {
        if ($version === null || $version === '') {
            return [];
        }

        $key = self::key($type, $slug);
        if (!array_key_exists($key, $this->loaded)) {
            $this->loadComponents([$key]);
        }
        $entries = $this->loaded[$key] ?? [];

        // One Wordfence record can list the same slug several times — a plugin
        // sold in editions (Business 7.x / Developer 20.x) gets one software
        // entry per edition, each with its own range and patched versions. Those
        // ranges usually do not overlap, but sometimes they do, and then the
        // same vulnerability id matches more than once: miniorange-oauth-oidc-
        // single-sign-on 18.5.3 matched one vulnerability 7 times, which would
        // render as "7 CVE" for a single issue. Keep the first range that
        // matches for a given id — a site runs one edition, and inflated counts
        // are exactly the kind of false alarm that discredits the report.
        $matched = [];
        foreach ($entries as $vuln) {
            if (!self::rangesInclude((array) ($vuln['affected_versions'] ?? []), $version)) {
                continue;
            }
            $id = (string) ($vuln['id'] ?? '');
            if ($id !== '' && isset($matched[$id])) {
                continue;
            }
            $matched[$id !== '' ? $id : count($matched)] = $vuln;
        }

        return array_values($matched);
    }

    /** Whether the local cache exists at all (distinct from "refreshed but empty"). */
    public function isAvailable(): bool
    {
        return is_file($this->cacheFile);
    }

    /** When the cache was last written, or null if it never has been. */
    public function refreshedAt(): ?string
    {
        $time = is_file($this->cacheFile) ? filemtime($this->cacheFile) : false;

        return $time !== false ? gmdate('Y-m-d\TH:i:s\Z', $time) : null;
    }

    /**
     * Download both feed variants and rebuild the local index. A variant that
     * fails (e.g. 429 — the API allows very few requests) does not abort the
     * other: the cache keeps yesterday's data for that variant rather than
     * going empty. Mirrors ReferenceRefreshCommand's per-product tolerance.
     *
     * @return array{production: int, scanner: int, index_entries: int, errors: list<string>}
     */
    public function refresh(): array
    {
        if ($this->client === null) {
            throw new RuntimeException('Wordfence is not configured (wordfence.base_url / api_key)');
        }

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create reference cache dir: {$dir}");
        }

        $counts = ['production' => 0, 'scanner' => 0];
        $errors = [];
        $index  = [];

        foreach ([WordfenceClient::VARIANT_PRODUCTION, WordfenceClient::VARIANT_SCANNER] as $variant) {
            try {
                $raw = $this->client->fetch($variant);
            } catch (WordfenceException $e) {
                $errors[] = "{$variant}: {$e->getMessage()}";
                // Keep what THIS variant already contributed to the cache from a
                // previous successful refresh, rather than wiping it. Only this
                // variant's old entries — feeding the whole old cache back in
                // would re-append the other variant's stale entries on top of
                // the fresh ones just fetched, duplicating them on every
                // partial-failure run.
                $index = self::mergeVariant($index, self::entriesFrom($this->readAll(), $variant), $variant);
                continue;
            }

            $counts[$variant] = count($raw);
            $index = self::mergeVariant($index, self::buildIndex($raw, $variant), $variant);
        }

        // A brand-new cache that fetched nothing (e.g. both variants rate
        // limited on the very first run) must NOT produce an empty-but-present
        // file: isAvailable() would then lie "refreshed" to the probe, which
        // would report every site clean instead of surfacing the real gap.
        // (When old data already exists, mergeVariant() above has already
        // carried it forward per-variant, so $index is not actually empty here.)
        if ($index === [] && $errors !== []) {
            return [
                'production'    => $counts['production'],
                'scanner'       => $counts['scanner'],
                'index_entries' => 0,
                'errors'        => $errors,
            ];
        }

        self::write($this->cacheFile, $index);
        $this->loaded = $index;

        return [
            'production'    => $counts['production'],
            'scanner'       => $counts['scanner'],
            'index_entries' => array_sum(array_map('count', $index)),
            'errors'        => $errors,
        ];
    }

    /**
     * Read several components in ONE sequential pass. A site scan touches a few
     * dozen components out of ~18 000, so call this once with everything the
     * scan needs rather than paying a pass per lookup.
     *
     * @param list<string> $keys "type:slug" keys
     */
    public function preload(array $keys): void
    {
        $missing = array_values(array_filter(
            array_unique($keys),
            fn (string $k): bool => !array_key_exists($k, $this->loaded)
        ));

        if ($missing !== []) {
            $this->loadComponents($missing);
        }
    }

    /**
     * Streams the JSON Lines cache and decodes only the requested components.
     * Decoding the whole file instead cost 243 MB to read 35 components out of
     * 18 000 — a hard OOM at PHP's usual 128M default, which killed the whole
     * pipeline process rather than failing one probe. Memory here stays flat
     * however far Wordfence's database grows.
     *
     * @param list<string> $keys
     */
    private function loadComponents(array $keys): void
    {
        $wanted = array_fill_keys($keys, true);
        foreach ($keys as $key) {
            $this->loaded[$key] = [];   // negative results are memoized too
        }

        $handle = is_file($this->cacheFile) ? @fopen($this->cacheFile, 'rb') : false;
        if ($handle === false) {
            return;
        }

        $remaining = count($wanted);
        while ($remaining > 0 && ($line = fgets($handle)) !== false) {
            // Cheap reject before paying for json_decode on a line we don't want.
            $quoted = strpos($line, '"k":"');
            if ($quoted === false) {
                continue;
            }
            $end = strpos($line, '"', $quoted + 5);
            if ($end === false) {
                continue;
            }
            $key = substr($line, $quoted + 5, $end - $quoted - 5);
            if (!isset($wanted[$key])) {
                continue;
            }

            $row = json_decode($line, true);
            if (is_array($row) && isset($row['v']) && is_array($row['v'])) {
                $this->loaded[$key] = $row['v'];
            }
            unset($wanted[$key]);
            $remaining--;
        }

        fclose($handle);
    }

    /**
     * Server-side search across the FULL cache — every vulnerability from
     * every component, flattened to one row per vulnerability — for the
     * /data/vulnerabilities Datatables.net browser. The cache holds ~84 000
     * vulnerability records across ~18 000 components: far too many to ever
     * send to the browser at once, so this streams the file exactly like
     * preload() does (one sequential pass, never the whole cache in memory)
     * and returns only the requested page window plus the counts Datatables'
     * server-side mode needs.
     *
     * Column sorting is deliberately not supported here: sorting would mean
     * buffering every matched row before slicing a page, which defeats the
     * point of streaming. Rows come back in cache order; the UI disables
     * column sort on this table.
     *
     * @return array{total: int, filtered: int, rows: list<array<string, mixed>>}
     */
    public function search(string $query, int $offset, int $limit): array
    {
        $needle  = mb_strtolower(trim($query));
        $total   = 0;
        $matches = [];

        $handle = is_file($this->cacheFile) ? @fopen($this->cacheFile, 'rb') : false;
        if ($handle === false) {
            return ['total' => 0, 'filtered' => 0, 'rows' => []];
        }

        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row) || !isset($row['k'], $row['v']) || !is_array($row['v'])) {
                continue;
            }
            [$type, $slug] = array_pad(explode(':', (string) $row['k'], 2), 2, '');

            foreach ($row['v'] as $vuln) {
                if (!is_array($vuln)) {
                    continue;
                }
                $total++;

                if ($needle !== '') {
                    $haystack = mb_strtolower($slug . ' '
                        . ($vuln['title'] ?? '') . ' '
                        . ($vuln['cve_id'] ?? '') . ' '
                        . ($vuln['id'] ?? ''));
                    if (!str_contains($haystack, $needle)) {
                        continue;
                    }
                }

                $matches[] = [
                    'type'             => $type,
                    'slug'             => $slug,
                    'id'               => $vuln['id'] ?? null,
                    'title'            => $vuln['title'] ?? null,
                    'cve_id'           => $vuln['cve_id'] ?? null,
                    'cvss_score'       => $vuln['cvss_score'] ?? null,
                    'cvss_rating'      => $vuln['cvss_rating'] ?? null,
                    'patched'          => $vuln['patched'] ?? null,
                    'patched_versions' => $vuln['patched_versions'] ?? [],
                    'informational'    => $vuln['informational'] ?? false,
                    'source'           => $vuln['source'] ?? null,
                    'published_at'     => $vuln['published_at'] ?? null,
                    'sort_version'     => self::sortVersion($vuln),
                ];
            }
        }

        fclose($handle);

        // Ordered by version descending on every request, search or not — a
        // fixed, meaningful order beats "file order" (an artefact of refresh()'s
        // merge). This means sorting the whole filtered set before slicing a
        // page, i.e. holding it in memory — but that is the *filtered* set of
        // flat scalar rows (worst case ~84 000 of them, a few tens of MB), not
        // the full nested per-component structure whose one-shot decode is what
        // actually caused the OOM documented on preload() above.
        usort(
            $matches,
            static fn (array $a, array $b): int => self::compareVersionDesc($a['sort_version'], $b['sort_version'])
        );

        $filtered = count($matches);
        $rows     = array_slice($matches, $offset, $limit);

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $rows];
    }

    /**
     * The version to order a vulnerability row by: the highest patched version
     * when one is known, else the highest affected-range upper bound.
     *
     * @param array<string, mixed> $vuln
     */
    private static function sortVersion(array $vuln): ?string
    {
        $patched = array_values(array_filter(
            (array) ($vuln['patched_versions'] ?? []),
            static fn ($v): bool => is_string($v) && $v !== ''
        ));
        if ($patched !== []) {
            return self::maxVersion($patched);
        }

        $upperBounds = [];
        foreach ((array) ($vuln['affected_versions'] ?? []) as $range) {
            $to = is_array($range) ? ($range['to_version'] ?? null) : null;
            if (is_string($to) && $to !== '') {
                $upperBounds[] = $to;
            }
        }

        return self::maxVersion($upperBounds);
    }

    /**
     * @param list<string> $versions
     * A "*" upper bound means the range is open-ended ("every version up to
     * current") — the most urgent case, not an unknown one, so it outranks any
     * concrete version rather than being dropped or sorted last.
     */
    private static function maxVersion(array $versions): ?string
    {
        if ($versions === []) {
            return null;
        }
        if (in_array('*', $versions, true)) {
            return '*';
        }

        usort($versions, static fn ($a, $b) => version_compare((string) $b, (string) $a));

        return (string) $versions[0];
    }

    private static function compareVersionDesc(?string $a, ?string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }
        if ($a === '*' || $b === '*') {
            return $a === '*' ? -1 : 1;
        }

        return version_compare($b, $a);
    }

    /**
     * Writes the index as JSON Lines — one component per line, `{"k":…,"v":[…]}`
     * — so lookups can stream instead of decoding the whole file.
     *
     * @param array<string, list<array<string, mixed>>> $index
     */
    public static function write(string $file, array $index): void
    {
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot write Wordfence cache: {$file}");
        }

        flock($handle, LOCK_EX);
        foreach ($index as $key => $entries) {
            fwrite($handle, (string) json_encode(['k' => $key, 'v' => $entries], JSON_UNESCAPED_SLASHES) . "\n");
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * The whole cache as one map. Only for refresh(), which must merge
     * variants — a site scan must never call this.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function readAll(): array
    {
        if (!is_file($this->cacheFile)) {
            return [];
        }

        $index  = [];
        $handle = @fopen($this->cacheFile, 'rb');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['k'], $row['v'])) {
                $index[(string) $row['k']] = (array) $row['v'];
            }
        }
        fclose($handle);

        return $index;
    }

    /**
     * Reduces one raw feed (id => record, each with software[]) into
     * "type:slug" => list of compact vulnerability entries. Deliberately
     * drops "description"/"researchers"/"cwe"/"copyrights" to keep the cache
     * small — every field kept here is used by the probe or the UI.
     *
     * @param array<string, array<string, mixed>> $raw
     * @return array<string, list<array<string, mixed>>>
     */
    public static function buildIndex(array $raw, string $variant): array
    {
        $index = [];

        foreach ($raw as $id => $record) {
            if (!is_array($record)) {
                continue;
            }
            $cvss = (array) ($record['cvss'] ?? []);

            $entry = [
                'id'               => (string) ($record['id'] ?? $id),
                'title'            => (string) ($record['title'] ?? ''),
                'cve_id'           => self::stringOrNull($record['cve'] ?? null),
                'cvss_rating'      => self::stringOrNull($cvss['rating'] ?? null),
                'cvss_score'       => isset($cvss['score']) ? (float) $cvss['score'] : null,
                'published_at'     => self::stringOrNull($record['published'] ?? null),
                'informational'    => (bool) ($record['informational'] ?? false),
                'source'           => $variant,
            ];

            foreach ((array) ($record['software'] ?? []) as $software) {
                if (!is_array($software) || !isset($software['type'], $software['slug'])) {
                    continue;
                }
                $key = self::key((string) $software['type'], (string) $software['slug']);
                $index[$key][] = $entry + [
                    'patched'          => (bool) ($software['patched'] ?? false),
                    'patched_versions' => array_values(array_map('strval', (array) ($software['patched_versions'] ?? []))),
                    'affected_versions' => self::normalizeRanges((array) ($software['affected_versions'] ?? [])),
                ];
            }
        }

        return $index;
    }

    /**
     * Every entry belonging to one variant, keyed as in the source index.
     * Used to carry a failed variant's previous data forward without dragging
     * the other variant's stale entries along with it.
     *
     * @param array<string, list<array<string, mixed>>> $index
     * @return array<string, list<array<string, mixed>>>
     */
    private static function entriesFrom(array $index, string $variant): array
    {
        $only = [];
        foreach ($index as $key => $entries) {
            $matching = array_values(array_filter(
                (array) $entries,
                static fn (array $v): bool => ($v['source'] ?? null) === $variant
            ));
            if ($matching !== []) {
                $only[$key] = $matching;
            }
        }

        return $only;
    }

    /**
     * Merges one freshly-fetched variant's contribution into $base, replacing
     * only that variant's previous entries per slug (so a failed refresh of
     * the other variant is untouched). $fresh must contain only $variant's
     * entries — see entriesFrom().
     *
     * @param array<string, list<array<string, mixed>>> $base
     * @param array<string, list<array<string, mixed>>> $fresh
     * @return array<string, list<array<string, mixed>>>
     */
    private static function mergeVariant(array $base, array $fresh, string $variant): array
    {
        $keys = array_unique(array_merge(array_keys($base), array_keys($fresh)));

        $merged = [];
        foreach ($keys as $key) {
            $kept = array_values(array_filter(
                (array) ($base[$key] ?? []),
                static fn (array $v): bool => ($v['source'] ?? null) !== $variant
            ));
            $incoming = (array) ($fresh[$key] ?? []);
            $entries  = [...$kept, ...$incoming];
            if ($entries !== []) {
                $merged[$key] = $entries;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $ranges
     * @return list<array{from_version: string, from_inclusive: bool, to_version: string, to_inclusive: bool}>
     */
    private static function normalizeRanges(array $ranges): array
    {
        $normalized = [];
        foreach ($ranges as $range) {
            if (!is_array($range)) {
                continue;
            }
            $normalized[] = [
                'from_version'   => (string) ($range['from_version'] ?? '*'),
                'from_inclusive' => (bool) ($range['from_inclusive'] ?? true),
                'to_version'     => (string) ($range['to_version'] ?? '*'),
                'to_inclusive'   => (bool) ($range['to_inclusive'] ?? true),
            ];
        }

        return $normalized;
    }

    /**
     * True when $version falls inside any of the given ranges. "*" means
     * unbounded on that side (confirmed live: Wordfence uses it for both
     * "any version up to X" and, more rarely, "X and everything after").
     *
     * @param list<array<string, mixed>> $ranges
     */
    private static function rangesInclude(array $ranges, string $version): bool
    {
        foreach ($ranges as $range) {
            $from = (string) ($range['from_version'] ?? '*');
            $to   = (string) ($range['to_version'] ?? '*');

            $aboveFrom = $from === '*' || version_compare(
                $version,
                $from,
                ($range['from_inclusive'] ?? true) ? '>=' : '>'
            );
            $belowTo = $to === '*' || version_compare(
                $version,
                $to,
                ($range['to_inclusive'] ?? true) ? '<=' : '<'
            );

            if ($aboveFrom && $belowTo) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $type, string $slug): string
    {
        return strtolower($type) . ':' . strtolower($slug);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }
}
