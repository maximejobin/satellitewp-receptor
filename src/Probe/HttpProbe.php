<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\TransferStats;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;

/**
 * HTTP behaviour of the site: redirect chain (http→https, www canonical),
 * negotiated HTTP version, TTFB, compression, cache/security headers,
 * server fingerprint, CDN hints, soft-404 behaviour.
 */
final class HttpProbe extends AbstractProbe
{
    private const array SECURITY_HEADERS = [
        'strict-transport-security',
        'content-security-policy',
        'x-content-type-options',
        'x-frame-options',
        'referrer-policy',
        'permissions-policy',
    ];

    private const array CDN_HEADER_HINTS = [
        'cf-ray'          => 'cloudflare',
        'x-sucuri-id'     => 'sucuri',
        'x-amz-cf-id'     => 'cloudfront',
        'x-fastly-request-id' => 'fastly',
        'x-akamai-transformed' => 'akamai',
        'x-cache-status'  => 'generic-cache',
    ];

    public function __construct(
        private readonly int $connectTimeout,
        private readonly int $timeout,
        private readonly string $userAgent,
    ) {
    }

    public function name(): string
    {
        return 'http';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        if ($site->host === '') {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['No host in site context']];
        }

        $client = new Client([
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->timeout,
            'headers'         => ['User-Agent' => $this->userAgent],
            'http_errors'     => false,
            'verify'          => true,
            'allow_redirects' => false,
        ]);

        $errors = [];

        // 1. Redirect chain starting on plain http://
        $redirects = $this->followRedirects($client, 'http://' . $site->host . '/', $errors);

        // 2. Main request on the final URL.
        $finalUrl = $redirects['final_url'] ?? ('https://' . $site->host . '/');
        $main     = $this->mainRequest($client, $finalUrl, $errors);

        // 3. Soft-404 detection.
        $soft404 = $this->soft404Check($client, $finalUrl, $errors);

        // 4. Compression + cache of a first-party CSS/JS asset. Servers often
        //    compress the HTML document but not their static assets (or vice versa).
        $asset = $this->assetCheck($client, $finalUrl);

        $data = [
            'redirects'        => $redirects,
            'final_url'        => $finalUrl,
            'soft_404'         => $soft404,
            'asset'            => $asset,
        ] + $main;

