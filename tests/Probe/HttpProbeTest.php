<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Probe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Probe\HttpProbe;

final class HttpProbeTest extends TestCase
{
    public function testParseMainResponse(): void
    {
        $data = HttpProbe::parseMainResponse(
            200,
            [
                'content-encoding'       => 'br',
                'cache-control'          => 'max-age=3600, public',
                'strict-transport-security' => 'max-age=31536000',
                'x-content-type-options' => 'nosniff',
                'server'                 => 'cloudflare',
                'cf-ray'                 => 'abc123',
                'set-cookie'             => 'session=x; Secure; HttpOnly; SameSite=Lax',
            ],
            [
                'http_version'       => CURL_HTTP_VERSION_2_0,
                'starttransfer_time' => 0.245,
            ]
        );

        $this->assertSame(200, $data['status_code']);
        $this->assertSame('2', $data['http_version']);
        $this->assertTrue($data['brotli']);
        $this->assertFalse($data['gzip']);
        $this->assertSame('max-age=3600, public', $data['cache_headers']['cache-control']);
        $this->assertSame('max-age=31536000', $data['security_headers']['strict-transport-security']);
        $this->assertSame('nosniff', $data['security_headers']['x-content-type-options']);
        $this->assertArrayNotHasKey('cdn', $data);
        $this->assertTrue($data['cookies']['secure']);
        $this->assertTrue($data['cookies']['httponly']);
        $this->assertTrue($data['cookies']['samesite']);
        $this->assertSame('cloudflare', $data['headers']['server'], 'the full raw response is kept, not just the curated subsets');
        $this->assertArrayNotHasKey(
            'set-cookie',
            $data['headers'],
            'a real cookie value (a site visitor session/cart id) must never be written to data/ or shown in the UI — cookies above is the safe boolean-only summary of the same fact'
        );
    }

    public function testParseMainResponseMinimal(): void
    {
        $data = HttpProbe::parseMainResponse(200, [], []);

        $this->assertNull($data['http_version']);
        $this->assertFalse($data['gzip']);
        $this->assertNull($data['cookies']);
        $this->assertNull($data['alt_svc']);
        $this->assertNull($data['security_headers']['content-security-policy']);
    }

    public function testParseMainResponseCapturesAltSvc(): void
    {
        $data = HttpProbe::parseMainResponse(200, ['alt-svc' => 'h3=":443"; ma=2592000'], []);

        $this->assertSame('h3=":443"; ma=2592000', $data['alt_svc']);
    }

    #[DataProvider('altSvcProvider')]
    public function testAltSvcAdvertisesHttp3(?string $header, bool $expected): void
    {
        $this->assertSame($expected, HttpProbe::altSvcAdvertisesHttp3($header));
    }

    public static function altSvcProvider(): array
    {
        return [
            'absent'                     => [null, false],
            'empty'                      => ['', false],
            'standard h3'                => ['h3=":443"; ma=2592000', true],
            'h3 not first entry'         => ['h2=":443", h3=":443"; ma=2592000', true],
            'older draft id h3-29'       => ['h3-29=":443"; ma=2592000', true],
            'h2 only, no h3'             => ['h2=":443"; ma=2592000', false],
            'unrelated protocol id'      => ['clear=":80"; ma=2592000', false],
            // "h3" must be its own token, not a substring of something else.
            'lookalike token must not match' => ['h33=":443"', false],
        ];
    }

    public function testExtractFirstAssetPrefersSameOriginStylesheet(): void
    {
        $html = <<<HTML
            <html><head>
            <link rel="preconnect" href="https://fonts.gstatic.com">
            <link rel='stylesheet' href='/wp-content/themes/x/style.css?ver=1.2'>
            <script src="https://cdn.example.net/third-party.js"></script>
            </head></html>
            HTML;

        $this->assertSame(
            'https://www.example.com/wp-content/themes/x/style.css?ver=1.2',
            HttpProbe::extractFirstAsset($html, 'https://www.example.com/')
        );
    }

    public function testExtractFirstAssetFallsBackToScriptAndSkipsThirdParty(): void
    {
        $html = '<script src="https://cdn.other.com/a.js"></script>'
            . '<script src="//www.example.com/app.js"></script>';

        $this->assertSame(
            'https://www.example.com/app.js',
            HttpProbe::extractFirstAsset($html, 'https://www.example.com/page/')
        );
    }

    public function testExtractFirstAssetReturnsNullWhenNoFirstPartyAsset(): void
    {
        $html = '<link rel="stylesheet" href="https://cdn.other.com/x.css">';

        $this->assertNull(HttpProbe::extractFirstAsset($html, 'https://www.example.com/'));
    }

