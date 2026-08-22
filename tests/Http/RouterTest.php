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

    public function testCatalogRoute(): void
    {
        $this->assertSame('catalog', Router::matchRoute('/catalog')['route']);
        $this->assertSame('not_found', Router::matchRoute('/catalog/extra')['route']);
    }

    public function testSafeReturnRejectsOffsiteRedirects(): void
    {
        // Same-site relative paths pass through.
        $this->assertSame('/catalog?needs=1', Router::safeReturn('/catalog?needs=1'));
        $this->assertSame('/site/x/extraction/y', Router::safeReturn('/site/x/extraction/y'));

        // Anything that could leave the site falls back to /catalog.
        $this->assertSame('/catalog', Router::safeReturn('//evil.com'));
        $this->assertSame('/catalog', Router::safeReturn('/\\evil.com')); // backslash is normalised to '/' by browsers
        $this->assertSame('/catalog', Router::safeReturn('https://evil.com'));
        $this->assertSame('/catalog', Router::safeReturn('javascript:alert(1)'));
        $this->assertSame('/catalog', Router::safeReturn(null));
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
            // A syntactically valid name that is not a stored file resolves to a
            // probes/ path; the caller's is_file() check turns it into a 404.
            // The router no longer keeps a name allowlist — see resolveRawFile().
            ['summary', 'probes/summary.json'],
            ['tls', 'probes/tls.json'],
            ['http', 'probes/http.json'],
            ['pagespeed', 'probes/pagespeed.json'],
            ['payload.json', 'payload.json'], // extension is stripped and re-added
            // keys.json lives outside the extraction directory, so this points at
            // a probes/keys.json that never exists -> 404.
            ['keys', 'probes/keys.json'],
            ['../keys', 'probes/keys.json'],   // basename() strips the traversal
            ['../../config/config.local', null],
            ['payload/../../keys', 'probes/keys.json'],
            ['', null],
        ];
    }

    #[DataProvider('rawFileCases')]
    public function testResolveRawFileNeverEscapesTheExtractionDirectory(string $name, ?string $expected): void
    {
        $this->assertSame($expected, Router::resolveRawFile($name));
    }

    /**
     * Any probe, including ones added later, resolves without touching the
     * router — the old hardcoded allowlist silently 404'd blogvault and
     * wordfence because nobody remembered to extend it.
     */
    public function testEveryProbeResolvesUnderProbes(): void
    {
        foreach (['dns', 'rdap', 'tls', 'http', 'pagespeed', 'blogvault', 'wordfence', 'a-future-probe'] as $probe) {
            $this->assertSame("probes/{$probe}.json", Router::resolveRawFile($probe));
        }

        foreach (['payload', 'meta', 'findings'] as $top) {
            $this->assertSame("{$top}.json", Router::resolveRawFile($top));
        }
    }

    /**
     * The name is attacker-controlled: it must never escape the extraction
     * directory, and must not reach for dotfiles.
     */
    public function testRawNameCannotEscapeTheExtractionDirectory(): void
    {
        foreach (['../../keys', '../keys', '/etc/passwd', '..', '.', '.env', '', 'UPPER', 'has space', 'sub/dir'] as $evil) {
            $resolved = Router::resolveRawFile($evil);
            if ($resolved !== null) {
                $this->assertStringNotContainsString('..', $resolved, "{$evil} must not traverse");
                $this->assertMatchesRegularExpression('#^(probes/)?[a-z0-9][a-z0-9_-]*\\.json$#', $resolved);
            } else {
                $this->assertNull($resolved);
            }
        }

        // keys.json lives outside the extraction dir, so this resolves to a
        // probes/ path that simply does not exist -> 404 at the caller.
        $this->assertSame('probes/keys.json', Router::resolveRawFile('keys'));
    }
}
