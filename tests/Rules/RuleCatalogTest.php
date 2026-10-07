<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Rules;

use SatelliteWP\Manager\Reference\WordPressVersions;
use SatelliteWP\Manager\Rules\Context;
use SatelliteWP\Manager\Rules\RuleCatalog;
use SatelliteWP\Manager\Rules\RuleEngine;
use SatelliteWP\Manager\Rules\Status;
use SatelliteWP\Manager\Tests\TestCase;

/**
 * Guards the real catalogue: it must load, have unique ids, and behave sanely
 * against both a healthy site and an empty payload.
 */
final class RuleCatalogTest extends TestCase
{
    private const string CATALOG = __DIR__ . '/../../config/rules.php';
    private const string LANG    = __DIR__ . '/../../config/lang';

    private function engine(array $thresholds = []): RuleEngine
    {
        return new RuleEngine(RuleCatalog::load(self::CATALOG, $thresholds));
    }

    public function testCatalogLoadsWithUniqueIdsAndRequiredFields(): void
    {
        $rules = RuleCatalog::load(self::CATALOG);

        $this->assertNotEmpty($rules);

        $ids = array_map(static fn ($r): string => $r->id, $rules);
        $this->assertSame($ids, array_unique($ids), 'rule ids must be unique');

        foreach ($rules as $rule) {
            $this->assertTrue(
                \SatelliteWP\Manager\Rules\Category::isValid($rule->category),
                "{$rule->id} has a valid category"
            );
            $this->assertContains($rule->source, ['DATA', 'EXT', 'EMAIL'], "{$rule->id} source");
        }
    }

    public function testEveryRuleHasBilingualStrings(): void
    {
        $en = (array) require self::LANG . '/en.php';
        $fr = (array) require self::LANG . '/fr.php';

        foreach (RuleCatalog::load(self::CATALOG) as $rule) {
            $this->assertArrayHasKey($rule->id, $en['rules'], "{$rule->id} missing EN strings");
            $this->assertArrayHasKey($rule->id, $fr['rules'], "{$rule->id} missing FR strings");
            $this->assertNotSame('', (string) ($en['rules'][$rule->id]['title'] ?? ''), "{$rule->id} EN title");
            $this->assertNotSame('', (string) ($en['rules'][$rule->id]['fail'] ?? ''), "{$rule->id} EN fail");
            $this->assertNotSame('', (string) ($fr['rules'][$rule->id]['fail'] ?? ''), "{$rule->id} FR fail");
        }
    }

    /**
     * A finding's title/fail/pass text is shown as-is, including in the
     * client-facing report — it must never name which third-party source
     * (BlogVault, Wordfence) produced the verdict.
     */
    public function testNoRuleTextNamesAThirdPartyVendor(): void
    {
        $en = (array) require self::LANG . '/en.php';
        $fr = (array) require self::LANG . '/fr.php';

        foreach (['en' => $en, 'fr' => $fr] as $lang => $catalog) {
            foreach ((array) $catalog['rules'] as $id => $strings) {
                foreach (['title', 'title_success', 'title_failure', 'fail', 'pass'] as $key) {
                    $text = (string) ($strings[$key] ?? '');
                    foreach (['BlogVault', 'Wordfence'] as $vendor) {
                        $this->assertStringNotContainsStringIgnoringCase($vendor, $text, "{$lang} {$id}.{$key}");
                    }
                }
            }
        }
    }

    /**
     * Every active rule needs a verdict-specific headline for both outcomes
     * — "Website infected" reads nothing like "No hacking detected", so
     * these can never be derived from one another or left unset.
     */
    public function testEveryRuleHasSuccessAndFailureTitlesInBothLanguages(): void
    {
        $en = (array) require self::LANG . '/en.php';
        $fr = (array) require self::LANG . '/fr.php';

        foreach (RuleCatalog::load(self::CATALOG) as $rule) {
            foreach (['title_success', 'title_failure'] as $key) {
                $this->assertNotSame('', (string) ($en['rules'][$rule->id][$key] ?? ''), "{$rule->id} EN {$key}");
                $this->assertNotSame('', (string) ($fr['rules'][$rule->id][$key] ?? ''), "{$rule->id} FR {$key}");
            }
        }
    }

