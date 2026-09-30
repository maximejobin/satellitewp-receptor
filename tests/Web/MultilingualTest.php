<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Web\Multilingual;

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
            'current_language' => '',
            'active_languages' => ['fr', 'en'],
        ]]]);

        self::assertSame($key, $result['plugin'] ?? null);
        self::assertSame($label, $result['label'] ?? null);
        self::assertSame('fr', $result['default_language'] ?? null);
        self::assertSame(['fr', 'en'], $result['active_languages'] ?? null);
    }

    public function testNoMultilingualConnectorIsNull(): void
    {
        self::assertNull(Multilingual::fromPayload([]));
        self::assertNull(Multilingual::fromPayload(['connectors' => ['woocommerce' => ['version' => '9']]]));
    }
}
