<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Rules;

use SatelliteWP\Xtractor\Reference\WordPressVersions;
use SatelliteWP\Xtractor\Rules\Context;
use SatelliteWP\Xtractor\Rules\RuleCatalog;
use SatelliteWP\Xtractor\Rules\RuleEngine;
use SatelliteWP\Xtractor\Rules\Status;
use SatelliteWP\Xtractor\Tests\TestCase;

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
                \SatelliteWP\Xtractor\Rules\Category::isValid($rule->category),
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
        // Fixture default (512000) sits at I1's new 500 KB boundary
        // (2026-09-05: banded, green is strictly under 500 KB) — make it
        // unambiguously healthy rather than relying on it landing on a line.
        $payload['autoload']['total_bytes'] = 400_000;

        // F1 (2026-09-07: counts major branches behind, not point releases —
        // see WordPressVersionsTest) needs its own reference data now,
        // unlike the plain core_update signal it replaced. The fixture's own
        // wp_version (6.8.1) marked "latest" here means zero branches behind.
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

    /**
     * G3: post_max_size / upload_max_filesize should match (PHP silently
     * caps an upload at whichever is smaller, so a mismatch is always dead
     * weight on one side) AND the effective limit should clear a real
     * working threshold (default 50 MB), or a normal upload risks silently
     * failing partway through.
     */
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
        $this->assertSame(Status::Fail->value, $g3('128M', '64M'), 'mismatch');
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

    /**
     * ConstantsCollector sends the string "N/A" for a constant that is not
     * defined, and (bool) "N/A" is true — which used to turn "no hardening at
     * all" into a green K4/K6. An undefined constant is false in WordPress.
     */
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
        // I1 used to be the id exercised here, but it's banded now (2026-09-05
        // — fixed 500 KB / 2 MB boundaries, no longer driven by $rule->threshold)
        // so overriding its config threshold no longer changes its outcome.
        // H5 (expired transients, still a plain atMost($rule->threshold)) is
        // still a real test of the override mechanism itself.
        $payload = $this->fixtureArray('extraction-valid.json'); // transients.expired = 14

        $strict   = $this->engine(['H5' => 5])->evaluate(new Context($payload));
        $findings = array_column($strict['findings'], null, 'id');

        $this->assertSame(Status::Fail->value, $findings['H5']['status']);
        $this->assertSame(5, $findings['H5']['threshold']);
    }

    public function testEolRulesUseInjectedReferenceData(): void
    {
        $eol = new \SatelliteWP\Xtractor\Reference\EndOfLife($this->tmpDir . '/reference');
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
        $this->assertSame(Status::Fail->value, $findings['F2']['status'], 'WordPress 6.8 is EOL');
        // The EOL date rides along as neutral data (for later interpolation).
        $this->assertSame('2025-12-02', $findings['F2']['data']['eol_date']);
        $this->assertSame(Status::Fail->value, $findings['H1']['status'], 'MySQL 8.0 is EOL');
    }

    /**
     * F2 fails on a missing same-branch patch (minor_update_version), never
     * just because a newer major release is offered (available_version).
     */
    public function testF2IgnoresAMajorReleaseOfferAndFailsOnlyOnABranchPatch(): void
    {
        mkdir($this->tmpDir . '/reference', 0775, true);
        file_put_contents($this->tmpDir . '/reference/wordpress.json', (string) json_encode([
            ['cycle' => '6.8', 'eol' => '2099-12-31'],
        ]));
        $eol = new \SatelliteWP\Xtractor\Reference\EndOfLife($this->tmpDir . '/reference');

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

    /**
     * F1 (rewritten 2026-09-07) fails only on a 4+ major-branch gap, not on
     * "a newer point release exists" — that narrower signal is F2/core_update
     * now. See WordPressVersionsTest for majorVersionsBehind() itself.
     */
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

    /**
     * WP_DEBUG_DISPLAY / WP_DEBUG_LOG have no real effect while WP_DEBUG
     * itself is off (2026-09-07, user: "n'a pas d'importance ... si WP_DEBUG
     * est à false") — K2/K3 must not fail on a stray true left over in
     * wp-config.php in that case.
     */
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

    /**
     * D1 must not pass on presence alone — "?all"/"+all" protect nothing,
     * and only "-all"/"~all" actually restrict who may send as the domain.
     */
    public function testD1RequiresARestrictiveAllMechanism(): void
    {
        $payload = $this->fixtureArray('extraction-valid.json');
        $dns     = static fn (?string $record): array => [
            'status' => 'ok',
            'data'   => ['spf' => ['present' => $record !== null, 'record' => $record]],
        ];

        $cases = [
            'v=spf1 include:_spf.example.com ~all' => Status::Pass,  // softfail, the recommended one
            'v=spf1 include:_spf.example.com -all' => Status::Pass,  // hardfail
            'v=spf1 include:_spf.example.com ?all' => Status::Fail,  // neutral — protects nothing
            'v=spf1 include:_spf.example.com +all' => Status::Fail,  // pass-all — accepts forgery from anywhere
            'v=spf1 include:_spf.example.com'      => Status::Fail,  // no "all" mechanism at all
            null                                    => Status::Fail,  // absent entirely
        ];

        foreach ($cases as $record => $expected) {
            $findings = array_column(
                $this->engine()->evaluate(new Context($payload, ['dns' => $dns($record)]))['findings'],
                null,
                'id'
            );
            $this->assertSame(
                $expected->value,
                $findings['D1']['status'],
                'record: ' . var_export($record, true)
            );
        }
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

    /**
     * page_count is a bare integer on every real extraction checked (never
     * the full draft/trash breakdown posts_count carries) — N2/N4 read
     * posts_count only, and must stay unknown rather than guess when even
     * that is missing.
     */
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