    public function testEmptyPayloadYieldsNoFailuresOnlyUnknowns(): void
    {
        $result = $this->engine()->evaluate(new Context([]));

        $this->assertSame(
            0,
            $result['counts']['fail'],
            'with no data at all, rules must report unknown rather than invent failures'
        );
        $this->assertGreaterThan(0, $result['counts']['unknown']);
    }

    public function testW2AndW3AreClientActionAdvisoriesRenderedPurple(): void
    {
        $findings = array_column(
            $this->engine()->evaluate(new Context([]))['findings'],
            null,
            'id'
        );

        foreach (['W2', 'W3'] as $id) {
            $this->assertSame(Status::Pass->value, $findings[$id]['status']);
            $this->assertSame(
                'purple',
                $findings[$id]['pastille'],
                "{$id} is a standing client-action advisory, not a plain Info blue"
            );
        }
    }

    public function testHealthySiteFixturePassesTheDataRules(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');

        // Make the fixture fully healthy for the [DATA] rules under test.
        $payload['php']['max_input_vars'] = '5000';
        $payload['php']['extensions']     = ['curl', 'mbstring', 'openssl', 'zip', 'dom', 'xml', 'json', 'gd', 'Zend OPcache'];
        $payload['db_table_prefix']       = 'swp_';
        $payload['object_cache']          = ['external' => true, 'dropin' => true, 'page_cache' => true];
        $payload['filesystem']['core_writable'] = false;
        // plugins/themes arrive keyed by plugin file / stylesheet, never as lists.
        $payload['plugins']['woocommerce/woocommerce.php']['new_version'] = '';
        // The fixture's 512000 sits exactly on I1's 500 KB boundary.
        $payload['autoload']['total_bytes'] = 400_000;
        mkdir($this->tmpDir . '/reference', 0775, true);
        file_put_contents(
            $this->tmpDir . '/reference/wordpress-versions.json',
            (string) json_encode(['6.8.1' => 'latest'])
        );
        $wpVersions = new WordPressVersions($this->tmpDir . '/reference/wordpress-versions.json');

        $findings = array_column(
            $this->engine()->evaluate(new Context($payload, [], ['wordpress_versions' => $wpVersions]))['findings'],
            null,
            'id'
        );

        foreach (['G1', 'G3', 'G4', 'G5', 'G6', 'I1', 'I4', 'J2', 'K1', 'K2', 'K4', 'K6', 'L1', 'L4', 'M1', 'M2', 'F1', 'F4', 'N2', 'N4'] as $id) {
            $this->assertSame(
                Status::Pass->value,
                $findings[$id]['status'],
                "{$id} should pass on a healthy site, observed: " . var_export($findings[$id]['observed'] ?? null, true)
            );
        }
    }

    public function testDataRulesDetectRealProblems(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        $payload['constants']['WP_DEBUG']    = true;   // K1
        $payload['autoload']['total_bytes']  = 2_000_000; // I1
        $payload['cron']['overdue_events']   = 7;      // J2
        $payload['administrators']           = [['id' => 1, 'login' => 'admin']]; // M2
        $payload['filesystem']['disk_free_bytes'] = 1_000_000; // L1, ~1%
        $payload['posts_count']['trash']     = 50;  // N2 (threshold 20)
        $payload['posts_count']['draft']     = 500; // N4: 500/(500+100) = 83% (threshold 30%)
        $payload['posts_count']['publish']   = 100;

        $findings = array_column(
            $this->engine()->evaluate(new Context($payload))['findings'],
            null,
            'id'
        );

        foreach (['K1', 'I1', 'J2', 'M2', 'L1', 'N2', 'N4'] as $id) {
            $this->assertSame(Status::Fail->value, $findings[$id]['status'], "{$id} should fail");
            // Findings are neutral: they carry the raw observed value, not prose.
            $this->assertArrayHasKey('observed', $findings[$id]);
        }
    }

