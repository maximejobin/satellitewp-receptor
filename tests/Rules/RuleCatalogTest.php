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

        foreach (['G1', 'G4', 'G5', 'G6', 'H9', 'I1', 'I4', 'J2', 'K1', 'K2', 'K4', 'K6', 'L1', 'L4', 'L5', 'M1', 'M2', 'F1', 'F4'] as $id) {
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

        $findings = array_column(
            $this->engine()->evaluate(new Context($payload))['findings'],
            null,
            'id'
        );

        foreach (['K1', 'I1', 'J2', 'M2', 'L1'] as $id) {
            $this->assertSame(Status::Fail->value, $findings[$id]['status'], "{$id} should fail");
            // Findings are neutral: they carry the raw observed value, not prose.
            $this->assertArrayHasKey('observed', $findings[$id]);
        }
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
}
