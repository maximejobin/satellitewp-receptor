<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

final class HelpersFormatObservationTextTest extends TestCase
{
    public function testBoldItalicAndLinkAllResolve(): void
    {
        $this->assertSame('<strong>fort</strong>', \format_observation_text('**fort**'));
        $this->assertSame('<em>faible</em>', \format_observation_text('_faible_'));
        $this->assertSame(
            '<a href="https://example.test" target="_blank" rel="noopener">ici</a>',
            \format_observation_text('[ici](https://example.test)')
        );
    }

    public function testCombinesAllThreeInOneSentence(): void
    {
        $html = \format_observation_text('Activez **2FA** dès que possible, voir [la doc](https://example.test/2fa) _rapidement_.');

        $this->assertSame(
            'Activez <strong>2FA</strong> dès que possible, voir <a href="https://example.test/2fa" target="_blank" rel="noopener">la doc</a> <em>rapidement</em>.',
            $html
        );
    }

    public function testHtmlIsEscapedBeforeMarkupIsApplied(): void
    {
        $html = \format_observation_text('<script>alert(1)</script> **<b>x</b>**');

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt; <strong>&lt;b&gt;x&lt;/b&gt;</strong>', $html);
    }

    public function testPlainTextWithNoMarkupIsJustEscaped(): void
    {
        $this->assertSame('Rien de spécial ici.', \format_observation_text('Rien de spécial ici.'));
    }

    public function testUnderscoresInsideALinkUrlOrASnakeCaseNameAreNotItalic(): void
    {
        $this->assertSame(
            '<a href="https://x.test/wp_super_cache/" target="_blank" rel="noopener">doc</a> et wp_options_x, café_x_',
            \format_observation_text('[doc](https://x.test/wp_super_cache/) et wp_options_x, café_x_')
        );
        $this->assertSame('(<em>entre parenthèses</em>)', \format_observation_text('(_entre parenthèses_)'));
    }

    public function testNonHttpsLinkIsNotResolvedAsALink(): void
    {
        // Only http(s):// is accepted — same as the Apps Script parser's own pattern.
        $html = \format_observation_text('[texte](javascript:alert(1))');

        $this->assertStringNotContainsString('<a ', $html);
    }
}