        return [
            'data'   => $data,
            'status' => $errors !== []
                ? ProbeResult::STATUS_ERROR
                : $this->assess($data),
            'errors' => $errors,
        ];
    }

    /**
     * Follow up to 10 redirects manually so we can record the chain.
     *
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function followRedirects(Client $client, string $startUrl, array &$errors): array
    {
        $chain = [];
        $url   = $startUrl;

        for ($hop = 0; $hop < 10; $hop++) {
            try {
                $response = $client->head($url);
            } catch (GuzzleException $e) {
                // Some servers refuse HEAD; retry with GET before giving up.
                try {
                    $response = $client->get($url);
                } catch (GuzzleException $e2) {
                    $errors[] = "Redirect chain: {$e2->getMessage()}";

                    return ['chain' => $chain, 'forces_https' => null, 'loop_detected' => false];
                }
            }

            $status  = $response->getStatusCode();
            $chain[] = ['url' => $url, 'status' => $status];

            if ($status < 300 || $status >= 400) {
                break;
            }

            $location = $response->getHeaderLine('Location');
            if ($location === '') {
                break;
            }

            $next = $this->resolveUrl($url, $location);
            if (in_array($next, array_column($chain, 'url'), true)) {
                $chain[] = ['url' => $next, 'status' => null];

                return ['chain' => $chain, 'forces_https' => null, 'loop_detected' => true];
            }
            $url = $next;
        }

        $finalUrl = $chain !== [] ? end($chain)['url'] : $startUrl;

        return [
            'chain'         => $chain,
            'hops'          => max(0, count($chain) - 1),
            'final_url'     => $finalUrl,
            'forces_https'  => str_starts_with($finalUrl, 'https://'),
            'loop_detected' => false,
        ];
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function mainRequest(Client $client, string $url, array &$errors): array
    {
        $stats = null;

        try {
            $response = $client->get($url, [
                'headers' => [
                    'Accept-Encoding' => 'gzip, br',
                    'Accept'          => 'text/html,application/xhtml+xml',
                ],
                'decode_content' => false,
                'version'        => 2.0, // negotiate HTTP/2 when available
                'on_stats'       => static function (TransferStats $s) use (&$stats): void {
                    $stats = $s->getHandlerStats();
                },
            ]);
        } catch (GuzzleException $e) {
            $errors[] = "Main request: {$e->getMessage()}";

            return [];
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return self::parseMainResponse($response->getStatusCode(), $headers, $stats ?? []);
    }

    /**
     * Pure parsing of the main response — unit-testable without network.
     *
     * @param array<string, string> $headers lowercased header map
     * @param array<string, mixed>  $stats curl handler stats
     * @return array<string, mixed>
     */
    public static function parseMainResponse(int $statusCode, array $headers, array $stats): array
    {
        $httpVersion = match ((int) ($stats['http_version'] ?? 0)) {
            CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2_0 => '2',
            30                    => '3', // CURL_HTTP_VERSION_3 (may be undefined on older curl)
            default               => null,
        };

        $security = [];
        foreach (self::SECURITY_HEADERS as $header) {
            $security[$header] = $headers[$header] ?? null;
        }

        $cdn = null;
        foreach (self::CDN_HEADER_HINTS as $header => $vendor) {
            if (isset($headers[$header])) {
                $cdn = $vendor;
                break;
            }
        }
        if ($cdn === null && str_contains(strtolower($headers['server'] ?? ''), 'cloudflare')) {
            $cdn = 'cloudflare';
        }

        $setCookie = $headers['set-cookie'] ?? null;

        return [
            'status_code'      => $statusCode,
            'http_version'     => $httpVersion,
            'ttfb_ms'          => isset($stats['starttransfer_time'])
                ? (int) round(((float) $stats['starttransfer_time']) * 1000)
                : null,
            'content_encoding' => $headers['content-encoding'] ?? null,
            'gzip'             => ($headers['content-encoding'] ?? '') === 'gzip',
            'brotli'           => ($headers['content-encoding'] ?? '') === 'br',
            'cache_headers'    => [
                'cache-control' => $headers['cache-control'] ?? null,
                'expires'       => $headers['expires'] ?? null,
                'etag'          => $headers['etag'] ?? null,
                'age'           => $headers['age'] ?? null,
            ],
            'security_headers' => $security,
            'fingerprint'      => [
                'server'       => $headers['server'] ?? null,
                'x-powered-by' => $headers['x-powered-by'] ?? null,
            ],
            'cdn'              => $cdn,
            'cookies'          => $setCookie === null ? null : [
                'secure'   => str_contains(strtolower($setCookie), 'secure'),
                'httponly' => str_contains(strtolower($setCookie), 'httponly'),
                'samesite' => str_contains(strtolower($setCookie), 'samesite'),
            ],
        ];
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function soft404Check(Client $client, string $baseUrl, array &$errors): array
    {
        $url = rtrim($baseUrl, '/') . '/swp-not-a-page-' . bin2hex(random_bytes(6));

        try {
            $response = $client->get($url);
        } catch (GuzzleException $e) {
            return ['checked' => false, 'error' => $e->getMessage()];
        }

        return [
            'checked'     => true,
            'status_code' => $response->getStatusCode(),
            'is_soft_404' => $response->getStatusCode() === 200,
        ];
    }

    /**
     * Fetch the HTML (decoded), find the first same-origin CSS/JS asset, then
     * measure its compression and cacheability.
     *
     * @return array<string, mixed>
     */
    private function assetCheck(Client $client, string $pageUrl): array
    {
        try {
            $html = (string) $client->get($pageUrl, [
                'headers'        => ['Accept-Encoding' => 'gzip'], // gzip is auto-decoded; keeps HTML readable
                'decode_content' => true,
            ])->getBody();
        } catch (GuzzleException $e) {
            return ['checked' => false, 'error' => $e->getMessage()];
        }

        $assetUrl = self::extractFirstAsset($html, $pageUrl);
        if ($assetUrl === null) {
            return ['checked' => false, 'reason' => 'no first-party CSS/JS asset found'];
        }

        try {
            $response = $client->get($assetUrl, [
                'headers'        => ['Accept-Encoding' => 'gzip, br'],
                'decode_content' => false,
            ]);
        } catch (GuzzleException $e) {
            return ['checked' => false, 'url' => $assetUrl, 'error' => $e->getMessage()];
        }

        $encoding     = strtolower($response->getHeaderLine('Content-Encoding'));
        $cacheControl = $response->getHeaderLine('Cache-Control');

        return [
            'checked'          => true,
            'url'              => $assetUrl,
            'content_encoding' => $encoding !== '' ? $encoding : null,
            'gzip'             => $encoding === 'gzip',
            'brotli'           => $encoding === 'br',
            'cache_control'    => $cacheControl !== '' ? $cacheControl : null,
            'max_age'          => self::cacheMaxAge($cacheControl),
        ];
    }

    /**
     * First same-origin stylesheet or script URL in the HTML — pure, testable.
     */
    public static function extractFirstAsset(string $html, string $pageUrl): ?string
    {
        $host = parse_url($pageUrl, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return null;
        }

        $candidates = [];
        if (preg_match_all('/<link\b[^>]*\brel=["\']?stylesheet[^>]*>/i', $html, $links)) {
            foreach ($links[0] as $tag) {
                if (preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $m)) {
                    $candidates[] = $m[1];
                }
            }
        }
        if (preg_match_all('/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $scripts)) {
            foreach ($scripts[1] as $src) {
                $candidates[] = $src;
            }
        }

        foreach ($candidates as $candidate) {
            $resolved = (new self(0, 0, ''))->resolveUrl($pageUrl, $candidate);
            if (parse_url($resolved, PHP_URL_HOST) === $host) {
                return strtok($resolved, '#'); // drop any fragment
            }
        }

        return null;
    }

    private static function cacheMaxAge(string $cacheControl): ?int
    {
        return preg_match('/max-age=(\d+)/i', $cacheControl, $m) ? (int) $m[1] : null;
    }

    /** Assess collected data into ok/warn. */
    private function assess(array $data): string
    {
        $asset = $data['asset'] ?? [];
        $assetUncompressed = ($asset['checked'] ?? false) === true
            && ($asset['gzip'] ?? false) === false
            && ($asset['brotli'] ?? false) === false;

        $warn =
            ($data['gzip'] ?? false) === false && ($data['brotli'] ?? false) === false
            || $assetUncompressed
            || ($data['redirects']['forces_https'] ?? true) === false
            || (($data['security_headers']['x-content-type-options'] ?? null) === null)
            || (($data['ttfb_ms'] ?? 0) > 600)
            || (($data['soft_404']['is_soft_404'] ?? false) === true)
            || (($data['redirects']['loop_detected'] ?? false) === true);

        return $warn ? ProbeResult::STATUS_WARN : ProbeResult::STATUS_OK;
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts  = parse_url($base);
        $scheme = $parts['scheme'] ?? 'http';
        $host   = $parts['host'] ?? '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir  = substr($path, -1) === '/' ? $path : dirname($path) . '/';

        return $scheme . '://' . $host . $dir . $location;
    }
}
