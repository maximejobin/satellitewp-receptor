<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Catalog;

use SatelliteWP\Xtractor\Support\AtomicFile;

/**
 * A growing, cross-site reference of every plugin and theme ever seen, keyed by
 * type + wp.org-style slug. Each extraction feeds it new slugs; an analyst then
 * classifies each one's licensing so the team can tell at a glance which
 * plugins/themes likely need a paid licence.
 *
 * Stored as one JSON file (data/catalog/software.json). No language, no network
 * here — enriching from wp.org is done separately and only records a neutral
 * "source" + "suggested" value.
 */
final class SoftwareCatalog
{
    public const string LICENSE_FREE    = 'free';
    public const string LICENSE_PREMIUM = 'premium';
    public const string LICENSE_MIXED   = 'mixed';   // free plugin that unlocks a paid licence (e.g. MailPoet)
    public const string LICENSE_CUSTOM  = 'custom';  // bespoke code, not a wp.org/marketplace plugin at all
    public const string LICENSE_UNKNOWN = 'unknown';

    public const array LICENSES = [
        self::LICENSE_FREE, self::LICENSE_PREMIUM, self::LICENSE_MIXED, self::LICENSE_CUSTOM, self::LICENSE_UNKNOWN,
    ];

    /** @var array<string, array<string, mixed>>|null lazy-loaded cache */
    private ?array $entries = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return int number of newly discovered slugs
     */
    public function recordExtraction(array $payload): int
    {
        return $this->mutate(function () use ($payload): array {
            $new = 0;

            foreach (['plugin' => $payload['plugins'] ?? [], 'theme' => $payload['themes'] ?? []] as $type => $items) {
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $slug = self::normalizeSlug($type, (string) ($item['slug'] ?? ''));
                    if ($slug === '') {
                        continue;
                    }
                    $new += $this->upsert($type, $slug, (string) ($item['name'] ?? $slug));
                }
            }

            return [$new, true];
        });
    }

    /**
     * @param 'plugin'|'theme' $type
     * @return int 1 if newly created, 0 if it already existed
     */
    private function upsert(string $type, string $slug, string $name): int
    {
        $key = $type . ':' . $slug;

        if (isset($this->entries[$key])) {
            // Keep the friendliest known name.
            if ($name !== $slug && ($this->entries[$key]['name'] ?? '') === $slug) {
                $this->entries[$key]['name'] = $name;
            }

            return 0;
        }

        $this->entries[$key] = [
            'type'      => $type,
            'slug'      => $slug,
            'name'      => $name,
            'license'   => self::LICENSE_UNKNOWN,
            'suggested' => null,
            'source'    => 'unknown', // wporg | absent | unknown (repo presence not yet checked)
        ];

        return 1;
    }

    /**
     * Set the analyst-decided licence for one entry.
     *
     * @param 'plugin'|'theme' $type
     */
    public function setLicense(string $type, string $slug, string $license): bool
    {
        if (!in_array($license, self::LICENSES, true)) {
            return false;
        }
        $key = $type . ':' . self::normalizeSlug($type, $slug);

        return $this->mutate(function () use ($key, $license): array {
            if (!isset($this->entries[$key])) {
                return [false, false];
            }
            $this->entries[$key]['license'] = $license;

            return [true, true];
        });
    }

    /**
     * Fill "source" (wp.org repo presence) and a "suggested" licence for entries
     * not yet checked. $isOnWporg(type, slug) returns whether the slug exists in
     * the wp.org repository. Suggestion: on the repo → free (may be refined to
     * mixed by an analyst); absent → premium.
     *
     * @param callable(string, string): bool $isOnWporg
     * @return int number of entries updated
     */
    public function suggest(callable $isOnWporg, bool $recheck = false): int
    {
        // The wp.org lookups are slow network calls — run them on a snapshot,
        // outside the lock, then merge only the two fields they decide.
        $this->reload();
        $results = [];
        foreach ((array) $this->entries as $key => $entry) {
            if (!$recheck && ($entry['source'] ?? 'unknown') !== 'unknown') {
                continue;
            }
            $results[$key] = (bool) $isOnWporg($entry['type'], $entry['slug']);
        }
        if ($results === []) {
            return 0;
        }

        return $this->mutate(function () use ($results): array {
            $updated = 0;
            foreach ($results as $key => $onRepo) {
                if (!isset($this->entries[$key])) {
                    continue;
                }
                $this->entries[$key]['source']    = $onRepo ? 'wporg' : 'absent';
                $this->entries[$key]['suggested'] = $onRepo ? self::LICENSE_FREE : self::LICENSE_PREMIUM;
                $updated++;
            }

            return [$updated, $updated > 0];
        });
    }

    /**
     * @param 'plugin'|'theme'|null $type
     * @return list<array<string, mixed>>
     */
    public function all(?string $type = null, bool $onlyNeedsLicense = false): array
    {
        $this->load();
        $rows = array_values($this->entries);

        if ($type !== null) {
            $rows = array_values(array_filter($rows, static fn ($e) => $e['type'] === $type));
        }
        if ($onlyNeedsLicense) {
            $rows = array_values(array_filter($rows, static fn ($e) => self::needsLicense($e)));
        }

        usort($rows, static fn ($a, $b) => [$a['type'], strtolower($a['name'])] <=> [$b['type'], strtolower($b['name'])]);

        return $rows;
    }

    /**
     * Datatables server-side search for /catalog: all()'s filters plus a
     * slug/name query and pagination. The file is small enough to filter in PHP.
     *
     * @param 'plugin'|'theme'|null $type
     * @return array{total: int, filtered: int, rows: list<array<string, mixed>>}
     */
    public function search(
        ?string $type,
        bool $onlyNeedsLicense,
        bool $onlyUnclassified,
        string $query,
        int $start,
        int $length,
    ): array {
        $this->load();
        $all   = array_values($this->entries);
        $total = count($all);

        $rows = $all;
        if ($type !== null) {
            $rows = array_values(array_filter($rows, static fn ($e) => $e['type'] === $type));
        }
        if ($onlyNeedsLicense) {
            $rows = array_values(array_filter($rows, static fn ($e) => self::needsLicense($e)));
        }
        if ($onlyUnclassified) {
            $rows = array_values(array_filter($rows, static fn ($e) => ($e['license'] ?? 'unknown') === 'unknown'));
        }
        if ($query !== '') {
            $q    = strtolower($query);
            $rows = array_values(array_filter(
                $rows,
                static fn ($e) => str_contains(strtolower((string) $e['slug']), $q)
                    || str_contains(strtolower((string) $e['name']), $q)
            ));
        }

        usort($rows, static fn ($a, $b) => [$a['type'], strtolower($a['name'])] <=> [$b['type'], strtolower($b['name'])]);

        $filtered = count($rows);
        $rows     = $length > 0 ? array_slice($rows, $start, $length) : $rows;

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $rows];
    }

    /**
     * @param 'plugin'|'theme' $type
     * @return array<string, mixed>|null
     */
    public function get(string $type, string $slug): ?array
    {
        $this->load();

        return $this->entries[$type . ':' . self::normalizeSlug($type, $slug)] ?? null;
    }

    /**
     * The licence to act on: the analyst's choice, else the suggestion.
     *
     * @param array<string, mixed> $entry
     */
    public static function effectiveLicense(array $entry): string
    {
        $license = (string) ($entry['license'] ?? self::LICENSE_UNKNOWN);

        return $license !== self::LICENSE_UNKNOWN
            ? $license
            : (string) ($entry['suggested'] ?? self::LICENSE_UNKNOWN);
    }

    /** @param array<string, mixed> $entry */
    public static function needsLicense(array $entry): bool
    {
        return in_array(self::effectiveLicense($entry), [self::LICENSE_PREMIUM, self::LICENSE_MIXED], true);
    }

    /**
     * Plugin payload slugs look like "woocommerce/woocommerce.php"; the wp.org
     * slug is the directory. Single-file plugins ("hello.php") use the basename.
     * Theme slugs are already the directory.
     */
    public static function normalizeSlug(string $type, string $slug): string
    {
        $slug = trim($slug);
        if ($type === 'plugin' && str_contains($slug, '/')) {
            return explode('/', $slug)[0];
        }
        if ($type === 'plugin') {
            return preg_replace('/\.php$/', '', $slug) ?? $slug;
        }

        return $slug;
    }

    private function load(): void
    {
        if ($this->entries !== null) {
            return;
        }
        $this->reload();
    }

    private function reload(): void
    {
        $this->entries = [];
        if (is_file($this->file)) {
            $decoded = json_decode((string) file_get_contents($this->file), true);
            if (is_array($decoded)) {
                $this->entries = $decoded;
            }
        }
    }

    /**
     * Every write goes through here: exclusive lock, fresh read from disk,
     * change, atomic save. A long-lived instance (ingest:process runs for
     * minutes) otherwise saves its stale in-memory copy over whatever an
     * analyst classified in the UI meanwhile.
     *
     * @template T
     * @param callable(): array{0: T, 1: bool} $change returns [result, whether to save]
     * @return T
     */
    private function mutate(callable $change): mixed
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }

        $lock = fopen($this->file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Cannot lock {$this->file}");
        }

        try {
            $this->reload();
            [$result, $dirty] = $change();
            if ($dirty) {
                $this->save();
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function save(): void
    {
        $json = json_encode(
            $this->entries,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new \RuntimeException("Unable to encode {$this->file}");
        }
        AtomicFile::write($this->file, $json);
    }
}