    public function testParseRobotsExtractsSitemapsAndRules(): void
    {
        $body = <<<TXT
            # comment line
            User-agent: *
            Disallow: /wp-admin/
            Allow: /wp-admin/admin-ajax.php

            User-agent: BadBot
            Disallow: /

            Sitemap: https://example.com/sitemap.xml
            Sitemap: https://example.com/news-sitemap.xml
            TXT;

        $parsed = HttpProbe::parseRobots($body);

        $this->assertFalse($parsed['disallow_all'], 'Disallow: / applies to BadBot, not *');
        $this->assertSame(
            ['https://example.com/sitemap.xml', 'https://example.com/news-sitemap.xml'],
            $parsed['sitemaps']
        );
        $this->assertSame(2, $parsed['rule_count']);
    }

    public function testParseRobotsDetectsGlobalBlock(): void
    {
        $parsed = HttpProbe::parseRobots("User-agent: *\nDisallow: /");

        $this->assertTrue($parsed['disallow_all']);
        $this->assertSame([], $parsed['sitemaps']);
    }

    public function testParseRobotsEmpty(): void
    {
        $parsed = HttpProbe::parseRobots('');

        $this->assertFalse($parsed['disallow_all']);
        $this->assertSame([], $parsed['sitemaps']);
        $this->assertSame(0, $parsed['rule_count']);
    }

    // ---------- exposure detection (pure, unit-testable without network) ----------

    public function testXmlrpcEnabledDetectsTheExactWordPressResponse(): void
    {
        $this->assertTrue(HttpProbe::isXmlrpcEnabled(200, 'XML-RPC server accepts POST requests only.'));
    }

    public function testXmlrpcEnabledIsFalseWhenBlockedOrMissing(): void
    {
        $this->assertFalse(HttpProbe::isXmlrpcEnabled(403, ''));
        $this->assertFalse(HttpProbe::isXmlrpcEnabled(404, 'Not Found'));
        // A soft-404 catch-all answers 200 with unrelated content — must not
        // be mistaken for the real xmlrpc.php response.
        $this->assertFalse(HttpProbe::isXmlrpcEnabled(200, '<html>Page not found</html>'));
    }

    public function testRestUserEnumerationDetectsAUserList(): void
    {
        $body = json_encode([['id' => 1, 'name' => 'Jane Admin', 'slug' => 'jane-admin']]);

        $this->assertTrue(HttpProbe::isRestUserEnumerationExposed(200, (string) $body));
    }

    public function testExtractUsernamesReturnsEverySlug(): void
    {
        $body = json_encode([
            ['id' => 1, 'slug' => 'admin'],
            ['id' => 2, 'slug' => 'jane-admin'],
        ]);

        $this->assertSame(['admin', 'jane-admin'], HttpProbe::extractUsernames((string) $body));
    }

    public function testExtractUsernamesOnNonJsonReturnsEmpty(): void
    {
        $this->assertSame([], HttpProbe::extractUsernames('<html>not json</html>'));
    }

    public function testRestUserEnumerationIsFalseWhenDisabledOrEmpty(): void
    {
        $this->assertFalse(HttpProbe::isRestUserEnumerationExposed(401, ''));
        $this->assertFalse(HttpProbe::isRestUserEnumerationExposed(200, '[]'));
        $this->assertFalse(HttpProbe::isRestUserEnumerationExposed(200, '<html>not json</html>'));
    }

    public function testAuthorEnumerationDetectsTheClassicRedirect(): void
    {
        $this->assertTrue(HttpProbe::isAuthorEnumerationExposed(301, 'https://example.com/author/admin/'));
        $this->assertTrue(HttpProbe::isAuthorEnumerationExposed(302, '/author/jane/'));
    }

    public function testAuthorEnumerationIsFalseWithoutAnAuthorRedirect(): void
    {
        $this->assertFalse(HttpProbe::isAuthorEnumerationExposed(200, ''));
        $this->assertFalse(HttpProbe::isAuthorEnumerationExposed(301, 'https://example.com/'));
    }

    public function testDirectoryListingDetectsAnAutoindexPage(): void
    {
        $this->assertTrue(HttpProbe::isDirectoryListing(200, '<html><title>Index of /uploads</title></html>'));
        $this->assertTrue(HttpProbe::isDirectoryListing(200, '<a href="../">Parent Directory</a>'));
    }

