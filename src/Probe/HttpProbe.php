<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Support\HostGuard;

/**
 * HTTP behaviour of the site: redirect chain (http→https, www canonical),
 * negotiated HTTP version, compression, cache/security headers, server
 * fingerprint, CDN hints, soft-404 behaviour and passive exposure checks.
 *
 * Every request connects to the address the SSRF guard vetted (pinned via
 * CURLOPT_RESOLVE), never to a second independent lookup.
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
    ];

    /**
     * Backup/config/log files commonly left in the webroot, each with the
     * content signature that proves the real file was served (an HTML error
     * page answered with 200 is not an exposure).
     */
    private const array SENSITIVE_PATHS = [
        '.env'                 => '/^\s*[A-Z][A-Z0-9_]*\s*=/m',
        'wp-config.php.bak'    => '/define\s*\(\s*[\'"](?:DB_NAME|DB_PASSWORD|DB_USER|AUTH_KEY)/',
        'wp-config.php~'       => '/define\s*\(\s*[\'"](?:DB_NAME|DB_PASSWORD|DB_USER|AUTH_KEY)/',
        '.git/config'          => '/^\s*\[core\]/m',
        'wp-content/debug.log' => '/PHP (?:Fatal error|Warning|Notice|Deprecated|Parse error)/',
        'backup.sql'           => '/(?:CREATE TABLE|INSERT INTO|-- (?:MySQL|MariaDB) dump)/i',
    ];

    /** Only the start of a sensitive file is read — enough for its signature, never a multi-GB dump. */
    private const int SENSITIVE_HEAD_BYTES = 4096;
    private const int SENSITIVE_MAX_BYTES  = 1_048_576;

    private readonly Closure $resolveIp;

    /**
     * @param Closure|null $handler   Guzzle handler override (tests)
     * @param Closure|null $resolveIp host => vetted public IP or null (default HostGuard::publicIpFor)
     */
    public function __construct(
        private readonly int $connectTimeout,
        private readonly int $timeout,
        private readonly string $userAgent,
        private readonly ?Closure $handler = null,
        ?Closure $resolveIp = null,
    ) {
        $this->resolveIp = $resolveIp ?? HostGuard::publicIpFor(...);
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

        if (($this->resolveIp)($site->host) === null) {
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
        $stack = HandlerStack::create($this->handler);
        $stack->push(self::pinToVettedAddress($this->resolveIp), 'pin_vetted_address');
        // Per request, never client-wide: the chain starts on plain http:// and
        // can hop to another host, and neither may see the credentials.
        if ($site->httpAuth !== null) {
            $header   = 'Basic ' . base64_encode($site->httpAuth['username'] . ':' . $site->httpAuth['password']);
            $siteHost = $site->host;
            $stack->push(Middleware::mapRequest(
                static fn (RequestInterface $request): RequestInterface => self::shouldSendCredentials((string) $request->getUri(), $siteHost)
                    ? $request->withHeader('Authorization', $header)
                    : $request->withoutHeader('Authorization')
            ), 'site_http_auth');
        }
        $clientOptions['handler'] = $stack;
        $client = new Client($clientOptions);

        $errors = [];

        // 1. The http:// chain answers only "does the site force HTTPS" (A10).
        // Its landing URL is never reused: a plain-HTTP vhost can be broken
        // independently of the site visitors actually reach.
        $redirects = $this->followRedirects($client, 'http://' . $site->host . '/', $errors);

        // 2. Everything else targets the public homepage (home_url), followed
        // through its own redirects.
        $mainStartUrl = self::pickMainStartUrl($site->homeUrl, $site->siteUrl, $site->host);
        if (!$this->isSafeUrl($mainStartUrl)) {
            $errors[]     = "home_url \"{$mainStartUrl}\" does not resolve to a public address — refusing to connect (SSRF guard)";
            $mainStartUrl = 'https://' . $site->host . '/';
        }
        $mainRedirects = $this->followRedirects($client, $mainStartUrl, $errors);
        $finalUrl      = $mainRedirects['final_url'] ?? $mainStartUrl;
        $main          = $this->mainRequest($client, $finalUrl, $errors);

        $authRequired = ($main['status_code'] ?? null) === 401;

        $soft404     = $this->soft404Check($client, $finalUrl, $errors);
        // Servers often compress the HTML document but not their static assets.
        $asset       = $this->assetCheck($client, $finalUrl);
        $robots      = $this->robotsCheck($client, $finalUrl);
        $exposure    = $this->exposureCheck($client, $finalUrl, ($soft404['is_soft_404'] ?? false) === true, $authRequired);
        $compression = $this->compressionSupportCheck($client, $finalUrl);
        $protocols = $this->protocolSupportCheck($client, $finalUrl, $main['http_version'] ?? null, $main['alt_svc'] ?? null);

        $data = [
            'redirects'        => $redirects,
            'final_url'        => $finalUrl,
            'soft_404'         => $soft404,
            'asset'            => $asset,
            'robots'           => $robots,
            'exposure'         => $exposure,
            'compression'      => $compression,
            'protocols'        => $protocols,
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
     * The URL every check past the http:// chain targets: home_url (the
     * public homepage — site_url is where core's files live, possibly /wp),
     * then site_url, then an https guess from the host.
     */
    public static function pickMainStartUrl(string $homeUrl, string $siteUrl, string $host): string
    {
        if ($homeUrl !== '') {
            return $homeUrl;
        }

        return $siteUrl !== '' ? $siteUrl : ('https://' . $host . '/');
    }

    private function isSafeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' && ($this->resolveIp)($host) !== null;
    }

    /**
     * Follow up to 10 redirects manually to record the chain. GET, not HEAD:
     * some servers answer HEAD differently from what a browser receives.
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
                $response = $client->get($url);
            } catch (GuzzleException $e) {
                $errors[] = "Redirect chain: {$e->getMessage()}";

                return ['chain' => $chain, 'forces_https' => null, 'loop_detected' => false];
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

            $next = self::resolveUrl($url, $location);
            if (in_array($next, array_column($chain, 'url'), true)) {
                $chain[] = ['url' => $next, 'status' => null];

                return ['chain' => $chain, 'forces_https' => null, 'loop_detected' => true];
            }

            // A redirect can point anywhere, including an internal address.
            if (!$this->isSafeUrl($next)) {
                $errors[] = "Redirect chain: {$next} does not resolve to a public address — refusing to follow it (SSRF guard)";

                return ['chain' => $chain, 'forces_https' => null, 'loop_detected' => false];
            }
            $url = $next;
        }

        // Never empty here: every exit from the loop above appended first.
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
                'curl'           => [\CURLOPT_FRESH_CONNECT => true],
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

        $parsed = self::parseMainResponse($response->getStatusCode(), $headers, $stats ?? [], $response->getHeader('Set-Cookie'));

        // The exact request made, so an analyst can re-run it by hand.
        $parsed['request'] = [
            'method'  => 'GET',
            'url'     => $url,
            'headers' => [
                'Accept-Encoding' => 'gzip, br',
                'Accept'          => 'text/html,application/xhtml+xml',
                'User-Agent'      => $this->userAgent,
            ],
        ];

        return $parsed;
    }

    /**
     * Pure parsing of the main response.
     *
     * @param array<string, string> $headers    lowercased header map
     * @param array<string, mixed>  $stats      curl handler stats
     * @param list<string>          $setCookies one entry per Set-Cookie header (defaults to the joined header)
     * @return array<string, mixed>
     */
    public static function parseMainResponse(int $statusCode, array $headers, array $stats, array $setCookies = []): array
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

        if ($setCookies === [] && isset($headers['set-cookie'])) {
            $setCookies = [$headers['set-cookie']];
        }

        // Set-Cookie values can be visitors' session ids: only the flag
        // summary below is ever stored.
        unset($headers['set-cookie']);

        return [
            'status_code'      => $statusCode,
            'http_version'     => $httpVersion,
            'content_encoding' => $headers['content-encoding'] ?? null,
            'gzip'             => ($headers['content-encoding'] ?? '') === 'gzip',
            'brotli'           => ($headers['content-encoding'] ?? '') === 'br',
            'alt_svc'          => $headers['alt-svc'] ?? null,
            'headers'          => $headers,
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
            'cookies'          => self::cookieFlags($setCookies),
        ];
    }

    /**
     * Pure. Each flag is true only when EVERY cookie carries it — one secure
     * cookie must not vouch for the others. SameSite counts only as Lax or
     * Strict (None offers no CSRF protection).
     *
     * @param list<string> $setCookies
     * @return array{secure: bool, httponly: bool, samesite: bool}|null null when no cookie was set
     */
    public static function cookieFlags(array $setCookies): ?array
    {
        if ($setCookies === []) {
            return null;
        }

        $flags = ['secure' => true, 'httponly' => true, 'samesite' => true];
        foreach ($setCookies as $cookie) {
            $attributes = array_map(
                static fn (string $a): string => strtolower(trim($a)),
                array_slice(explode(';', $cookie), 1)
            );
            $flags['secure']   = $flags['secure'] && in_array('secure', $attributes, true);
            $flags['httponly'] = $flags['httponly'] && in_array('httponly', $attributes, true);
            $flags['samesite'] = $flags['samesite']
                && (in_array('samesite=lax', $attributes, true) || in_array('samesite=strict', $attributes, true));
        }

        return $flags;
    }

    /**
     * Offers gzip and brotli one at a time, so "false" means the server
     * cannot produce that encoding — the main request only shows which one
     * it prefers when both are offered.
     *
     * @return array{gzip: bool|null, brotli: bool|null} null when the request itself failed
     */
    private function compressionSupportCheck(Client $client, string $url): array
    {
        return [
            'gzip'   => $this->offeredEncodingIsHonoured($client, $url, 'gzip', 'gzip'),
            'brotli' => $this->offeredEncodingIsHonoured($client, $url, 'br', 'br'),
        ];
    }

    private function offeredEncodingIsHonoured(Client $client, string $url, string $offer, string $expect): ?bool
    {
        try {
            $response = $client->get($url, [
                'headers'        => ['Accept-Encoding' => $offer],
                'decode_content' => false,
            ]);
        } catch (GuzzleException) {
            return null; // inconclusive, not a "no"
        }

        return strtolower($response->getHeaderLine('Content-Encoding')) === $expect;
    }

    /**
     * Protocol support per version. HTTP/2 reuses the main request's ALPN
     * result; HTTP/1.1 gets its own forced request; HTTP/3 reads Alt-Svc
     * (this libcurl cannot negotiate QUIC).
     *
     * @return array{http1_1: bool|null, http2: bool|null, http3_advertised: bool|null}
     */
    private function protocolSupportCheck(Client $client, string $url, ?string $negotiatedMainVersion, ?string $altSvc): array
    {
        $stats = null;
        $http1_1 = null;
        try {
            $client->get($url, [
                'version'  => '1.1',
                // A reused HTTP/2 connection would make HTTP/1.1 read as unsupported.
                'curl'     => [\CURLOPT_FRESH_CONNECT => true],
                'on_stats' => static function (TransferStats $s) use (&$stats): void {
                    $stats = $s->getHandlerStats();
                },
            ]);
            $http1_1 = (int) ($stats['http_version'] ?? 0) === CURL_HTTP_VERSION_1_1;
        } catch (GuzzleException) {
            // inconclusive: leave null
        }

        return [
            'http2'            => $negotiatedMainVersion === null ? null : $negotiatedMainVersion === '2',
            'http1_1'          => $http1_1,
            'http3_advertised' => $altSvc === null ? null : self::altSvcAdvertisesHttp3($altSvc),
        ];
    }

    /**
     * Whether Alt-Svc advertises HTTP/3 ("h3" or a draft "h3-XX") — what the
     * site announces, not proof a QUIC handshake would succeed.
     */
    public static function altSvcAdvertisesHttp3(?string $altSvc): bool
    {
        if ($altSvc === null || $altSvc === '') {
            return false;
        }

        return (bool) preg_match('/(?:^|,)\s*h3(?:-\d+)?\s*=/i', $altSvc);
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
            $resolved = self::resolveUrl($pageUrl, $candidate);
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
        $body        = (string) $response->getBody();
        // An HTML page answered for robots.txt (SPA, soft-404) is not one.
        if (($contentType !== '' && !str_starts_with($contentType, 'text/')) || self::looksLikeHtml($body)) {
            return ['present' => false, 'status_code' => 200, 'reason' => 'Content-Type ' . $contentType];
        }

        $parsed = self::parseRobots($body);
        $parsed['present']     = true;
        $parsed['url']         = $robotsUrl;

        $parsed['sitemap_reachable'] = null;
        $parsed['sitemap_source']    = null;
        if ($parsed['sitemaps'] !== []) {
            $parsed['sitemap_source'] = 'robots.txt';
            try {
                $sitemap = $client->head($parsed['sitemaps'][0]);
                $parsed['sitemap_reachable'] = $sitemap->getStatusCode() >= 200
                    && $sitemap->getStatusCode() < 400;
            } catch (GuzzleException) {
                $parsed['sitemap_reachable'] = false;
            }
        } else {
            // Core never declares its sitemap in robots.txt: try the conventional URLs.
            foreach (['/wp-sitemap.xml', '/sitemap.xml', '/sitemap_index.xml'] as $path) {
                try {
                    $candidate = $client->head($origin . $path);
                } catch (GuzzleException) {
                    continue;
                }
                if ($candidate->getStatusCode() >= 200 && $candidate->getStatusCode() < 400) {
                    $parsed['sitemaps']          = [$origin . $path];
                    $parsed['sitemap_reachable'] = true;
                    $parsed['sitemap_source']    = 'convention';
                    break;
                }
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
     * Passive exposure checks: only requests any anonymous visitor can make.
     * Each check records its evidence (URL, status, the detail behind the
     * verdict) so an analyst can re-run it by hand.
     *
     * null means "not checked": the file/listing checks are skipped on a
     * soft-404 site (every path answers 200), and everything is skipped when
     * the homepage itself requires auth (every path would 401 and read clean).
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
        $sensitiveEvidence = ['checked' => array_keys(self::SENSITIVE_PATHS), 'found' => [], 'unverified' => []];
        if (!$isSoft404) {
            $uploadsUrl = $origin . '/wp-content/uploads/';
            $uploads    = $this->probeExposure($client, $uploadsUrl);
            $directoryListing = $uploads === null ? null : self::isDirectoryListing($uploads['status'], $uploads['body']);
            $uploadsEvidence  = ['url' => $uploadsUrl, 'status' => $uploads['status'] ?? null];

            $sensitiveFiles = [];
            foreach (array_keys(self::SENSITIVE_PATHS) as $path) {
                $head = $this->fetchHead($client, $origin . '/' . $path);
                if ($head === null) {
                    $sensitiveEvidence['unverified'][] = $path;
                    continue;
                }
                if (self::isSensitiveFileExposed($path, $head['status'], $head['content_type'], $head['head'])) {
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
     * Guzzle middleware pinning each request to the address $resolve vetted,
     * refusing the request when it returns null.
     *
     * @param callable(string): ?string $resolve
     * @return callable(callable): callable
     */
    public static function pinToVettedAddress(callable $resolve): callable
    {
        return static function (callable $handler) use ($resolve): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $resolve) {
                $uri  = $request->getUri();
                $host = $uri->getHost();
                $ip   = $host !== '' ? $resolve($host) : null;
                if ($ip === null) {
                    return Create::rejectionFor(new ConnectException(
                        "Refusing to connect to {$host}: it does not resolve to a public address (SSRF guard)",
                        $request
                    ));
                }
                $port = $uri->getPort() ?? (strtolower($uri->getScheme()) === 'https' ? 443 : 80);
                $options['curl'][\CURLOPT_RESOLVE] = [HostGuard::curlResolveEntry($host, $port, $ip)];

                return $handler($request, $options);
            };
        };
    }

    /**
     * Whether one request may carry the site's HTTP Basic credentials: only
     * over https, and only to the site's own host — never the initial
     * plain-http request, never a redirect hop to another host.
     */
    public static function shouldSendCredentials(string $url, string $siteHost): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || $siteHost === '') {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === strtolower($siteHost);
    }

    /**
     * exposureCheck() for a site that requires auth we lack: every field null
     * ("not checked"), never false ("checked, clean").
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

    /**
     * Status, content type and the first bytes of a possibly huge file.
     * Range keeps the transfer small on servers that honour it;
     * CURLOPT_MAXFILESIZE aborts one that announces an oversized body.
     *
     * @return array{status: int, content_type: string, head: string}|null null when it could not be read
     */
    private function fetchHead(Client $client, string $url): ?array
    {
        try {
            $response = $client->get($url, [
                'headers' => ['Range' => 'bytes=0-' . (self::SENSITIVE_HEAD_BYTES - 1)],
                'curl'    => [\CURLOPT_MAXFILESIZE => self::SENSITIVE_MAX_BYTES],
            ]);
        } catch (GuzzleException) {
            return null;
        }

        return [
            'status'       => $response->getStatusCode(),
            'content_type' => strtolower($response->getHeaderLine('Content-Type')),
            'head'         => substr((string) $response->getBody(), 0, self::SENSITIVE_HEAD_BYTES),
        ];
    }

    /**
     * Pure: exposed only when the real file was served — a 200/206 whose
     * content matches the file's signature, never an HTML page.
     */
    public static function isSensitiveFileExposed(string $path, int $status, string $contentType, string $head): bool
    {
        $signature = self::SENSITIVE_PATHS[$path] ?? null;
        if ($signature === null || !in_array($status, [200, 206], true)
            || str_contains($contentType, 'text/html') || self::looksLikeHtml($head)) {
            return false;
        }

        return preg_match($signature, $head) === 1;
    }

    private static function looksLikeHtml(string $body): bool
    {
        return (bool) preg_match('/^\s*(?:<!doctype html|<html\b|<head\b|<body\b)/i', $body);
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
     * HTTP TRACE: a 200 is itself the exposure (reflected XST); hardened
     * servers answer 405/501/403.
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
            (($data['compression']['gzip'] ?? null) === false && ($data['compression']['brotli'] ?? null) === false)
            || $assetUncompressed
            || ($data['redirects']['forces_https'] ?? true) === false
            || (($data['security_headers']['x-content-type-options'] ?? null) === null)
            || (($data['soft_404']['is_soft_404'] ?? false) === true)
            || (($data['redirects']['loop_detected'] ?? false) === true)
            || $exposed;

        return $warn ? ProbeResult::STATUS_WARN : ProbeResult::STATUS_OK;
    }

    /** Pure: a Location/href resolved against the URL it came from, port kept. */
    public static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts     = parse_url($base);
        $scheme    = $parts['scheme'] ?? 'http';
        $authority = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $authority . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir  = str_ends_with($path, '/') ? $path : rtrim(dirname($path), '/') . '/';

        return $scheme . '://' . $authority . $dir . $location;
    }
}
