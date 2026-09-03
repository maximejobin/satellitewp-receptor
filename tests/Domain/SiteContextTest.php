<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Domain\SiteContext;

final class SiteContextTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function hosts(): array
    {
        return [
            'bare domain unchanged'                    => ['example.com', 'example.com'],
            'www stripped like any other subdomain'    => ['www.example.com', 'example.com'],
            // The real bug: a hosting panel's multi-level vhost alias (e.g.
            // RunCloud's "<app>.<n>.<hosting-domain>") was queried against
            // WHOIS/RDAP verbatim, which always comes back empty — that host
            // was never registered as its own domain, only the base one was.
            'multi-level subdomain reduced to eTLD+1'  => ['latest.1.webint.ca', 'webint.ca'],
            'deeply nested subdomain'                  => ['a.b.c.d.example.com', 'example.com'],
            // Two-label public suffixes: naive "last two labels" would
            // wrongly return the suffix itself ("co.uk").
            'two-label suffix (co.uk)'                 => ['www.example.co.uk', 'example.co.uk'],
            'two-label suffix, deeper subdomain'       => ['shop.blog.example.co.uk', 'example.co.uk'],
            'two-label suffix (com.au)'                => ['example.com.au', 'example.com.au'],
            'empty host stays empty'                   => ['', ''],
            'single-label host unchanged'              => ['localhost', 'localhost'],
        ];
    }

    #[DataProvider('hosts')]
    public function testRegistrableDomain(string $host, string $expected): void
    {
        $this->assertSame($expected, SiteContext::registrableDomain($host));
    }

    public function testFromExtractionPayloadUsesRegistrableDomainNotHost(): void
    {
        $context = SiteContext::fromExtractionPayload('site-1', [
            'home_url' => 'https://latest.1.webint.ca',
            'site_url' => 'https://latest.1.webint.ca',
        ]);

        $this->assertSame('latest.1.webint.ca', $context->host, 'host stays the full hostname (DNS A/AAAA need it)');
        $this->assertSame('webint.ca', $context->registrableDomain, 'RDAP/WHOIS/NS/MX need the registered domain');
    }
}
