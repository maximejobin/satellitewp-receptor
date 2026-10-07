<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Rules;

use SatelliteWP\Manager\Rules\Translator;
use SatelliteWP\Manager\Tests\TestCase;

/**
 * title() picks the pass/fail-specific headline (title_success/
 * title_failure) when a status is given and one exists, and falls back to
 * the plain neutral 'title' otherwise — catalogue listings (no finding),
 * an unknown/na verdict (nothing to assert either way), or a rule that
 * simply has no title_success/title_failure of its own.
 */
final class TranslatorTitleTest extends TestCase
{
    private function translator(): Translator
    {
        mkdir($this->tmpDir . '/lang');
        file_put_contents($this->tmpDir . '/lang/fr.php', <<<'PHP'
        <?php
        return ['rules' => [
            'X1' => ['title' => 'Neutre', 'title_success' => 'Tout va bien', 'title_failure' => 'Ça a échoué', 'title_failure_autre' => 'Échec particulier', 'fail' => 'f', 'pass' => 'p'],
            'X2' => ['title' => 'Titre seul, pas de variantes', 'fail' => 'f'],
        ]];
        PHP);

        return new Translator('fr', $this->tmpDir . '/lang');
    }

    public function testPassStatusUsesTitleSuccess(): void
    {
        $this->assertSame('Tout va bien', $this->translator()->title('X1', 'pass'));
    }

    public function testFailStatusUsesTitleFailure(): void
    {
        $this->assertSame('Ça a échoué', $this->translator()->title('X1', 'fail'));
    }

    public function testUnknownAndNaStatusesFallBackToTheNeutralTitle(): void
    {
        $t = $this->translator();
        $this->assertSame('Neutre', $t->title('X1', 'unknown'));
        $this->assertSame('Neutre', $t->title('X1', 'na'));
    }

    public function testNoStatusGivenFallsBackToTheNeutralTitle(): void
    {
        // rules:list / rules:doc: a catalogue listing, no finding to verdict.
        $this->assertSame('Neutre', $this->translator()->title('X1'));
    }

    public function testARuleWithNoSuccessOrFailureVariantFallsBackToItsPlainTitle(): void
    {
        $t = $this->translator();
        $this->assertSame('Titre seul, pas de variantes', $t->title('X2', 'pass'));
        $this->assertSame('Titre seul, pas de variantes', $t->title('X2', 'fail'));
    }

    public function testAVariantWithItsOwnTitleUsesItAndOthersFallBack(): void
    {
        $t = $this->translator();
        $this->assertSame('Échec particulier', $t->title('X1', 'fail', 'autre'));
        $this->assertSame('Ça a échoué', $t->title('X1', 'fail', 'inconnue'));
        $this->assertSame('Tout va bien', $t->title('X1', 'pass', 'autre'));
    }

    public function testUnknownRuleIdFallsBackToTheIdItself(): void
    {
        $this->assertSame('Z9', $this->translator()->title('Z9', 'fail'));
    }
}