    public function testDirectoryListingIsFalseForAnOrdinaryPage(): void
    {
        $this->assertFalse(HttpProbe::isDirectoryListing(403, ''));
        // A soft-404 catch-all also answers 200 — must not read as a listing.
        $this->assertFalse(HttpProbe::isDirectoryListing(200, '<html>Page not found</html>'));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function credentialCases(): array
    {
        return [
            'https, site host'            => ['https://example.com/', 'example.com', true],
            'https, host case differs'    => ['https://Example.COM/path?x=1', 'example.com', true],
            'plain http, site host'       => ['http://example.com/', 'example.com', false],
            'https, other host'           => ['https://evil.test/', 'example.com', false],
            'https, www variant'          => ['https://www.example.com/', 'example.com', false],
            'https, suffix look-alike'    => ['https://example.com.evil.test/', 'example.com', false],
            'malformed url'               => ['not a url', 'example.com', false],
            'empty site host'             => ['https://example.com/', '', false],
        ];
    }

    #[DataProvider('credentialCases')]
    public function testCredentialsOnlyGoOverHttpsToTheSiteHost(string $url, string $siteHost, bool $expected): void
    {
        $this->assertSame($expected, HttpProbe::shouldSendCredentials($url, $siteHost));
    }

    /** A 401 on every path is "not checked", never "checked, clean". */
    public function testAuthGatedExposureResultLeavesEveryCheckUnknown(): void
    {
        $result = HttpProbe::authGatedExposureResult();

        $this->assertTrue($result['auth_required']);
        foreach (['xmlrpc_enabled', 'rest_user_enumeration', 'author_enumeration', 'directory_listing', 'sensitive_files', 'trace_enabled'] as $key) {
            $this->assertNull($result[$key], "{$key} must be null (not checked), not false (checked, clean)");
        }
        $this->assertSame([], $result['evidence'], 'nothing was actually requested, so there is no evidence to show');
    }

    public function testPinToVettedAddressSetsCurlResolveForEachRequest(): void
    {
        $seen = [];
        $mock = static function ($request, array $options) use (&$seen) {
            $seen[] = $options['curl'][\CURLOPT_RESOLVE] ?? null;

            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200));
        };
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(HttpProbe::pinToVettedAddress(static fn (string $h): ?string => $h === 'example.com' ? '93.184.216.34' : null));
        $client = new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => false]);

        $client->get('https://example.com/');
        $client->get('http://example.com:8080/x');

        $this->assertSame([['example.com:443:93.184.216.34'], ['example.com:8080:93.184.216.34']], $seen);
    }

    public function testPinToVettedAddressRefusesAHostWithNoPublicAddress(): void
    {
        $called = false;
        $mock = static function () use (&$called) {
            $called = true;

            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200));
        };
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(HttpProbe::pinToVettedAddress(static fn (string $h): ?string => null));
        $client = new \GuzzleHttp\Client(['handler' => $stack]);

        try {
            $client->get('http://internal.example/');
            $this->fail('expected a ConnectException');
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $this->assertStringContainsString('SSRF guard', $e->getMessage());
        }
        $this->assertFalse($called, 'the request must never reach the handler');
    }

    private function followRedirects(\GuzzleHttp\Client $client, string $url): array
    {
        $probe  = new HttpProbe(5, 10, 'test-agent', null, static fn (string $h): string => '93.184.216.34');
        $method = new \ReflectionMethod(HttpProbe::class, 'followRedirects');
        $errors = [];

        return $method->invokeArgs($probe, [$client, $url, &$errors]);
    }

    public function testFollowRedirectsUsesGetSoAHostThatRejectsHeadStillResolves(): void
    {
        $mock   = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(301, ['Location' => 'https://example.com/']),
            new \GuzzleHttp\Psr7\Response(200),
        ]);
        // allow_redirects false, as in collect(): Guzzle must not follow the 301 itself.
        $client = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($mock), 'http_errors' => false, 'allow_redirects' => false]);

        $result = $this->followRedirects($client, 'http://example.com/');

        $this->assertTrue($result['forces_https']);
        $this->assertSame('https://example.com/', $result['final_url']);
        $this->assertSame(1, $result['hops']);
    }

    public function testFollowRedirectsRecordsABareErrorStatusAsTheFinalNonRedirectingHop(): void
    {
        $mock   = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(500)]);
        $client = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($mock), 'http_errors' => false, 'allow_redirects' => false]);

        $result = $this->followRedirects($client, 'http://example.com/');

        $this->assertFalse($result['forces_https']);
        $this->assertSame(0, $result['hops']);
        $this->assertSame([['url' => 'http://example.com/', 'status' => 500]], $result['chain']);
    }

    public function testPickMainStartUrlPrefersTheHomeUrl(): void
    {
        $this->assertSame(
            'https://example.com/',
            HttpProbe::pickMainStartUrl('https://example.com/', 'https://example.com/wp', 'example.com')
        );
    }

    public function testPickMainStartUrlFallsBackToSiteUrlThenTheHost(): void
    {
        $this->assertSame('https://example.com/wp', HttpProbe::pickMainStartUrl('', 'https://example.com/wp', 'example.com'));
        $this->assertSame('https://example.com/', HttpProbe::pickMainStartUrl('', '', 'example.com'));
    }

    /**
     * collect() against a site whose plain-HTTP vhost is broken but whose
     * home_url is healthy: A10 reads the http:// chain, everything else the
     * homepage.
     */
    public function testCollectMeasuresTheHomepageNotTheHttpVhost(): void
    {
        $handler = static function (\Psr\Http\Message\RequestInterface $request): \GuzzleHttp\Promise\PromiseInterface {
            $uri = (string) $request->getUri();
            $response = match (true) {
                str_starts_with($uri, 'http://')                        => new \GuzzleHttp\Psr7\Response(500),
                $uri === 'https://example.com/' && $request->getMethod() === 'GET'
                    => new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html', 'Set-Cookie' => ['a=1; Secure; HttpOnly; SameSite=Lax', 'b=2']], '<html></html>'),
                default                                                => new \GuzzleHttp\Psr7\Response(404, ['Content-Type' => 'text/html'], '<!doctype html><p>Not found</p>'),
            };

            return \GuzzleHttp\Promise\Create::promiseFor($response);
        };

        $probe = new HttpProbe(5, 10, 'test-agent', $handler, static fn (string $h): string => '93.184.216.34');
        $site  = new \SatelliteWP\Manager\Domain\SiteContext('site-1', 'https://example.com/wp', 'https://example.com/', 'example.com', 'example.com');

        $data = $probe->run($site)->data;

        $this->assertSame(200, $data['status_code']);
        $this->assertSame('https://example.com/', $data['final_url']);
        $this->assertFalse($data['redirects']['forces_https']);
        $this->assertSame(500, $data['redirects']['chain'][0]['status']);
        $this->assertSame(['secure' => false, 'httponly' => false, 'samesite' => false], $data['cookies'], 'the second cookie carries no flag');
        $this->assertSame([], $data['exposure']['sensitive_files'], 'HTML 404 pages are never exposures');
    }

    /** A dead plain-HTTP vhost answers A10 alone: it never turns the whole probe into an error. */
    public function testAFailingHttpChainIsKeptInRedirectsNotAProbeError(): void
    {
        $handler = static function (\Psr\Http\Message\RequestInterface $request): \GuzzleHttp\Promise\PromiseInterface {
            if ($request->getUri()->getScheme() === 'http') {
                return \GuzzleHttp\Promise\Create::rejectionFor(new \GuzzleHttp\Exception\ConnectException('Connection timed out', $request));
            }

            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html'], '<html></html>'));
        };

        $probe  = new HttpProbe(5, 10, 'test-agent', $handler, static fn (string $h): string => '93.184.216.34');
        $result = $probe->run(new \SatelliteWP\Manager\Domain\SiteContext('site-1', 'https://example.com/', 'https://example.com/', 'example.com', 'example.com'));

        $this->assertNotSame('error', $result->status);
        $this->assertSame([], $result->errors);
        $this->assertNull($result->data['redirects']['forces_https']);
        $this->assertStringContainsString('Connection timed out', $result->data['redirects']['error']);
        $this->assertSame(200, $result->data['status_code']);
    }

    public function testAFailedMainRequestIsAProbeError(): void
    {
        $handler = static fn (\Psr\Http\Message\RequestInterface $request): \GuzzleHttp\Promise\PromiseInterface
            => \GuzzleHttp\Promise\Create::rejectionFor(new \GuzzleHttp\Exception\ConnectException('Connection refused', $request));

        $probe = new HttpProbe(5, 10, 'test-agent', $handler, static fn (string $h): string => '93.184.216.34');

        $this->assertSame('error', $probe->run(new \SatelliteWP\Manager\Domain\SiteContext('site-1', 'https://example.com/', 'https://example.com/', 'example.com', 'example.com'))->status);
    }

    public function testSensitiveFilesAreUnknownWhenARequestFailedAndNothingWasFound(): void
    {
        $this->assertNull(HttpProbe::sensitiveFilesVerdict([], ['.env']));
        $this->assertSame(['.env'], HttpProbe::sensitiveFilesVerdict(['.env'], ['backup.sql']));
        $this->assertSame([], HttpProbe::sensitiveFilesVerdict([], []));
    }

    public function testOnlyAServedAssetCountsForTheCacheCheck(): void
    {
        $this->assertTrue(HttpProbe::isServedAssetStatus(200));
        $this->assertTrue(HttpProbe::isServedAssetStatus(304));
        $this->assertFalse(HttpProbe::isServedAssetStatus(404));
        $this->assertFalse(HttpProbe::isServedAssetStatus(301));
    }

    public function testExtractFirstAssetDecodesHtmlEntities(): void
    {
        $html = '<link rel="stylesheet" href="https://example.com/wp-includes/css/a.css?ver=6.8&#038;b=1&amp;c=2">';

        $this->assertSame('https://example.com/wp-includes/css/a.css?ver=6.8&b=1&c=2', HttpProbe::extractFirstAsset($html, 'https://example.com/'));
    }

    public function testCollectRefusesAHostThatDoesNotResolveToAPublicAddress(): void
    {
        $probe  = new HttpProbe(5, 10, 'test-agent', null, static fn (string $h): ?string => null);
        $result = $probe->run(new \SatelliteWP\Manager\Domain\SiteContext('s', 'https://internal.example', 'https://internal.example', 'internal.example', 'internal.example'));

        $this->assertSame('error', $result->status);
        $this->assertStringContainsString('SSRF guard', $result->errors[0]);
    }

    /** @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: bool}> */
    public static function sensitiveFiles(): array
    {
        return [
            'real .env'                   => ['.env', 200, 'text/plain', "APP_ENV=production\nDB_PASSWORD=x\n", true],
            'html page answered for .env' => ['.env', 200, 'text/html; charset=utf-8', '<!DOCTYPE html><html>…', false],
            'html without content type'   => ['.env', 200, '', "  <html><body>Error</body></html>", false],
            'real wp-config backup'       => ['wp-config.php.bak', 200, 'application/octet-stream', "<?php\ndefine( 'DB_NAME', 'wp' );", true],
            'git config'                  => ['.git/config', 206, 'text/plain', "[core]\n\trepositoryformatversion = 0", true],
            'debug log'                   => ['wp-content/debug.log', 200, 'text/plain', "[01-Jan-2026 00:00:00 UTC] PHP Warning:  x", true],
            'sql dump'                    => ['backup.sql', 206, 'application/sql', "-- MySQL dump 10.13\nCREATE TABLE `wp_posts`", true],
            'a 200 with unrelated text'   => ['backup.sql', 200, 'text/plain', 'OK', false],
            'not found'                   => ['.env', 404, 'text/plain', 'APP_ENV=x', false],
            'unknown path'                => ['nope.txt', 200, 'text/plain', 'A=1', false],
        ];
    }

    #[DataProvider('sensitiveFiles')]
    public function testASensitiveFileCountsOnlyWhenItsContentIsServed(string $path, int $status, string $type, string $head, bool $expected): void
    {
        $this->assertSame($expected, HttpProbe::isSensitiveFileExposed($path, $status, $type, $head));
    }

    public function testCookieFlagsHoldOnlyWhenEveryCookieCarriesThem(): void
    {
        $this->assertNull(HttpProbe::cookieFlags([]));
        $this->assertSame(
            ['secure' => true, 'httponly' => true, 'samesite' => true],
            HttpProbe::cookieFlags(['a=1; Path=/; Secure; HttpOnly; SameSite=Strict', 'b=2; secure; httponly; samesite=lax'])
        );
        $this->assertSame(
            ['secure' => false, 'httponly' => true, 'samesite' => false],
            HttpProbe::cookieFlags(['a=1; Secure; HttpOnly; SameSite=None', 'b=2; HttpOnly; SameSite=Lax'])
        );
        $this->assertFalse(HttpProbe::cookieFlags(['secure_token=1; HttpOnly'])['secure'], 'a cookie NAME containing "secure" is not the flag');
    }

    public function testResolveUrlKeepsThePortOnRelativeRedirects(): void
    {
        $this->assertSame('https://example.com:8443/login', HttpProbe::resolveUrl('https://example.com:8443/a/b', '/login'));
        $this->assertSame('https://example.com:8443/a/c', HttpProbe::resolveUrl('https://example.com:8443/a/b', 'c'));
        $this->assertSame('https://cdn.example/x.css', HttpProbe::resolveUrl('https://example.com/', '//cdn.example/x.css'));
        $this->assertSame('https://other.example/', HttpProbe::resolveUrl('https://example.com/', 'https://other.example/'));
        $this->assertSame('https://example.com/c', HttpProbe::resolveUrl('https://example.com/b', 'c'));
    }
}