    /** G3: the two upload limits must match and clear the working threshold. */
    public function testUploadLimitsConsistencyRule(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');

        // Mismatched — fails regardless of either value's own size.
        $payload['php']['post_max_size']       = '64M';
        $payload['php']['upload_max_filesize'] = '32M';
        $payload['php']['upload_max_size']     = 32 * 1048576;
        $findings = array_column($this->engine()->evaluate(new Context($payload))['findings'], null, 'id');
        $this->assertSame(Status::Fail->value, $findings['G3']['status']);

        // Identical but under the 50 MB threshold — still fails.
        $payload['php']['post_max_size']       = '32M';
        $payload['php']['upload_max_filesize'] = '32M';
        $payload['php']['upload_max_size']     = 32 * 1048576;
        $findings = array_column($this->engine()->evaluate(new Context($payload))['findings'], null, 'id');
        $this->assertSame(Status::Fail->value, $findings['G3']['status']);

        // Identical and at least 50 MB — passes.
        $payload['php']['post_max_size']       = '64M';
        $payload['php']['upload_max_filesize'] = '64M';
        $payload['php']['upload_max_size']     = 64 * 1048576;
        $findings = array_column($this->engine()->evaluate(new Context($payload))['findings'], null, 'id');
        $this->assertSame(Status::Pass->value, $findings['G3']['status']);

        // Missing data — unknown, never a fabricated failure.
        unset($payload['php']['post_max_size']);
        $findings = array_column($this->engine()->evaluate(new Context($payload))['findings'], null, 'id');
        $this->assertSame(Status::Unknown->value, $findings['G3']['status']);
    }

    /** G3 compares bytes, not raw ini strings, and reads post_max_size 0 as unlimited. */
    public function testUploadLimitsCompareBytesAndTreatZeroPostAsUnlimited(): void
    {
        $g3 = function (string $post, string $upload): string {
            $payload = $this->fixtureArray('extraction-valid.json');
            $payload['php']['post_max_size']       = $post;
            $payload['php']['upload_max_filesize'] = $upload;
            $payload['php']['upload_max_size']     = 0; // never read by G3 any more

            $findings = array_column($this->engine()->evaluate(new Context($payload))['findings'], null, 'id');

            return $findings['G3']['status'];
        };

        $this->assertSame(Status::Pass->value, $g3('64M', '64m'), 'same size, different case');
        $this->assertSame(Status::Pass->value, $g3('64M', '67108864'), 'same size, different notation');
        $this->assertSame(Status::Pass->value, $g3('0', '64M'), 'post_max_size 0 = unlimited, not a mismatch');
        $this->assertSame(Status::Fail->value, $g3('0', '32M'), 'unlimited POST, but uploads still capped under 50 MB');
        $this->assertSame(Status::Pass->value, $g3('128M', '64M'), 'POST above the file limit is the recommended setting');
        $this->assertSame(Status::Fail->value, $g3('64M', '128M'), 'POST below the file limit');
        $this->assertSame(Status::Fail->value, $g3('32M', '32M'), 'identical but under 50 MB');
        $this->assertSame(Status::Unknown->value, $g3('garbage', '64M'), 'unparseable value');
    }

    public function testL1CarriesTheFreeSpaceInGigabytes(): void
    {
        $l1 = function (int $free, int $total): array {
            $payload = $this->fixtureArray('extraction-valid.json');
            $payload['filesystem']['disk_free_bytes']  = $free;
            $payload['filesystem']['disk_total_bytes'] = $total;

            return array_column($this->engine()->evaluate(new Context($payload))['findings'], null, 'id')['L1'];
        };

        $pass = $l1(50 * 1073741824, 100 * 1073741824);
        $this->assertSame(Status::Pass->value, $pass['status']);
        $this->assertEquals(50.0, $pass['data']['free_gb']);

        $fail = $l1(1073741824, 100 * 1073741824);
        $this->assertSame(Status::Fail->value, $fail['status']);
        $this->assertEquals(1.0, $fail['data']['free_gb']);
    }

