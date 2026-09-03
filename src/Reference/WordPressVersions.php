<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Reference;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Every WordPress version core itself considers a real release, straight from
 * wordpress.org's own "stable check" service — the same list core uses to
 * decide whether an install needs a security nag. This is a different, more
 * precise source than endoflife.date's `cycles('wordpress')` (EndOfLife.php):
 * endoflife.date tracks one row per minor *branch* ("6.4"), while this tracks
 * every *explicit* release ("6.4.3") with wordpress.org's own verdict on it —
 * exactly the distinction needed for a per-version secure/insecure/latest
 * status. Cached locally under data/reference/wordpress-versions.json,
 * refreshed by `reference:refresh` alongside the endoflife.date cycle data
 * whenever "wordpress" is among the refreshed products.
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
     * The 3-state badge this project displays, collapsed from wordpress.org's
     * own 4 raw values: "insecure" is the only one that means an update
     * actually matters for security, so it alone becomes "unsecure"; "latest"
     * becomes "uptodate"; everything else ("outdated" — a supported older
     * release with a newer one available — and "" — supported, no update
     * offered) becomes "secure", since neither implies a known vulnerability
     * (the UI labels this bucket "Outdated" rather than "Secure" — old but
     * safe is not the same as current, and the label should not read like
     * the recommended state).
     */
    public static function status(string $rawStatus): string
    {
        return match ($rawStatus) {
            'insecure' => 'unsecure',
            'latest'   => 'uptodate',
            default    => 'secure',
        };
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

        file_put_contents($this->cacheFile, $body, LOCK_EX);
        $this->loaded = $decoded;

        return count($decoded);
    }
}
