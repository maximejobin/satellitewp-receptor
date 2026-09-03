<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

/**
 * src_note() / field()'s $source param — "every datum must be explainable":
 * a data-provenance marker naming the exact dot-path and whether Xtractor
 * verified it live or is just relaying what the WordPress plugin reported.
 */
final class HelpersSrcNoteTest extends TestCase
{
    public function testPayloadPathExplainsItIsUnverifiedPluginData(): void
    {
        $note = \src_note('payload.object_cache.page_cache');

        $this->assertStringContainsString('payload.object_cache.page_cache', $note);
        $this->assertStringContainsString('WordPress plugin', $note);
        $this->assertStringContainsString('does not independently re-measure', $note);
    }

    public function testProbePathExplainsXtractorVerifiedItLive(): void
    {
        $note = \src_note('probe.tls.chain_valid');

        $this->assertStringContainsString('probe.tls.chain_valid', $note);
        $this->assertStringContainsString('Verified live', $note);
    }

    public function testCatalogPathExplainsItIsAnalystEntered(): void
    {
        $note = \src_note('catalog.plugin.akismet.license');

        $this->assertStringContainsString('by hand', $note);
        $this->assertStringContainsString('not collected from the site', $note);
    }

    public function testUnknownPathPrefixRendersNothing(): void
    {
        $this->assertSame('', \src_note('mystery.field'));
    }

    public function testFieldAppendsTheSourceMarkerWhenGiven(): void
    {
        $row = \field('Page cache', true, null, 'payload.object_cache.page_cache');

        $this->assertStringContainsString('xt-src', $row);
        $this->assertStringContainsString('payload.object_cache.page_cache', $row);
    }

    public function testFieldOmitsTheMarkerWhenNoSourceGiven(): void
    {
        $row = \field('Page cache', true);

        $this->assertStringNotContainsString('xt-src', $row);
    }

    public function testFieldRawAppendsTheSourceMarkerAfterTheTrustedHtml(): void
    {
        $row = \field_raw('Autoload', '<span>72.9 Ko</span>', null, 'payload.autoload.total_bytes');

        $this->assertStringContainsString('<span>72.9 Ko</span>', $row);
        $this->assertStringContainsString('xt-src', $row);
    }
}