    /** A homepage behind HTTP Basic Auth returns the gate's headers, not the site's: unknown, never a fail. */
    public function testSecurityHeaderRulesAreUnknownWhenTheHomepageRequiresAuth(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        $probes  = ['http' => ['status' => 'ok', 'data' => [
            'auth'             => ['required' => true, 'configured' => false],
            'security_headers' => [],
        ]]];

        $findings = array_column($this->engine()->evaluate(new Context($payload, $probes))['findings'], null, 'id');

        foreach (['A8', 'B7a', 'B7b', 'B7c', 'B7d', 'B7e'] as $id) {
            $this->assertSame(Status::Unknown->value, $findings[$id]['status'], "{$id} must not fail a non-public site");
        }

        $probes['http']['data']['auth']['required'] = false;
        $findings = array_column($this->engine()->evaluate(new Context($payload, $probes))['findings'], null, 'id');
        $this->assertSame(Status::Fail->value, $findings['B7a']['status'], 'public site with no headers still fails');
    }

    /** "N/A" (undefined constant) reads as false, as in WordPress — (bool) "N/A" would be true. */
    public function testUndefinedConstantsAreReadAsFalseNotTrue(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        $payload['constants'] = array_fill_keys(array_keys($payload['constants']), 'N/A');

        $findings = array_column(
            $this->engine()->evaluate(new Context($payload))['findings'],
            null,
            'id'
        );

        // Undefined WP_DEBUG / WP_DEBUG_DISPLAY means debugging is off.
        foreach (['K1', 'K2'] as $id) {
            $this->assertSame(Status::Pass->value, $findings[$id]['status'], "{$id} should pass");
        }

        // Undefined DISALLOW_FILE_EDIT / FORCE_SSL_ADMIN means not hardened.
        foreach (['K4', 'K6'] as $id) {
            $this->assertSame(Status::Fail->value, $findings[$id]['status'], "{$id} should fail");
        }
    }

    /** No constants collected at all is unknown, not a fabricated failure. */
    public function testMissingConstantsBlockStaysUnknown(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        unset($payload['constants']);

        $findings = array_column(
            $this->engine()->evaluate(new Context($payload))['findings'],
            null,
            'id'
        );

        foreach (['K1', 'K2', 'K4', 'K6'] as $id) {
            $this->assertSame(Status::Unknown->value, $findings[$id]['status'], "{$id} should be unknown");
        }
    }

    public function testThresholdOverrideIsApplied(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json'); // transients.expired = 14

        $strict   = $this->engine(['H5' => 5])->evaluate(new Context($payload));
        $findings = array_column($strict['findings'], null, 'id');

        $this->assertSame(Status::Fail->value, $findings['H5']['status']);
        $this->assertSame(5, $findings['H5']['threshold']);
    }

    public function testEolRulesUseInjectedReferenceData(): void
    {
        $eol = new \SatelliteWP\Manager\Reference\EndOfLife($this->tmpDir . '/reference');
        mkdir($this->tmpDir . '/reference', 0775, true);
        file_put_contents($this->tmpDir . '/reference/php.json', (string) json_encode([
            ['cycle' => '8.3', 'eol' => '2027-12-31'],
            ['cycle' => '7.4', 'eol' => '2022-11-28'],
        ]));
        file_put_contents($this->tmpDir . '/reference/wordpress.json', (string) json_encode([
            ['cycle' => '6.8', 'eol' => '2025-12-02'],
        ]));
        file_put_contents($this->tmpDir . '/reference/mysql.json', (string) json_encode([
            ['cycle' => '8.0', 'eol' => '2026-04-30'],
        ]));

        $payload = $this->fixtureArray('extraction-valid.json'); // PHP 8.3.11, WP 6.8.1, mysql 8.0.36

        $findings = array_column(
            $this->engine()->evaluate(new Context($payload, [], ['eol' => $eol]))['findings'],
            null,
            'id'
        );

        $this->assertSame(Status::Pass->value, $findings['F3']['status'], 'PHP 8.3 still supported');
        $this->assertSame('2027-12-31', $findings['F3']['data']['eol_date']);
        $this->assertSame(Status::Fail->value, $findings['H1']['status'], 'MySQL 8.0 is EOL');
        $this->assertSame('MySQL 8.0', $findings['H1']['observed']);
    }

