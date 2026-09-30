<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use Throwable;

/**
 * wordpress.org's "last updated" date for each installed plugin/theme (rule
 * F8). A probe rather than a SoftwareCatalog field: it is a live external
 * fact snapshotted with this extraction. The target is wp.org's fixed API,
 * never derived from the payload, so no SSRF guard is needed.
 */
final class WporgProbe extends AbstractProbe
{
    private const int CONCURRENCY = 5;

    private const string PLUGIN_URL = 'https://api.wordpress.org/plugins/info/1.0/%s.json';
    // Concatenated, not sprintf()'d: the pre-encoded %5B/%5D would read as format directives.
    private const string THEME_URL_BASE = 'https://api.wordpress.org/themes/info/1.1/?action=theme_information&request%5Bslug%5D=';

    /** @param Closure|null $handler Guzzle handler override (tests) */
    public function __construct(
        private readonly int $connectTimeout,
        private readonly int $timeout,
        private readonly string $userAgent,
        private readonly ?Closure $handler = null,
    ) {
    }

    public function name(): string
    {
        return 'wporg';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        $client = new Client([
            'handler'         => HandlerStack::create($this->handler),
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->timeout,
            'headers'         => ['User-Agent' => $this->userAgent],
            'http_errors'     => false,
        ]);

        $errors  = [];
        $plugins = $this->fetchAll($client, 'plugin', $site->plugins, $errors);
        $themes  = $this->fetchAll($client, 'theme', $site->themes, $errors);

        return [
            'data'   => ['plugins' => $plugins, 'themes' => $themes],
            'status' => $errors !== [] ? ProbeResult::STATUS_WARN : ProbeResult::STATUS_OK,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items raw payload.plugins / payload.themes
     * @param list<string>               $errors
     * @return array<string, array{on_wporg: bool, last_updated: ?string}>
     */
    private function fetchAll(Client $client, string $type, array $items, array &$errors): array
    {
        $slugs = [];
        foreach ($items as $item) {
            $slug = SoftwareCatalog::normalizeSlug($type, (string) ($item['slug'] ?? ''));
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }
        $slugs = array_keys($slugs);

        $requests = static function () use ($slugs, $type): \Generator {
            foreach ($slugs as $slug) {
                yield new Request('GET', $type === 'theme'
                    ? self::THEME_URL_BASE . rawurlencode($slug)
                    : sprintf(self::PLUGIN_URL, rawurlencode($slug)));
            }
        };

        $result = [];
        (new Pool($client, $requests(), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled'   => static function (ResponseInterface $response, int $i) use ($slugs, $type, &$result, &$errors): void {
                $slug   = $slugs[$i];
                $status = $response->getStatusCode();
                // 404 is both endpoints' answer for "not on wp.org" (premium,
                // custom), a normal outcome rather than a probe error.
                if ($status !== 200 && $status !== 404) {
                    $errors[] = "wp.org {$type} \"{$slug}\": HTTP {$status}";

                    return;
                }
                $result[$slug] = self::parseInfo(json_decode((string) $response->getBody(), true));
            },
            'rejected'    => static function (Throwable $reason, int $i) use ($slugs, $type, &$errors): void {
                $errors[] = "wp.org {$type} \"{$slugs[$i]}\": {$reason->getMessage()}";
            },
        ]))->promise()->wait();

        ksort($result);

        return $result;
    }

    /**
     * Pure parser. An unknown slug answers `false` (themes) or an
     * {"error":…} object (plugins); last_updated comes as "2026-08-18
     * 11:42pm GMT" (plugins) or "2025-12-09" (themes), both strtotime()-able.
     *
     * @param mixed $decoded json_decode() of the raw response body
     * @return array{on_wporg: bool, last_updated: ?string}
     */
    public static function parseInfo(mixed $decoded): array
    {
        if (!is_array($decoded) || isset($decoded['error']) || ($decoded['slug'] ?? null) === null) {
            return ['on_wporg' => false, 'last_updated' => null];
        }

        $raw       = $decoded['last_updated'] ?? null;
        $timestamp = is_string($raw) && $raw !== '' ? strtotime($raw) : false;

        return [
            'on_wporg'     => true,
            'last_updated' => $timestamp !== false ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null,
        ];
    }
}
