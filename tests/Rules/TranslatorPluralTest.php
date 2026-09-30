<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Rules;

use PHPUnit\Framework\Attributes\DataProvider;
use SatelliteWP\Xtractor\Rules\Translator;
use SatelliteWP\Xtractor\Tests\TestCase;

/** The {name|singular|plural} placeholder: French treats 0 and 1 as singular, English only 1. */
final class TranslatorPluralTest extends TestCase
{
    private function translator(string $locale): Translator
    {
        @mkdir($this->tmpDir . '/lang');
        file_put_contents($this->tmpDir . "/lang/{$locale}.php", <<<'PHP'
        <?php
        return ['rules' => [
            'X1' => ['title' => 't', 'fail' => '{observed} {observed|extension a|extensions ont} une mise à jour'],
            'X2' => ['title' => 't', 'fail' => '{n} {n|item|items}, {observed} kept plain'],
        ]];
        PHP);

        return new Translator($locale, $this->tmpDir . '/lang');
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function counts(): iterable
    {
        yield 'fr 0 is singular'  => ['fr', 0, '0 extension a une mise à jour'];
        yield 'fr 1 is singular'  => ['fr', 1, '1 extension a une mise à jour'];
        yield 'fr 1.5 singular'   => ['fr', 1.5, '1,5 extension a une mise à jour'];
        yield 'fr 2 is plural'    => ['fr', 2, '2 extensions ont une mise à jour'];
        yield 'en 0 is plural'    => ['en', 0, '0 extensions ont une mise à jour'];
        yield 'en 1 is singular'  => ['en', 1, '1 extension a une mise à jour'];
        yield 'en 3 is plural'    => ['en', 3, '3 extensions ont une mise à jour'];
        yield 'list counts'       => ['fr', ['a', 'b'], '2 extensions ont une mise à jour'];
    }

    #[DataProvider('counts')]
    public function testAgreementFollowsTheLocaleRule(string $locale, mixed $observed, string $expected): void
    {
        $this->assertSame($expected, $this->translator($locale)->message(['id' => 'X1', 'status' => 'fail', 'observed' => $observed]));
    }

    public function testANonNumericOrMissingValueReadsAsPlural(): void
    {
        $t = $this->translator('fr');

        $this->assertSame('? extensions ont une mise à jour', $t->message(['id' => 'X1', 'status' => 'fail']));
        $this->assertSame('abc extensions ont une mise à jour', $t->message(['id' => 'X1', 'status' => 'fail', 'observed' => 'abc']));
    }

    public function testDataValuesDriveAgreementAndPlainPlaceholdersAreUnchanged(): void
    {
        $out = $this->translator('en')->message(['id' => 'X2', 'status' => 'fail', 'observed' => 5, 'data' => ['n' => 1]]);

        $this->assertSame('1 item, 5 kept plain', $out);
    }
}
