<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use SatelliteWP\Xtractor\Http\PayloadValidator;
use SatelliteWP\Xtractor\Http\Receptor;
use SatelliteWP\Xtractor\Http\SignatureVerifier;
use SatelliteWP\Xtractor\Storage\DataStore;
use SatelliteWP\Xtractor\Storage\Index;
use SatelliteWP\Xtractor\Storage\KeyStore;
use SatelliteWP\Xtractor\Tests\TestCase;

final class ReceptorTest extends TestCase
{
    private const string SITE_ID = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';
    private const string API_KEY = 'receptor-test-key';

    private DataStore $store;
    private Index $index;
    private Receptor $receptor;
    private KeyStore $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DataStore($this->tmpDir);
        $this->index = new Index($this->tmpDir . '/index.sqlite');

        $this->keys = new KeyStore($this->tmpDir . '/keys.json');
        $this->keys->addKey(self::SITE_ID, self::API_KEY);

        $this->receptor = new Receptor(
            new SignatureVerifier($this->keys, 300, false),
            new PayloadValidator(),
            $this->store,
            $this->index,
            1024 * 1024,
            $this->keys
        );
    }

    /** @return array<string, string|null> */
    private function headers(string $type, string $body, ?string $timestamp = null): array
    {
        $timestamp ??= (string) time();

        return [
            'site'      => self::SITE_ID,
            'type'      => $type,
            'timestamp' => $timestamp,
            'signature' => hash_hmac('sha256', $timestamp . '.' . $body, self::API_KEY),
        ];
    }

    public function testExtractionIsStoredAndIndexed(): void
    {
        $body   = $this->fixture('extraction-valid.json');
        $result = $this->receptor->handle($this->headers('extraction', $body), $body, '192.0.2.10');

        $this->assertSame(200, $result['status']);
        $this->assertSame('received', $result['body']['status']);

        $extractionId = $result['body']['id'];
        $dir          = $this->store->extractionDir(self::SITE_ID, $extractionId);

        // Raw body stored verbatim.
        $this->assertSame($body, file_get_contents($dir . '/payload.json'));

        $meta = $this->store->readMeta(self::SITE_ID, $extractionId);
        $this->assertTrue($meta['signature_valid']);
        $this->assertSame('192.0.2.10', $meta['remote_ip']);

        $site = $this->store->readSiteInfo(self::SITE_ID);
        $this->assertSame('https://www.example.com', $site['site_url']);

        $row = $this->index->getExtraction(self::SITE_ID, $extractionId);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('6.8.1', $row['wp_version']);
        $this->assertSame('8.3.11', $row['php_version']);
    }

    public function testEventsAreAppendedAsJsonl(): void
    {
        $body   = $this->fixture('event-valid.json');
        $result = $this->receptor->handle($this->headers('event', $body), $body);

        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['body']['events']);

        $files = glob($this->tmpDir . '/sites/' . self::SITE_ID . '/events/*.jsonl');
        $this->assertCount(1, $files);

        $lines = array_filter(explode("\n", (string) file_get_contents($files[0])));
        $this->assertCount(1, $lines); // one payload per line
        $decoded = json_decode($lines[0], true);
        $this->assertCount(2, $decoded['events']);
    }

    public function testIntegrityIsStored(): void
    {
        $body   = $this->fixture('integrity-valid.json');
        $result = $this->receptor->handle($this->headers('integrity', $body), $body);

        $this->assertSame(200, $result['status']);

        $files = glob($this->tmpDir . '/sites/' . self::SITE_ID . '/integrity/*.json');
        $this->assertCount(1, $files);
    }

    public function testBadSignatureIsRejectedAndNothingStored(): void
    {
        $body    = $this->fixture('extraction-valid.json');
        $headers = $this->headers('extraction', $body);
        $headers['signature'] = 'tampered';

        $result = $this->receptor->handle($headers, $body);

        $this->assertSame(401, $result['status']);
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/sites/' . self::SITE_ID);
    }

    public function testSiteIdMismatchIsRejected(): void
    {
        $payload            = $this->fixtureArray('extraction-valid.json');
        $payload['site_id'] = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $body               = (string) json_encode($payload);

        $result = $this->receptor->handle($this->headers('extraction', $body), $body);

        $this->assertSame(422, $result['status']);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $body   = $this->fixture('extraction-valid.json');
        $result = $this->receptor->handle($this->headers('bogus', $body), $body);

        $this->assertSame(400, $result['status']);
    }

    public function testMalformedSiteHeaderIsRejected(): void
    {
        $body            = $this->fixture('extraction-valid.json');
        $headers         = $this->headers('extraction', $body);
        $headers['site'] = 'not-a-uuid';

        $result = $this->receptor->handle($headers, $body);

        $this->assertSame(400, $result['status']);
    }

    public function testOversizedBodyIsRejected(): void
    {
        $keys = new KeyStore($this->tmpDir . '/keys.json');

        $receptor = new Receptor(
            new SignatureVerifier($keys, 300, true),
            new PayloadValidator(),
            $this->store,
            $this->index,
            10 // tiny limit
        );

        $body   = $this->fixture('extraction-valid.json');
        $result = $receptor->handle(['site' => self::SITE_ID, 'type' => 'extraction', 'timestamp' => (string) time()], $body);

        $this->assertSame(413, $result['status']);
    }

    public function testFirstExtractionBindsTheSiteToItsAddress(): void
    {
        $body = $this->fixture('extraction-valid.json');

        $this->assertNull($this->keys->getOrigin(self::SITE_ID), 'unbound before the first push');

        $result = $this->receptor->handle($this->headers('extraction', $body), $body);

        $this->assertSame(200, $result['status']);
        $this->assertSame('example.com', $this->keys->getOrigin(self::SITE_ID));
    }

    /**
     * A site restored from a backup carries the original's id and key but answers
     * elsewhere. Accepting it would let a staging copy report over production.
     */
    public function testExtractionFromAnotherAddressIsRefused(): void
    {
        $this->keys->setOrigin(self::SITE_ID, 'example.com');

        $payload             = $this->fixtureArray('extraction-valid.json');
        $payload['home_url'] = 'https://staging.example.com';
        $payload['site_url'] = 'https://staging.example.com';
        $body                = (string) json_encode($payload);

        $result = $this->receptor->handle($this->headers('extraction', $body), $body);

        $this->assertSame(409, $result['status']);
        $this->assertStringContainsString('staging.example.com', $result['body']['message']);
        $this->assertSame([], glob($this->store->siteDir(self::SITE_ID) . '/extractions/*') ?: []);
    }

    /** http -> https and a www redirect are the same site, not a move. */
    public function testSchemeAndWwwDoNotCountAsAMove(): void
    {
        $this->keys->setOrigin(self::SITE_ID, 'example.com');

        $payload             = $this->fixtureArray('extraction-valid.json');
        $payload['home_url'] = 'http://www.example.com/';
        $body                = (string) json_encode($payload);

        $result = $this->receptor->handle($this->headers('extraction', $body), $body);

        $this->assertSame(200, $result['status']);
    }

    public function testRebindingLetsAMovedSiteReportAgain(): void
    {
        $this->keys->setOrigin(self::SITE_ID, 'old-domain.com');

        $body   = $this->fixture('extraction-valid.json');
        $result = $this->receptor->handle($this->headers('extraction', $body), $body);
        $this->assertSame(409, $result['status']);

        $this->keys->setOrigin(self::SITE_ID, 'example.com');

        $result = $this->receptor->handle($this->headers('extraction', $body), $body);
        $this->assertSame(200, $result['status']);
    }
}
