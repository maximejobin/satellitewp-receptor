<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Support;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Support\HostGuard;

/**
 * The SSRF guard (2026-08-31): a probe must refuse to connect to a private,
 * loopback, link-local or otherwise reserved address, no matter what a
 * (possibly compromised) site's home_url claims. Only the IP-literal paths
 * are exercised here — no network, per project convention; the hostname
 * branch's dns_get_record() call is exactly the same filter_var() check
 * applied to whatever it resolves to.
 */
final class HostGuardTest extends TestCase
{
    public function testEmptyHostIsNeverRoutable(): void
    {
        $this->assertFalse(HostGuard::isPubliclyRoutable(''));
    }

    public function testPublicIpv4LiteralIsRoutable(): void
    {
        $this->assertTrue(HostGuard::isPubliclyRoutable('93.184.216.34')); // example.com's old IP
    }

    public function testPublicIpv6LiteralIsRoutable(): void
    {
        $this->assertTrue(HostGuard::isPubliclyRoutable('2001:4860:4860::8888'));
    }

    /** @return list<array{0: string}> */
    public static function privateAndReservedIps(): array
    {
        return [
            ['127.0.0.1'],       // loopback
            ['10.0.0.5'],        // RFC1918
            ['172.16.0.5'],      // RFC1918
            ['192.168.1.1'],     // RFC1918
            ['169.254.169.254'], // link-local / cloud metadata endpoint
            ['100.64.0.1'],      // CGNAT 100.64.0.0/10
            ['100.100.100.200'], // CGNAT — Alibaba Cloud metadata endpoint
            ['0.0.0.0'],
            ['::1'],             // IPv6 loopback
            ['fc00::1'],         // IPv6 unique local
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privateAndReservedIps')]
    public function testPrivateAndReservedIpLiteralsAreRefused(string $ip): void
    {
        $this->assertFalse(HostGuard::isPubliclyRoutable($ip));
    }

    public function testIsSafeUrlChecksTheUrlsHost(): void
    {
        $this->assertFalse(HostGuard::isSafeUrl('http://127.0.0.1/admin'));
        $this->assertFalse(HostGuard::isSafeUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertTrue(HostGuard::isSafeUrl('http://93.184.216.34/'));
    }

    public function testIsSafeUrlIsFalseForAMalformedUrl(): void
    {
        $this->assertFalse(HostGuard::isSafeUrl('not a url'));
        $this->assertFalse(HostGuard::isSafeUrl(''));
    }

    public function testSelectPublicIpRefusesTheWholeHostIfAnyAddressIsInternal(): void
    {
        $this->assertNull(HostGuard::selectPublicIp([]));
        $this->assertNull(HostGuard::selectPublicIp(['93.184.216.34', '10.0.0.5']));
        $this->assertNull(HostGuard::selectPublicIp(['2606:2800:220:1:248:1893:25c8:1946', '::1']));
        $this->assertNull(HostGuard::selectPublicIp(['']));
    }

    public function testSelectPublicIpPrefersIpv4(): void
    {
        $this->assertSame('93.184.216.34', HostGuard::selectPublicIp(['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']));
        $this->assertSame('2606:2800:220:1:248:1893:25c8:1946', HostGuard::selectPublicIp(['2606:2800:220:1:248:1893:25c8:1946']));
    }

    public function testPublicIpForAnIpLiteralNeedsNoLookup(): void
    {
        $this->assertSame('93.184.216.34', HostGuard::publicIpFor('93.184.216.34'));
        $this->assertNull(HostGuard::publicIpFor('127.0.0.1'));
        $this->assertNull(HostGuard::publicIpFor('[::1]'));
        $this->assertNull(HostGuard::publicIpFor(''));
    }

    public function testCurlResolveEntryBracketsIpv6(): void
    {
        $this->assertSame('example.com:443:93.184.216.34', HostGuard::curlResolveEntry('example.com', 443, '93.184.216.34'));
        $this->assertSame('example.com:80:[2606:2800:220:1::1]', HostGuard::curlResolveEntry('example.com', 80, '2606:2800:220:1::1'));
    }
}
