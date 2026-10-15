<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Web\Multilingual;

final class MultilingualTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function plugins(): array
    {
        return [
            'WPML'           => ['wpml', 'WPML'],
            'Polylang'       => ['polylang', 'Polylang'],
            'TranslatePress' => ['translatepress', 'TranslatePress'],
        ];
    }

    #[DataProvider('plugins')]
    public function testEachConnectorIsNormalized(string $key, string $label): void
    {
        $result = Multilingual::fromPayload(['connectors' => [$key => [
            'version'          => '1.2',
            'default_language' => 'fr',
            'active_languages' => ['fr', 'en'],
            'locales'          => ['fr' => 'fr_CA', 'en' => 'en_CA'],
            'language_urls'    => ['fr' => 'https://example.com/', 'en' => 'https://example.com/en/'],
            'url_mode'         => 'directory',
            'browser_redirect' => false,
            'details'          => ['addons' => []],
        ]]]);

        self::assertSame($key, $result['plugin'] ?? null);
        self::assertSame($label, $result['label'] ?? null);
        self::assertSame('fr', $result['default_language'] ?? null);
        self::assertSame(['fr', 'en'], $result['active_languages'] ?? null);
        self::assertSame(['fr' => 'fr_CA', 'en' => 'en_CA'], $result['locales'] ?? null);
        self::assertSame('https://example.com/en/', $result['language_urls']['en'] ?? null);
        self::assertSame('directory', $result['url_mode'] ?? null);
        self::assertFalse($result['browser_redirect'] ?? null);
        self::assertSame(['addons' => []], $result['details'] ?? null);
        self::assertSame('', $result['error'] ?? null);
    }

    public function testTheConnectorLabelNamesTheEdition(): void
    {
        $result = Multilingual::fromPayload(['connectors' => ['polylang' => ['label' => 'Polylang Pro']]]);

        self::assertSame('Polylang Pro', $result['label'] ?? null);
    }

    public function testAConnectorThatFailedKeepsItsReasonAndAnEmptyShape(): void
    {
        $result = Multilingual::fromPayload(['connectors' => ['wpml' => ['error' => 'internal API changed', 'browser_redirect' => null]]]);

        self::assertSame('internal API changed', $result['error'] ?? null);
        self::assertSame([], $result['active_languages'] ?? null);
        self::assertIsArray($result);
        self::assertNull($result['browser_redirect']);
    }

    public function testEveryActiveMultilingualPluginIsListed(): void
    {
        $all = Multilingual::all(['connectors' => ['translatepress' => ['version' => '3'], 'wpml' => ['version' => '4']]]);

        self::assertSame(['wpml', 'translatepress'], array_column($all, 'plugin'));
        self::assertSame('wpml', Multilingual::fromPayload(['connectors' => ['translatepress' => [], 'wpml' => []]])['plugin'] ?? null);
    }

    public function testNoMultilingualConnectorIsNull(): void
    {
        self::assertNull(Multilingual::fromPayload([]));
        self::assertNull(Multilingual::fromPayload(['connectors' => ['woocommerce' => ['version' => '9']]]));
    }
}
