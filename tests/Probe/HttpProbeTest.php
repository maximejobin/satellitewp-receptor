<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Probe\HttpProbe;

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
        $this->assertSame('cloudflare', $data['cdn']);
        $this->assertTrue($data['cookies']['secure']);
        $this->assertTrue($data['cookies']['httponly']);
        $this->assertTrue($data['cookies']['samesite']);
    }

    public function testParseMainResponseMinimal(): void
    {
        $data = HttpProbe::parseMainResponse(200, [], []);

        $this->assertNull($data['http_version']);
        $this->assertFalse($data['gzip']);
        $this->assertNull($data['cdn']);
        $this->assertNull($data['cookies']);
        $this->assertNull($data['security_headers']['content-security-policy']);
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

    /**
     * A site that requires HTTP auth we don't have must read as "not
     * checked" everywhere, never as "checked, clean" — a 401 on every path
     * is not the same fact as nothing being exposed (2026-08-30, user: "ce
     * n'est pas ce que c'est ok... c'est que le site n'est pas public").
     */
    public function testAuthGatedExposureResultLeavesEveryCheckUnknown(): void
    {
        $result = HttpProbe::authGatedExposureResult();

        $this->assertTrue($result['auth_required']);
        foreach (['xmlrpc_enabled', 'rest_user_enumeration', 'author_enumeration', 'directory_listing', 'sensitive_files', 'trace_enabled'] as $key) {
            $this->assertNull($result[$key], "{$key} must be null (not checked), not false (checked, clean)");
        }
        $this->assertSame([], $result['evidence'], 'nothing was actually requested, so there is no evidence to show');
    }
}
