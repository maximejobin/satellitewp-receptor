<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Reference;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * End-of-life reference data sourced from endoflife.date and cached locally
 * under data/reference/<product>.json. This is server-side reference data
 * (SOURCE 14): refreshed on a schedule by `reference:refresh`, read offline by
 * the rule engine — evaluation never hits the network.
 */
final class EndOfLife
{
    private const string API = 'https://endoflife.date/api';

    /** @var array<string, list<array<string, mixed>>> product => cycles, cached in-process */
    private array $loaded = [];

    public function __construct(private readonly string $cacheDir)
    {
    }

    /**
     * Cycles for a product as returned by endoflife.date, from the local cache.
     * Returns [] when the product has not been refreshed yet.
     *
     * @return list<array<string, mixed>>
     */
    public function cycles(string $product): array
    {
        if (isset($this->loaded[$product])) {
            return $this->loaded[$product];
        }

        $file = $this->cacheFile($product);
        if (!is_file($file)) {
            return $this->loaded[$product] = [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return $this->loaded[$product] = is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * The cycle record for a version's branch (e.g. "8.3.11" -> cycle "8.3"),
     * or null when unknown.
     *
     * @return array<string, mixed>|null
     */
    public function cycleFor(string $product, string $version): ?array
    {
        $branch = self::branch($version);

        foreach ($this->cycles($product) as $cycle) {
            if ((string) ($cycle['cycle'] ?? '') === $branch) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * Interpret an endoflife.date "eol" field (a date string, true, or false)
     * against "now". Returns [isEol, eolDate|null]; null when unknown.
     *
     * @return array{0: bool, 1: string|null}|null
     */
    public function eolStatus(string $product, string $version, string $field = 'eol'): ?array
    {
        $cycle = $this->cycleFor($product, $version);
        if ($cycle === null || !array_key_exists($field, $cycle)) {
            return null;
        }

        $value = $cycle[$field];

        if (is_bool($value)) {
            return [$value, null]; // true = already EOL, false = still supported
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }

        return [$timestamp < time(), gmdate('Y-m-d', $timestamp)];
    }

    /**
     * Refresh the given products from endoflife.date into the cache.
     *
     * @param list<string> $products
     * @return array<string, int> product => number of cycles fetched
     */
    public function refresh(array $products, int $timeout = 15): array
    {
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException("Cannot create reference cache dir: {$this->cacheDir}");
        }

        $client  = new Client(['timeout' => $timeout, 'headers' => ['Accept' => 'application/json']]);
        $results = [];

        foreach ($products as $product) {
            try {
                $body = (string) $client->get(self::API . '/' . rawurlencode($product) . '.json')->getBody();
            } catch (GuzzleException $e) {
                throw new RuntimeException("endoflife.date {$product}: {$e->getMessage()}", 0, $e);
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException("endoflife.date {$product}: invalid JSON");
            }

            file_put_contents($this->cacheFile($product), $body, LOCK_EX);
            unset($this->loaded[$product]);
            $results[$product] = count($decoded);
        }

        return $results;
    }

    /** First two dotted segments: "8.3.11" -> "8.3", "6.8" -> "6.8". */
    public static function branch(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /** When this product's cache was last written, or null if it never has been. */
    public function refreshedAt(string $product): ?string
    {
        $file = $this->cacheFile($product);
        $time = is_file($file) ? filemtime($file) : false;

        return $time !== false ? gmdate('Y-m-d\TH:i:s\Z', $time) : null;
    }

    private function cacheFile(string $product): string
    {
        return $this->cacheDir . '/' . preg_replace('/[^a-z0-9._-]/i', '', $product) . '.json';
    }
}
