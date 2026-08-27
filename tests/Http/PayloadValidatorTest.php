<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SatelliteWP\Xtractor\Http\PayloadValidator;
use SatelliteWP\Xtractor\Tests\TestCase;

final class PayloadValidatorTest extends TestCase
{
    private const string SITE = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';

    private PayloadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PayloadValidator();
    }

    public function testValidExtractionReturnsDecodedPayload(): void
    {
        $payload = $this->validator->validate($this->fixture('extraction-valid.json'), 'extraction', self::SITE);

        $this->assertSame(self::SITE, $payload['site_id']);
        $this->assertSame('6.8.1', $payload['wp_version']);
    }

    public function testValidEventAndIntegrity(): void
    {
        $event = $this->validator->validate($this->fixture('event-valid.json'), 'event', self::SITE);
        $this->assertCount(2, $event['events']);

        $integrity = $this->validator->validate($this->fixture('integrity-valid.json'), 'integrity', self::SITE);
        $this->assertArrayHasKey('integrity', $integrity);
    }

    public function testNonJsonBodyRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON');
        $this->validator->validate('not json', 'extraction', self::SITE);
    }

    public function testMissingSchemaVersionRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema_version');
        $this->validator->validate((string) json_encode(['site_id' => self::SITE]), 'extraction', self::SITE);
    }

    public function testSiteIdMismatchRejected(): void
    {
        $body = (string) json_encode(['schema_version' => '1.0', 'site_id' => 'other']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('site_id');
        $this->validator->validate($body, 'extraction', self::SITE);
    }

    public function testEventWithoutEventsArrayRejected(): void
    {
        $body = (string) json_encode(['schema_version' => '1.0', 'site_id' => self::SITE]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('events');
        $this->validator->validate($body, 'event', self::SITE);
    }

    public function testIntegrityWithoutIntegrityObjectRejected(): void
    {
        $body = (string) json_encode(['schema_version' => '1.0', 'site_id' => self::SITE]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('integrity');
        $this->validator->validate($body, 'integrity', self::SITE);
    }

    public function testUnknownTypeRejected(): void
    {
        $body = (string) json_encode(['schema_version' => '1.0', 'site_id' => self::SITE]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown payload type');
        $this->validator->validate($body, 'bogus', self::SITE);
    }

    public function testUnknownKeysAreTolerated(): void
    {
        $body = (string) json_encode([
            'schema_version' => '1.0',
            'site_id'        => self::SITE,
            'some_future_field' => ['nested' => true],
        ]);

        $payload = $this->validator->validate($body, 'extraction', self::SITE);
        $this->assertTrue($payload['some_future_field']['nested']);
    }

    /** @return list<array{0: string, 1: bool}> */
    public static function uuidCases(): array
    {
        return [
            ['3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c', true],
            ['3F2B1A9C-4D5E-4F6A-8B7C-9D0E1F2A3B4C', true], // case-insensitive
            ['not-a-uuid', false],
            ['3f2b1a9c4d5e4f6a8b7c9d0e1f2a3b4c', false],     // no hyphens
            ['3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4', false],  // too short
            ['../../etc/passwd', false],
            ['', false],
        ];
    }

    #[DataProvider('uuidCases')]
    public function testIsUuid(string $value, bool $expected): void
    {
        $this->assertSame($expected, PayloadValidator::isUuid($value));
    }

    /**
     * Must stay in step with ConfigFile::normalize_url() in the plugin: the two
     * decide, independently, whether a site has moved.
     *
     * @param string $url      Raw address.
     * @param string $expected Normalized form.
     */
    #[DataProvider('origins')]
    public function testOriginNormalization(string $url, string $expected): void
    {
        $this->assertSame($expected, PayloadValidator::normalizeOrigin($url));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function origins(): array
    {
        return [
            'plain'              => ['https://example.com', 'example.com'],
            'trailing slash'     => ['https://example.com/', 'example.com'],
            'http'               => ['http://example.com', 'example.com'],
            'www'                => ['https://www.example.com', 'example.com'],
            'http + www + slash' => ['http://www.example.com/', 'example.com'],
            'uppercase'          => ['HTTPS://WWW.Example.COM/', 'example.com'],
            'padded'             => ['  https://example.com/  ', 'example.com'],
            'subdirectory kept'  => ['https://example.com/blog/', 'example.com/blog'],
            'subdomain kept'     => ['https://staging.example.com', 'staging.example.com'],
            'port kept'          => ['http://example.com:8080', 'example.com:8080'],
            'empty'              => ['', ''],
        ];
    }
}