    /** F8 flags only wp.org-hosted software idle past the threshold; premium/custom items are skipped. */
    public function testF8FlagsOnlyAWporgHostedPluginOrThemeIdleBeyondTheThreshold(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        // Fixture: plugins woocommerce/woocommerce.php + akismet/akismet.php,
        // theme storefront (+ storefront-child, no 'slug' collision risk).
        $f8 = function (array $wporgData) use ($payload): array {
            $probes   = ['wporg' => ['status' => 'ok', 'data' => $wporgData]];
            $findings = array_column($this->engine()->evaluate(new Context($payload, $probes))['findings'], null, 'id');

            return $findings['F8'];
        };

        $this->assertSame(Status::Unknown->value, $f8([])['status'], 'the probe never ran / nothing matched');

        $recent = gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400);
        $stale  = gmdate('Y-m-d\TH:i:s\Z', time() - 400 * 86400);

        $allRecent = $f8([
            'plugins' => [
                'woocommerce' => ['on_wporg' => true, 'last_updated' => $recent],
                'akismet'     => ['on_wporg' => true, 'last_updated' => $recent],
            ],
            'themes' => ['storefront' => ['on_wporg' => true, 'last_updated' => $recent]],
        ]);
        $this->assertSame(Status::Pass->value, $allRecent['status']);

        $oneStale = $f8([
            'plugins' => [
                'woocommerce' => ['on_wporg' => true, 'last_updated' => $stale],
                'akismet'     => ['on_wporg' => true, 'last_updated' => $recent],
            ],
            'themes' => ['storefront' => ['on_wporg' => true, 'last_updated' => $recent]],
        ]);
        $this->assertSame(Status::Fail->value, $oneStale['status']);
        $this->assertSame(1, $oneStale['observed']);
        $this->assertStringContainsString('WooCommerce', $oneStale['data']['names']);

