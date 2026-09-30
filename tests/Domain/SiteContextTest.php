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
            'multi-level subdomain reduced to eTLD+1'  => ['latest.1.example.ca', 'example.ca'],
            'deeply nested subdomain'                  => ['a.b.c.d.example.com', 'example.com'],
            'two-label suffix (co.uk)'                 => ['www.example.co.uk', 'example.co.uk'],
            'two-label suffix, deeper subdomain'       => ['shop.blog.example.co.uk', 'example.co.uk'],
            'two-label suffix (com.au)'                => ['example.com.au', 'example.com.au'],
            'provincial suffix (qc.ca)'                => ['www.exemple.qc.ca', 'exemple.qc.ca'],
            'provincial suffix (on.ca)'                => ['shop.example.on.ca', 'example.on.ca'],
            'three-label suffix (gouv.qc.ca)'          => ['www.ministere.gouv.qc.ca', 'ministere.gouv.qc.ca'],
            'the suffix alone stays as-is'             => ['qc.ca', 'qc.ca'],
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
            'home_url' => 'https://latest.1.example.ca',
            'site_url' => 'https://latest.1.example.ca',
        ]);

        $this->assertSame('latest.1.example.ca', $context->host, 'host stays the full hostname (DNS A/AAAA need it)');
        $this->assertSame('example.ca', $context->registrableDomain, 'RDAP/WHOIS/NS/MX need the registered domain');
    }

    public function testFromExtractionPayloadCarriesTheGivenLocale(): void
    {
        $withLocale = SiteContext::fromExtractionPayload('site-1', ['site_url' => 'https://example.test'], null, 'en');
        $this->assertSame('en', $withLocale->locale);

        $withoutLocale = SiteContext::fromExtractionPayload('site-1', ['site_url' => 'https://example.test']);
        $this->assertNull($withoutLocale->locale);
    }
}
