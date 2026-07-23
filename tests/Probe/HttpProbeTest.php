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
}
