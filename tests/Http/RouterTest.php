<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Http\Router;

/**
 * Covers the security-relevant routing decisions of the read-only web surface:
 * identifier validation and the path-traversal-safe raw-file allowlist.
 */
final class RouterTest extends TestCase
{
    private const string UUID = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';
    private const string EID  = '20260723T125131Z';

    public function testRootRoutesToSitesList(): void
    {
        $this->assertSame('sites', Router::matchRoute('/')['route']);
        $this->assertSame('sites', Router::matchRoute('')['route']);
    }

    public function testSiteRoute(): void
    {
        $match = Router::matchRoute('/site/' . self::UUID);

        $this->assertSame('site', $match['route']);
        $this->assertSame(self::UUID, $match['params']['site_id']);
    }

    public function testExtractionRoute(): void
    {
        $match = Router::matchRoute('/site/' . self::UUID . '/extraction/' . self::EID);

        $this->assertSame('extraction', $match['route']);
        $this->assertSame(self::UUID, $match['params']['site_id']);
        $this->assertSame(self::EID, $match['params']['extraction_id']);
    }

    public function testRawRoute(): void
    {
        $match = Router::matchRoute('/site/' . self::UUID . '/extraction/' . self::EID . '/raw/tls');

        $this->assertSame('raw', $match['route']);
        $this->assertSame('tls', $match['params']['file']);
    }

    public function testInvalidSiteIdIsNotFound(): void
    {
        $this->assertSame('not_found', Router::matchRoute('/site/not-a-uuid')['route']);
        // A path-traversal attempt in the site position never matches.
        $this->assertSame('not_found', Router::matchRoute('/site/..%2f..%2fetc')['route']);
    }

    public function testInvalidExtractionIdIsNotFound(): void
    {
        $this->assertSame('not_found', Router::matchRoute('/site/' . self::UUID . '/extraction/nope')['route']);
        $this->assertSame('not_found', Router::matchRoute('/site/' . self::UUID . '/extraction/../../etc')['route']);
    }

    public function testUnknownDepthIsNotFound(): void
    {
        $this->assertSame('not_found', Router::matchRoute('/site/' . self::UUID . '/bogus')['route']);
        $this->assertSame('not_found', Router::matchRoute('/a/b/c/d/e/f/g')['route']);
    }

    public function testExtractionIdAcceptsCollisionSuffix(): void
    {
        $this->assertTrue(Router::isExtractionId('20260723T125131Z'));
        $this->assertTrue(Router::isExtractionId('20260723T125131Z-2'));
        $this->assertFalse(Router::isExtractionId('2026-07-23'));
        $this->assertFalse(Router::isExtractionId('../../payload'));
    }

    /** @return list<array{0: string, 1: string|null}> */
    public static function rawFileCases(): array
    {
        return [
            ['payload', 'payload.json'],
            ['meta', 'meta.json'],
            ['findings', 'findings.json'],
            ['summary', null], // no longer a stored file
            ['tls', 'probes/tls.json'],
            ['http', 'probes/http.json'],
            ['pagespeed', 'probes/pagespeed.json'],
            ['payload.json', 'payload.json'], // extension is stripped and re-added
            // Not allowlisted / traversal attempts -> null (404).
            ['keys', null],
            ['../keys', null],
            ['../../config/config.local', null],
            ['payload/../../keys', null],
            ['', null],
        ];
    }

    #[DataProvider('rawFileCases')]
    public function testResolveRawFileAllowlistAndTraversal(string $name, ?string $expected): void
    {
        $this->assertSame($expected, Router::resolveRawFile($name));
    }
}
