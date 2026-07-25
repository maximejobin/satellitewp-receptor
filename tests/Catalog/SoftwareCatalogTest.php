<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Catalog;

use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use SatelliteWP\Xtractor\Tests\TestCase;

final class SoftwareCatalogTest extends TestCase
{
    private function catalog(): SoftwareCatalog
    {
        return new SoftwareCatalog($this->tmpDir . '/catalog/software.json');
    }

    public function testNormalizeSlug(): void
    {
        $this->assertSame('woocommerce', SoftwareCatalog::normalizeSlug('plugin', 'woocommerce/woocommerce.php'));
        $this->assertSame('hello', SoftwareCatalog::normalizeSlug('plugin', 'hello.php'));
        $this->assertSame('storefront', SoftwareCatalog::normalizeSlug('theme', 'storefront'));
    }

    public function testRecordExtractionCreatesThenBumps(): void
    {
        $catalog = $this->catalog();

        $payload = [
            'plugins' => [
                ['slug' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce'],
                ['slug' => 'akismet/akismet.php', 'name' => 'Akismet'],
            ],
            'themes' => [['slug' => 'storefront', 'name' => 'Storefront']],
        ];

        $this->assertSame(3, $catalog->recordExtraction($payload, '2026-07-01T00:00:00Z'));

        // Second extraction: same slugs -> nothing new, seen_count bumps.
        $this->assertSame(0, $catalog->recordExtraction($payload, '2026-07-25T00:00:00Z'));

        $wc = $catalog->get('plugin', 'woocommerce');
        $this->assertSame('WooCommerce', $wc['name']);
        $this->assertSame('unknown', $wc['license']);
        $this->assertSame(2, $wc['seen_count']);
        $this->assertSame('2026-07-01T00:00:00Z', $wc['first_seen']);
        $this->assertSame('2026-07-25T00:00:00Z', $wc['last_seen']);
    }

    public function testSetLicenseAndNeedsLicense(): void
    {
        $catalog = $this->catalog();
        $catalog->recordExtraction(['plugins' => [['slug' => 'mailpoet/mailpoet.php', 'name' => 'MailPoet']]]);

        $this->assertTrue($catalog->setLicense('plugin', 'mailpoet', SoftwareCatalog::LICENSE_MIXED));
        $this->assertFalse($catalog->setLicense('plugin', 'nope', SoftwareCatalog::LICENSE_FREE));
        $this->assertFalse($catalog->setLicense('plugin', 'mailpoet', 'bogus'));

        $entry = $catalog->get('plugin', 'mailpoet');
        $this->assertSame('mixed', $entry['license']);
        $this->assertTrue(SoftwareCatalog::needsLicense($entry));
    }

    public function testSuggestFromWporgPresence(): void
    {
        $catalog = $this->catalog();
        $catalog->recordExtraction([
            'plugins' => [
                ['slug' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce'], // on repo
                ['slug' => 'acme-pro/acme-pro.php', 'name' => 'Acme Pro'],           // not on repo
            ],
        ]);

        // Stub wp.org: only woocommerce is on the repo.
        $updated = $catalog->suggest(fn (string $type, string $slug): bool => $slug === 'woocommerce');
        $this->assertSame(2, $updated);

        $wc = $catalog->get('plugin', 'woocommerce');
        $this->assertSame('wporg', $wc['source']);
        $this->assertSame('free', $wc['suggested']);
        $this->assertFalse(SoftwareCatalog::needsLicense($wc));

        $pro = $catalog->get('plugin', 'acme-pro');
        $this->assertSame('absent', $pro['source']);
        $this->assertSame('premium', $pro['suggested']);
        $this->assertTrue(SoftwareCatalog::needsLicense($pro), 'premium -> needs a licence');
    }

    public function testEffectiveLicensePrefersAnalystChoiceOverSuggestion(): void
    {
        $entry = ['license' => 'unknown', 'suggested' => 'free'];
        $this->assertSame('free', SoftwareCatalog::effectiveLicense($entry));

        $entry['license'] = 'mixed'; // analyst overrides the suggestion
        $this->assertSame('mixed', SoftwareCatalog::effectiveLicense($entry));
    }

    public function testAllFilters(): void
    {
        $catalog = $this->catalog();
        $catalog->recordExtraction([
            'plugins' => [['slug' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce']],
            'themes'  => [['slug' => 'storefront', 'name' => 'Storefront']],
        ]);
        $catalog->setLicense('plugin', 'woocommerce', SoftwareCatalog::LICENSE_PREMIUM);

        $this->assertCount(2, $catalog->all());
        $this->assertCount(1, $catalog->all('plugin'));
        $needs = $catalog->all(null, true);
        $this->assertCount(1, $needs);
        $this->assertSame('woocommerce', $needs[0]['slug']);
    }
}