        // Not on wp.org at all (a premium plugin) — skipped, not flagged,
        // even though it has no last_updated to check.
        $notOnWporg = $f8([
            'plugins' => [
                'woocommerce' => ['on_wporg' => false, 'last_updated' => null],
                'akismet'     => ['on_wporg' => true, 'last_updated' => $recent],
            ],
            'themes' => ['storefront' => ['on_wporg' => true, 'last_updated' => $recent]],
        ]);
        $this->assertSame(Status::Pass->value, $notOnWporg['status']);
    }

    public function testF2IgnoresAMajorReleaseOfferAndFailsOnlyOnABranchPatch(): void
    {
        mkdir($this->tmpDir . '/reference', 0775, true);
        file_put_contents($this->tmpDir . '/reference/wordpress.json', (string) json_encode([
            ['cycle' => '6.8', 'eol' => '2099-12-31'],
        ]));
        $eol = new \SatelliteWP\Manager\Reference\EndOfLife($this->tmpDir . '/reference');

        $payload = $this->fixtureArray('extraction-valid.json'); // WP 6.8.1
        $f2 = function (array $coreUpdate) use ($payload, $eol): array {
            $payload['core_update'] = $coreUpdate;
            $findings = array_column($this->engine()->evaluate(new Context($payload, [], ['eol' => $eol]))['findings'], null, 'id');

            return $findings['F2'];
        };

        $majorOnly = $f2(['available_version' => '6.9', 'status' => 'upgrade', 'minor_update_version' => '']);
        $this->assertSame(Status::Pass->value, $majorOnly['status'], 'a newer major release is not a missing security patch');

        $branchPatch = $f2(['available_version' => '6.9', 'status' => 'upgrade', 'minor_update_version' => '6.8.3']);
        $this->assertSame(Status::Fail->value, $branchPatch['status']);
        $this->assertSame('6.8.3', $branchPatch['data']['available']);
    }

    /** F1 fails only on a 4+ major-branch gap; a missing point release is F2's concern. */
    public function testF1FailsOnlyFourOrMoreMajorBranchesBehind(): void
    {
        mkdir($this->tmpDir . '/reference', 0775, true);
        file_put_contents($this->tmpDir . '/reference/wordpress-versions.json', (string) json_encode([
            '6.4' => '', '6.5' => '', '6.6' => '', '6.7' => '', '6.8' => 'latest',
        ]));
        $wpVersions = new WordPressVersions($this->tmpDir . '/reference/wordpress-versions.json');

        $payload = $this->fixtureArray('extraction-valid.json'); // wp_version 6.8.1 in the base fixture
        $findings = array_column(
            $this->engine()->evaluate(new Context($payload, [], ['wordpress_versions' => $wpVersions]))['findings'],
            null,
            'id'
        );
        $this->assertSame(Status::Pass->value, $findings['F1']['status'], 'on the latest branch');

        $payload['wp_version'] = '6.4.9'; // 4 branches behind 6.8
        $findings = array_column(
            $this->engine()->evaluate(new Context($payload, [], ['wordpress_versions' => $wpVersions]))['findings'],
            null,
            'id'
        );
        $this->assertSame(Status::Fail->value, $findings['F1']['status'], '4 branches behind');
        $this->assertSame(4, $findings['F1']['data']['major_versions_behind']);
    }

    /** WP_DEBUG_DISPLAY / WP_DEBUG_LOG have no effect while WP_DEBUG is off. */
    public function testK2AndK3IgnoreOwnValueWhenWpDebugIsOff(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        $payload['constants']['WP_DEBUG']         = false;
        $payload['constants']['WP_DEBUG_DISPLAY'] = true;
        $payload['constants']['WP_DEBUG_LOG']     = true;

        $findings = array_column(
            $this->engine()->evaluate(new Context($payload))['findings'],
            null,
            'id'
        );

        $this->assertSame(Status::Pass->value, $findings['K2']['status']);
        $this->assertSame(Status::NotApplicable->value, $findings['K3']['status']);
    }

    public function testProbeRulesAreUnknownWhenProbesDidNotRun(): void
    {
        $findings = array_column(
            $this->engine()->evaluate(new Context($this->fixtureArray('extraction-valid.json')))['findings'],
            null,
            'id'
        );

        // No probe data at all — external rules must not claim a failure.
        foreach (['A1', 'A10', 'B1', 'C5', 'D1', 'W1'] as $id) {
            $this->assertSame(Status::Unknown->value, $findings[$id]['status'], "{$id} without probes");
        }
    }

    /** N2/N4 read posts_count only (page_count is a bare integer) and stay unknown without it. */
    public function testN2AndN4AreUnknownWithoutPostsCount(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        unset($payload['posts_count']);

        $findings = array_column(
            $this->engine()->evaluate(new Context($payload))['findings'],
            null,
            'id'
        );

        $this->assertSame(Status::Unknown->value, $findings['N2']['status']);
        $this->assertSame(Status::Unknown->value, $findings['N4']['status']);
    }

    public function testN4IsTheDraftShareNotTheDraftToPublishedRatio(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        $payload['posts_count']['publish'] = '3';
        $payload['posts_count']['draft']   = '1';

        $findings = array_column(
            $this->engine()->evaluate(new Context($payload))['findings'],
            null,
            'id'
        );

        // 1 draft among 4 total (3 published + 1 draft) = 25%, not 1/3 = 33%.
        $this->assertSame(25.0, $findings['N4']['observed']);
    }
}
