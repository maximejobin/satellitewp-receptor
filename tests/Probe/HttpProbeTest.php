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
        $this->assertSame(245, $data['ttfb_ms']);
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
        $this->assertNull($data['ttfb_ms']);
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
}
