<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

final class HelpersExternalLinkTest extends TestCase
{
    public function testBuildsALinkWhenPatternAndIdAreBothPresent(): void
    {
        $out = \external_link('https://example.com/clients/{id}', 42, 'View client');

        $this->assertStringContainsString('href="https://example.com/clients/42"', $out);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $out);
        $this->assertStringContainsString('>View client', $out);
        // The external-link icon (2026-09-03) is appended after the label, not baked
        // into a fixed full-string comparison, so this survives the icon changing shape.
        $this->assertStringContainsString('<span class="icon">', $out);
    }

    public function testPlainTextWhenPatternIsNull(): void
    {
        $this->assertSame('4242', \external_link(null, 4242, '4242'));
    }

    public function testPlainTextWhenIdIsNullOrEmpty(): void
    {
        $this->assertSame('—', \external_link('https://example.com/{id}', null, '—'));
        $this->assertSame('—', \external_link('https://example.com/{id}', '', '—'));
    }

    public function testIdIsUrlEncoded(): void
    {
        $out = \external_link('https://example.com/{id}', 'a b/c', 'label');
        $this->assertStringContainsString('href="https://example.com/a%20b%2Fc"', $out);
    }

    public function testLabelIsEscaped(): void
    {
        $out = \external_link('https://example.com/{id}', 1, '<script>');
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringNotContainsString('<script>', $out);
    }

    public function testButtonRendersALinkWhenConfigured(): void
    {
        $out = \external_link_button('https://example.com/edit/{id}', 7, 'Edit');

        $this->assertStringContainsString('href="https://example.com/edit/7"', $out);
        $this->assertStringContainsString('>Edit ', $out);
        $this->assertStringContainsString('class="btn"', $out);
        // The external-link icon (2026-09-03), appended after the label.
        $this->assertStringContainsString('<span class="icon">', $out);
    }

    public function testButtonIsEmptyStringWhenUnconfigured(): void
    {
        $this->assertSame('', \external_link_button(null, 7, 'Edit'));
        $this->assertSame('', \external_link_button('https://example.com/{id}', null, 'Edit'));
    }
}
