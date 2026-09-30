<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Reference;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use SatelliteWP\Xtractor\Support\AtomicFile;

/**
 * Every WordPress release with wordpress.org's own verdict on it (the
 * "stable check" core uses for its security nag). Per explicit version
 * ("6.4.3"), unlike endoflife.date's per-branch cycles. Cached under
 * data/reference/wordpress-versions.json by `reference:refresh`.
 */
final class WordPressVersions
{
    private const API = 'https://api.wordpress.org/core/stable-check/1.0/';

    /** @var array<string, string>|null version => raw wordpress.org status, cached in-process */
    private ?array $loaded = null;

    public function __construct(private readonly string $cacheFile)
    {
    }

    /**
     * Every known version and wordpress.org's raw verdict on it: "insecure",
     * "outdated", "latest", or "" (old but still secure). Empty when the cache
     * has not been refreshed yet.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        if (!is_file($this->cacheFile)) {
            return $this->loaded = [];
        }

        $decoded = json_decode((string) file_get_contents($this->cacheFile), true);

        return $this->loaded = is_array($decoded) ? $decoded : [];
    }

    /**
     * wordpress.org's 4 raw values collapsed to 3: only "insecure" implies a
     * security update ("unsecure"); "latest" is "uptodate"; "outdated" and ""
     * (supported, older) are "secure" — displayed as "Outdated".
     */
    public static function status(string $rawStatus): string
    {
        return match ($rawStatus) {
            'insecure' => 'unsecure',
            'latest'   => 'uptodate',
            default    => 'secure',
        };
    }

    /**
     * The version wordpress.org marks "latest" — independent of the site's
     * self-reported update offer, which can be stale or blocked. Null when
     * the cache is empty.
     */
    public function latestVersion(): ?string
    {
        $latest = array_search('latest', $this->all(), true);

        return $latest !== false ? (string) $latest : null;
    }

    public function majorVersionsBehind(string $installedVersion): ?int
    {
        $all = $this->all();
        if ($all === []) {
            return null;
        }

        $latestVersion = $this->latestVersion();
        if ($latestVersion === null) {
            return null;
        }

        $latestBranch    = EndOfLife::branch((string) $latestVersion);
        $installedBranch = EndOfLife::branch($installedVersion);
        if ($latestBranch === '' || $installedBranch === '') {
            return null;
        }

        $branches = [$latestBranch => true, $installedBranch => true];
        foreach (array_keys($all) as $version) {
            $branches[EndOfLife::branch($version)] = true;
        }
        $branchList = array_keys($branches);
        usort($branchList, 'version_compare');

        $latestIndex    = array_search($latestBranch, $branchList, true);
        $installedIndex = array_search($installedBranch, $branchList, true);
        if ($latestIndex === false || $installedIndex === false) {
            return null;
        }

        return max(0, $latestIndex - $installedIndex);
    }

    /** When the cache was last written, or null if it never has been. */
    public function refreshedAt(): ?string
    {
        $time = is_file($this->cacheFile) ? filemtime($this->cacheFile) : false;

        return $time !== false ? gmdate('Y-m-d\TH:i:s\Z', $time) : null;
    }

    /** Refreshes the cache from wordpress.org. Returns the number of versions cached. */
    public function refresh(int $timeout = 15): int
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create reference cache dir: {$dir}");
        }

        $client = new Client(['timeout' => $timeout, 'headers' => ['Accept' => 'application/json']]);

        try {
            $body = (string) $client->get(self::API)->getBody();
        } catch (GuzzleException $e) {
            throw new RuntimeException("wordpress.org stable-check: {$e->getMessage()}", 0, $e);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('wordpress.org stable-check: invalid JSON');
        }

        AtomicFile::write($this->cacheFile, $body);
        $this->loaded = $decoded;

        return count($decoded);
    }
}
