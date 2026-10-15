<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SatelliteWP\Manager\Http\PayloadValidator;
use SatelliteWP\Manager\Tests\TestCase;

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

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badRegistrations(): array
    {
        return [
            'open as a string'   => [['open' => '1']],
            'a non-string cap'   => [['default_role_caps' => [42]]],
            'too many caps'      => [['default_role_caps' => array_fill(0, 51, 'edit_posts')]],
            'an over-long name'  => [['default_role_name' => str_repeat('x', 5000)]],
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('badRegistrations')]
    public function testAMalformedRegistrationSectionIsRefused(array $patch): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        $payload['registration'] = $patch + $payload['registration'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('registration');
        $this->validator->validate((string) json_encode($payload), 'extraction', self::SITE);
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

    public function testPluginReportedExtrasAreAccepted(): void
    {
        $payload                         = $this->fixtureArray('extraction-valid.json');
        $payload['mail']['test']         = ['recipient' => 'admin@example.com', 'sent' => false, 'error' => 'Could not instantiate mail function.', 'sent_at_gmt' => '2026-01-01 12:00:00'];
        $payload['plugin_errors']        = [['time_gmt' => '2026-01-01 12:00:00', 'context' => 'collector:php', 'message' => 'RuntimeException: denied', 'where' => 'satellitewp/src/x.php:12']];
        $payload['connectors']['polylang'] = [
            'provider' => 'polylang', 'label' => 'Polylang Pro', 'version' => '3.6', 'default_language' => 'fr',
            'active_languages' => ['fr', 'en'], 'locales' => ['fr' => 'fr_CA', 'en' => 'en_CA'],
            'language_urls' => ['fr' => 'https://example.com/', 'en' => 'https://example.com/en/'],
            'url_mode' => 'directory', 'browser_redirect' => null,
            'details' => ['hide_default_in_url' => true, 'synchronized' => ['taxonomies'], 'addons' => ['woocommerce' => '2.0'], 'empty' => []],
            'error' => '',
        ];

        $result = $this->validator->validate((string) json_encode($payload), 'extraction', self::SITE);
        $this->assertSame('fpm-fcgi', $result['php']['sapi']);
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
    public static function badPluginExtras(): array
    {
        return [
            'mail test sent as a string'   => [static fn (array $p) => ['mail' => ['test' => ['sent' => 'yes']] + $p['mail']] + $p, 'mail.test'],
            'mail test as a list'          => [static fn (array $p) => ['mail' => ['test' => ['x']] + $p['mail']] + $p, 'mail.test'],
            'plugin errors as an object'   => [static fn (array $p) => ['plugin_errors' => ['a' => ['message' => 'm']]] + $p, 'plugin_errors'],
            'plugin error message too long' => [static fn (array $p) => ['plugin_errors' => [['message' => str_repeat('x', 1_025)]]] + $p, 'plugin_errors'],
            'too many plugin errors'       => [static fn (array $p) => ['plugin_errors' => array_fill(0, 101, ['message' => 'm'])] + $p, 'plugin_errors'],
            'sapi not a string'            => [static fn (array $p) => ['php' => ['sapi' => 1] + $p['php']] + $p, 'php.sapi'],
            'nested connector details'     => [static fn (array $p) => ['connectors' => ['wpml' => ['details' => ['addons' => ['a' => ['b' => '1']]]]]] + $p, 'details'],
            'connector details as a list'  => [static fn (array $p) => ['connectors' => ['wpml' => ['details' => ['x']]]] + $p, 'details'],
            'connector error not a string' => [static fn (array $p) => ['connectors' => ['wpml' => ['error' => ['x']]]] + $p, 'error'],
        ];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $patch */
    #[DataProvider('badPluginExtras')]
    public function testMalformedPluginExtrasAreRefused(callable $patch, string $where): void
    {
        $payload = $patch($this->fixtureArray('extraction-valid.json'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($where);
        $this->validator->validate((string) json_encode($payload), 'extraction', self::SITE);
    }

    public function testPartialExtractionIsAccepted(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        unset($payload['plugins']);
        $payload['collector_errors'] = [['source' => 'collector:plugins', 'message' => 'RuntimeException: host forbids this']];

        $result = $this->validator->validate((string) json_encode($payload), 'extraction', self::SITE);
        $this->assertSame(self::SITE, $result['site_id']);
    }

    public function testCollectorErrorsMustBeAListOfSourceAndMessage(): void
    {
        foreach ([
            ['plugins' => 'host forbids this'],
            [['source' => 'collector:plugins']],
            [['source' => ['x'], 'message' => 'm']],
            [['source' => 'collector:plugins', 'message' => str_repeat('x', 1_025)]],
            array_fill(0, PayloadValidator::MAX_COLLECTOR_ERRORS + 1, ['source' => 's', 'message' => 'm']),
        ] as $i => $errors) {
            $payload                     = $this->fixtureArray('extraction-valid.json');
            $payload['collector_errors'] = $errors;
            try {
                $this->validator->validate((string) json_encode($payload), 'extraction', self::SITE);
                $this->fail("case {$i} should be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('collector_errors', $e->getMessage(), "case {$i}");
            }
        }
    }

    public function testEmptyCollectorErrorsTolerated(): void
    {
        $payload                     = $this->fixtureArray('extraction-valid.json');
        $payload['collector_errors'] = [];

        $result = $this->validator->validate((string) json_encode($payload), 'extraction', self::SITE);
        $this->assertSame(self::SITE, $result['site_id']);
    }

    public function testFixtureCarriesNoCollectorErrors(): void
    {
        $this->assertArrayNotHasKey('collector_errors', $this->fixtureArray('extraction-valid.json'));
    }

    public function testUnknownKeysAreTolerated(): void
    {
        $body = (string) json_encode([
            'schema_version' => '1.0',
            'site_id'        => self::SITE,
            'site_url'       => 'https://example.com',
            'home_url'       => 'https://example.com',
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
