<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\TransferStats;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Support\HostGuard;

/**
 * HTTP behaviour of the site: redirect chain (http→https, www canonical),
 * negotiated HTTP version, compression, cache/security headers,
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

    /**
     * Common backup/config/log files people leave in the webroot — the
     * single most consequential item here: `.env`/`wp-config.php.bak`
     * disclose database credentials directly, no vulnerability needed.
     */
    private const array SENSITIVE_PATHS = [
        '.env',
        'wp-config.php.bak',
        'wp-config.php~',
        '.git/config',
        'wp-content/debug.log',
        'backup.sql',
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

        if (!HostGuard::isPubliclyRoutable($site->host)) {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['Host does not resolve to a public address — refusing to connect (SSRF guard)']];
        }

        $clientOptions = [
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->timeout,
            'headers'         => ['User-Agent' => $this->userAgent],
            'http_errors'     => false,
            'verify'          => true,
            'allow_redirects' => false,
        ];
        // A site paired behind HTTP Basic Auth (staging, an IP-restriction
        // bypass, …) needs these to see anything past a 401 — see
        // KeyStore::getHttpAuth() / the site's "⚙ Site settings" panel.
        if ($site->httpAuth !== null) {
            $clientOptions['auth'] = [$site->httpAuth['username'], $site->httpAuth['password']];
        }
        $client = new Client($clientOptions);

        $errors = [];

        // 1. Redirect chain starting on plain http://
        $redirects = $this->followRedirects($client, 'http://' . $site->host . '/', $errors);

        // 2. Main request on the final URL.
        $finalUrl = $redirects['final_url'] ?? ('https://' . $site->host . '/');
        $main     = $this->mainRequest($client, $finalUrl, $errors);

        // A bare 401 on the homepage itself means the site isn't reachable
        // by an anonymous request at all — every check below would also 401
        // regardless of what it is actually testing for. That is not the
        // same fact as "checked, nothing exposed", so it must not be allowed
        // to look like one (2026-08-30, user: "pour tes vérifications de
        // fichiers sensibles, tu dis que tout est ok. Ce n'est pas ce que
        // c'est ok... c'est que le site n'est pas public"). If credentials
        // are configured and correct, the main request above already
        // authenticated and this stays false.
        $authRequired = ($main['status_code'] ?? null) === 401;

        // 3. Soft-404 detection.
        $soft404 = $this->soft404Check($client, $finalUrl, $errors);

        // 4. Compression + cache of a first-party CSS/JS asset. Servers often
        //    compress the HTML document but not their static assets (or vice versa).
        $asset = $this->assetCheck($client, $finalUrl);

        // 5. robots.txt (+ the sitemap it declares).
        $robots = $this->robotsCheck($client, $finalUrl);

        // 6. Passive exposure checks — xmlrpc.php, REST/legacy user
        //    enumeration, browsable uploads dir, common backup files, TRACE.
        $exposure = $this->exposureCheck($client, $finalUrl, ($soft404['is_soft_404'] ?? false) === true, $authRequired);

        $data = [
            'redirects'        => $redirects,
            'final_url'        => $finalUrl,
            'soft_404'         => $soft404,
            'asset'            => $asset,
            'robots'           => $robots,
            'exposure'         => $exposure,
            'auth'             => [
                'required'   => $authRequired,
                'configured' => $site->httpAuth !== null,
            ],
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

            // The starting host was already checked in collect(), but a
            // redirect can point anywhere — including an internal address a
            // public site has no business sending an anonymous prober to.
            // Same SSRF guard, applied per hop instead of once up front.
            if (!HostGuard::isSafeUrl($next)) {
                $errors[] = "Redirect chain: {$next} does not resolve to a public address — refusing to follow it (SSRF guard)";

                return ['chain' => $chain, 'forces_https' => null, 'loop_detected' => false];
            }
            $url = $next;
        }

        // $chain always has at least one entry by this point: every path out
        // of the loop above (break or exhaustion) appends to it first —
        // caught by PHPStan as dead code, not just a style nit.
        $finalUrl = end($chain)['url'];

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

    /**
     * Fetch and analyse /robots.txt, then confirm the sitemap it declares
     * (the sitemap URL comes from robots.txt, per the spec).
     *
     * @return array<string, mixed>
     */
    private function robotsCheck(Client $client, string $pageUrl): array
    {
        $origin    = $this->origin($pageUrl);
        $robotsUrl = $origin . '/robots.txt';

        try {
            $response = $client->get($robotsUrl, ['decode_content' => true]);
        } catch (GuzzleException $e) {
            return ['present' => false, 'error' => $e->getMessage()];
        }

        if ($response->getStatusCode() !== 200) {
            return ['present' => false, 'status_code' => $response->getStatusCode()];
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        // A "robots.txt" that is actually an HTML page (SPA/soft-404) is not one.
        if ($contentType !== '' && !str_contains($contentType, 'text/plain') && !str_contains($contentType, 'text/')) {
            return ['present' => false, 'status_code' => 200, 'reason' => 'Content-Type ' . $contentType];
        }

        $parsed = self::parseRobots((string) $response->getBody());
        $parsed['present']     = true;
        $parsed['url']         = $robotsUrl;

        // Verify the first declared sitemap actually resolves.
        $parsed['sitemap_reachable'] = null;
        if ($parsed['sitemaps'] !== []) {
            try {
                $sitemap = $client->head($parsed['sitemaps'][0]);
                $parsed['sitemap_reachable'] = $sitemap->getStatusCode() >= 200
                    && $sitemap->getStatusCode() < 400;
            } catch (GuzzleException) {
                $parsed['sitemap_reachable'] = false;
            }
        }

        return $parsed;
    }

    /**
     * Pure robots.txt parsing — unit-testable.
     *
     * @return array{disallow_all: bool, sitemaps: list<string>, rule_count: int}
     */
    public static function parseRobots(string $body): array
    {
        $sitemaps    = [];
        $disallowAll = false;
        $ruleCount   = 0;
        $appliesToAll = false; // are we inside a "User-agent: *" block?

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            switch ($field) {
                case 'sitemap':
                    if ($value !== '') {
                        $sitemaps[] = $value;
                    }
                    break;
                case 'user-agent':
                    $appliesToAll = $value === '*';
                    break;
                case 'disallow':
                    $ruleCount++;
                    // A bare "Disallow: /" for "*" blocks the whole site.
                    if ($appliesToAll && $value === '/') {
                        $disallowAll = true;
                    }
                    break;
            }
        }

        return [
            'disallow_all' => $disallowAll,
            'sitemaps'     => array_values(array_unique($sitemaps)),
            'rule_count'   => $ruleCount,
        ];
    }

    /**
     * Passive exposure checks — no exploitation, nothing destructive: every
     * one of these is a request any anonymous visitor (or `wpscan --enumerate`)
     * can already make. This only automates the well-known targets so an
     * analyst does not have to run a separate tool for the basics.
     *
     * Every check also records its own `evidence` (exact URL requested, HTTP
     * status, and whatever detail justified the verdict — the Location
     * header for author enumeration, the usernames actually parsed out for
     * REST enumeration, which of the sensitive paths were tried) so a finding
     * is never "trust me" — an analyst can point at exactly what was
     * requested and what came back, the same request they could re-run by
     * hand with curl.
     *
     * The sensitive-files and directory-listing checks are skipped entirely
     * on a soft-404 site (every path would answer 200 regardless of whether
     * it exists, which would report all of them as "exposed") — `null`
     * there means "not checked", never "checked, found nothing".
     *
     * Same logic, wider gate, for a site that answered the homepage itself
     * with a 401: every one of these checks would also 401 regardless of
     * what it is testing for, which would report all of them as "not
     * exposed" — a false "clean" when the truth is "not public, couldn't
     * check". `$authRequired` skips the lot up front instead of letting each
     * individual check independently (and misleadingly) read a 401 as "no".
     *
     * @return array<string, mixed>
     */
    private function exposureCheck(Client $client, string $finalUrl, bool $isSoft404, bool $authRequired): array
    {
        if ($authRequired) {
            return self::authGatedExposureResult();
        }

        $origin = $this->origin($finalUrl);

        $xmlrpcUrl = $origin . '/xmlrpc.php';
        $xmlrpc    = $this->probeExposure($client, $xmlrpcUrl);

        $restUrl   = $origin . '/wp-json/wp/v2/users';
        $restUsers = $this->probeExposure($client, $restUrl);

        $authorUrl = $origin . '/?author=1';
        $author    = $this->probeExposure($client, $authorUrl);

        $directoryListing  = null;
        $sensitiveFiles    = null;
        $uploadsEvidence   = null;
        $sensitiveEvidence = ['checked' => self::SENSITIVE_PATHS, 'found' => []];
        if (!$isSoft404) {
            $uploadsUrl = $origin . '/wp-content/uploads/';
            $uploads    = $this->probeExposure($client, $uploadsUrl);
            $directoryListing = $uploads === null ? null : self::isDirectoryListing($uploads['status'], $uploads['body']);
            $uploadsEvidence  = ['url' => $uploadsUrl, 'status' => $uploads['status'] ?? null];

            $sensitiveFiles = [];
            foreach (self::SENSITIVE_PATHS as $path) {
                $result = $this->probeExposure($client, $origin . '/' . $path);
                if ($result !== null && $result['status'] === 200) {
                    $sensitiveFiles[] = $path;
                }
            }
            $sensitiveEvidence['found'] = $sensitiveFiles;
        }

        $traceUrl = $finalUrl;
        $trace    = $this->traceCheck($client, $traceUrl);

        return [
            'xmlrpc_enabled'        => $xmlrpc === null ? null : self::isXmlrpcEnabled($xmlrpc['status'], $xmlrpc['body']),
            'rest_user_enumeration' => $restUsers === null ? null : self::isRestUserEnumerationExposed($restUsers['status'], $restUsers['body']),
            'author_enumeration'    => $author === null ? null : self::isAuthorEnumerationExposed($author['status'], $author['location']),
            'directory_listing'     => $directoryListing,
            'sensitive_files'       => $sensitiveFiles,
            'trace_enabled'         => $trace['enabled'],
            'auth_required'         => false,
            'evidence'              => [
                'xmlrpc'            => ['url' => $xmlrpcUrl, 'status' => $xmlrpc['status'] ?? null],
                'rest_users'        => ['url' => $restUrl, 'status' => $restUsers['status'] ?? null, 'usernames' => $restUsers !== null ? self::extractUsernames($restUsers['body']) : []],
                'author'            => ['url' => $authorUrl, 'status' => $author['status'] ?? null, 'location' => $author['location'] ?? null],
                'directory_listing' => $uploadsEvidence,
                'sensitive_files'   => $sensitiveEvidence,
                'trace'             => ['url' => $traceUrl, 'status' => $trace['status']],
            ],
        ];
    }

    /**
     * The result exposureCheck() returns without making a single request when
     * the site itself requires HTTP auth we don't have (or don't have right):
     * every field `null` ("not checked"), never `false` ("checked, clean") —
     * pulled out as its own pure method so the shape is unit-testable without
     * a Guzzle client.
     *
     * @return array<string, mixed>
     */
    public static function authGatedExposureResult(): array
    {
        return [
            'xmlrpc_enabled'        => null,
            'rest_user_enumeration' => null,
            'author_enumeration'    => null,
            'directory_listing'     => null,
            'sensitive_files'       => null,
            'trace_enabled'         => null,
            'auth_required'         => true,
            'evidence'              => [],
        ];
    }

    /** @return array{status: int, body: string, location: string}|null null only on a request failure (network/timeout) */
    private function probeExposure(Client $client, string $url): ?array
    {
        try {
            $response = $client->get($url);
        } catch (GuzzleException) {
            return null;
        }

        return [
            'status'   => $response->getStatusCode(),
            'body'     => (string) $response->getBody(),
            'location' => $response->getHeaderLine('Location'),
        ];
    }

    /**
     * A raw HTTP TRACE request most hardened servers reject outright
     * (405/501/403); a 200 answer is itself the exposure (reflected XST),
     * regardless of the exact body content.
     *
     * @return array{enabled: ?bool, status: ?int}
     */
    private function traceCheck(Client $client, string $url): array
    {
        try {
            $response = $client->request('TRACE', $url);
        } catch (GuzzleException) {
            return ['enabled' => null, 'status' => null];
        }

        $status = $response->getStatusCode();

        return ['enabled' => $status === 200, 'status' => $status];
    }

    /** GET xmlrpc.php answers 200 with this exact line — extremely stable across WP versions. */
    public static function isXmlrpcEnabled(int $status, string $body): bool
    {
        return $status === 200 && str_contains($body, 'XML-RPC server accepts POST requests only.');
    }

    /** A 200 JSON array of user objects (keyed by `slug`) discloses every author's username. */
    public static function isRestUserEnumerationExposed(int $status, string $body): bool
    {
        if ($status !== 200) {
            return false;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) && isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['slug']);
    }

    /**
     * The actual usernames a REST enumeration response discloses — the
     * evidence, not just the yes/no.
     *
     * @return list<string>
     */
    public static function extractUsernames(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        $slugs = [];
        foreach ($decoded as $user) {
            if (is_array($user) && isset($user['slug'])) {
                $slugs[] = (string) $user['slug'];
            }
        }

        return $slugs;
    }

    /**
     * The classic `?author=1` probe: with pretty permalinks on, WordPress
     * 301s straight to `/author/<username>/`, leaking the login in the
     * Location header alone — no page content to parse.
     */
    public static function isAuthorEnumerationExposed(int $status, string $location): bool
    {
        return in_array($status, [301, 302, 307, 308], true) && str_contains($location, '/author/');
    }

    /** A generic Apache/nginx autoindex page, not a WordPress "not found" or soft-404 catch-all. */
    public static function isDirectoryListing(int $status, string $body): bool
    {
        if ($status !== 200) {
            return false;
        }
        $lower = strtolower($body);

        return str_contains($lower, 'index of /') || str_contains($lower, 'parent directory');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Assess collected data into ok/warn.
     *
     * @param array<string, mixed> $data
     */
    private function assess(array $data): string
    {
        $asset = $data['asset'] ?? [];
        $assetUncompressed = ($asset['checked'] ?? false) === true
            && ($asset['gzip'] ?? false) === false
            && ($asset['brotli'] ?? false) === false;

        $exposure = $data['exposure'] ?? [];
        $exposed =
            ($exposure['xmlrpc_enabled'] ?? false) === true
            || ($exposure['rest_user_enumeration'] ?? false) === true
            || ($exposure['author_enumeration'] ?? false) === true
            || ($exposure['directory_listing'] ?? false) === true
            || ($exposure['trace_enabled'] ?? false) === true
            || !empty($exposure['sensitive_files']);

        $warn =
            ($data['gzip'] ?? false) === false && ($data['brotli'] ?? false) === false
            || $assetUncompressed
            || ($data['redirects']['forces_https'] ?? true) === false
            || (($data['security_headers']['x-content-type-options'] ?? null) === null)
            || (($data['soft_404']['is_soft_404'] ?? false) === true)
            || (($data['redirects']['loop_detected'] ?? false) === true)
            || $exposed;

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
