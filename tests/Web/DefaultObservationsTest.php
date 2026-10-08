<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Web;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Rules\Pastille;
use SatelliteWP\Manager\Rules\Translator;
use SatelliteWP\Manager\Web\DefaultObservations;

final class DefaultObservationsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function contract(): array
    {
        return require dirname(__DIR__, 2) . '/config/reports/bilan-de-sante.php';
    }

    private function translator(string $locale): Translator
    {
        return new Translator($locale, dirname(__DIR__, 2) . '/config/lang', $locale);
    }

    public function testAnExtractionWithoutSavedObservationsStartsWithTheDomainChecks(): void
    {
        $items = DefaultObservations::items(null, $this->contract());

        $this->assertSame(['domain_renewal', 'domain_ownership'], array_column($items, 'template'));
        foreach ($items as $item) {
            $this->assertSame('domain_observations', $item['section']);
            $this->assertSame(Pastille::Purple->value, $item['color']);
            $this->assertTrue($item['include']);
        }
    }

    public function testSavedObservationsReplaceTheDefaultsEvenWhenEmpty(): void
    {
        $this->assertSame([], DefaultObservations::items(['items' => []], $this->contract()), 'removed defaults stay removed');
        $this->assertSame([['id' => 'a']], DefaultObservations::items(['items' => [['id' => 'a']]], $this->contract()));
    }

    public function testDefaultsRenderInTheReportsLanguageAndEveryKeyHasTextInBoth(): void
    {
        $items = DefaultObservations::items(null, $this->contract());

        foreach (['fr', 'en'] as $locale) {
            $t = $this->translator($locale);
            foreach (DefaultObservations::resolve($items, $t) as $i => $resolved) {
                $key = $items[$i]['template'];
                $this->assertNotSame("observation_{$key}_title", $resolved['title'], "{$locale}: {$key} title");
                $this->assertNotSame("observation_{$key}_description", $resolved['description'], "{$locale}: {$key} description");
            }
        }

        $fr = DefaultObservations::resolve($items, $this->translator('fr'))[0];
        $en = DefaultObservations::resolve($items, $this->translator('en'))[0];
        $this->assertNotSame($fr['title'], $en['title']);
    }

    public function testAnEditedLineKeepsItsOwnText(): void
    {
        $edited = [['id' => 'x', 'title' => 'Mine', 'description' => 'Text', 'color' => 'blue']];

        $this->assertSame($edited, DefaultObservations::resolve($edited, $this->translator('en')));
    }
}
