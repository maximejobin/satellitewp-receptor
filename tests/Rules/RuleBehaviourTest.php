<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Rules;

use SatelliteWP\Manager\Reference\WordPressVersions;
use SatelliteWP\Manager\Rules\Check;
use SatelliteWP\Manager\Rules\Context;
use SatelliteWP\Manager\Rules\Rule;
use SatelliteWP\Manager\Rules\RuleCatalog;
use SatelliteWP\Manager\Rules\RuleEngine;
use SatelliteWP\Manager\Rules\Severity;
use SatelliteWP\Manager\Rules\Status;
use SatelliteWP\Manager\Rules\Translator;
use SatelliteWP\Manager\Tests\TestCase;

/** Branch-level behaviour of individual catalogue rules, and the catalogue's text contract. */
final class RuleBehaviourTest extends TestCase
{
    private const string CATALOG = __DIR__ . '/../../config/rules.php';
    private const string LANG    = __DIR__ . '/../../config/lang';

    /**
     * @param array<string, mixed> $probes probe name => data (status "ok")
     * @param array<string, mixed> $reference
     * @return array<string, array<string, mixed>> findings by id
     */
    private function evaluate(array $payload, array $probes = [], array $reference = []): array
    {
        $envelopes = array_map(static fn (array $data): array => ['status' => 'ok', 'data' => $data], $probes);
        $result    = (new RuleEngine(RuleCatalog::load(self::CATALOG)))->evaluate(new Context($payload, $envelopes, $reference));

        return array_column($result['findings'], null, 'id');
    }

    private function payload(): array
    {
        return $this->fixtureArray('extraction-valid.json');
    }

    /** @return array<string, mixed> */
    private function lang(string $locale): array
    {
        return (array) require self::LANG . "/{$locale}.php";
    }

    // ---- text contract -------------------------------------------------

    public function testEveryTemplateKeyIsKnownAndPresentInBothLanguages(): void
    {
        $en = $this->lang('en')['rules'];
        $fr = $this->lang('fr')['rules'];

        $this->assertSame(array_keys($en), array_keys($fr), 'same rule ids in both languages');
        foreach ($en as $id => $strings) {
            $this->assertSame(array_keys($strings), array_keys($fr[$id]), "{$id}: same keys (incl. variants) in FR and EN");
            foreach (array_keys($strings) as $key) {
                $this->assertMatchesRegularExpression('/^(title|title_success|title_failure|fail(_\w+)?|pass(_\w+)?)$/', $key, "{$id}.{$key}");
            }
            foreach (['title', 'title_success', 'title_failure', 'fail', 'pass'] as $required) {
                $this->assertNotSame('', (string) ($strings[$required] ?? ''), "{$id} EN {$required}");
                $this->assertNotSame('', (string) ($fr[$id][$required] ?? ''), "{$id} FR {$required}");
            }
        }
    }

    public function testEveryPlaceholderInATemplateIsFilledWhenEvaluatedAgainstTheFixture(): void
    {
        $wpVersions = $this->wpVersions(['6.8.1' => 'latest']);
        $findings   = $this->evaluate($this->payload(), [], ['wordpress_versions' => $wpVersions]);

        foreach (['fr', 'en'] as $locale) {
            $t = new Translator($locale, self::LANG);
            foreach ($findings as $id => $finding) {
                $message = $t->message($finding);
                if ($message === null) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('/\{\w+\}/', $message, "{$locale} {$id}: unfilled placeholder");
                $this->assertStringNotContainsString(' ?', $message, "{$locale} {$id}: unknown value rendered as \"?\"");
            }
        }
    }

    public function testFrenchTextUsesNonBreakingSpacesBeforeHighPunctuation(): void
    {
        foreach ($this->lang('fr')['rules'] as $id => $strings) {
            foreach ($strings as $key => $text) {
                $this->assertDoesNotMatchRegularExpression('/ [:;!?»%]|« /u', (string) $text, "fr {$id}.{$key}");
            }
        }
    }

    public function testVariantPicksItsOwnTemplateAndFallsBackToTheBaseOne(): void
    {
        $t = new Translator('en', self::LANG);

        $variant = $t->message(['id' => 'W1', 'status' => 'fail', 'observed' => -3, 'data' => ['variant' => 'expired']]);
        $this->assertStringContainsString('has expired', (string) $variant);

        $unknownVariant = $t->message(['id' => 'W1', 'status' => 'fail', 'observed' => 10, 'data' => ['variant' => 'nope']]);
        $this->assertStringContainsString('expires in 10 days', (string) $unknownVariant);
    }

    public function testNumbersRenderWithLocaleSeparators(): void
    {
        $finding = ['id' => 'L1', 'status' => 'pass', 'observed' => 45.5, 'data' => ['free_gb' => 12345.5]];

        $this->assertStringContainsString("45,5\u{00A0}%", (string) (new Translator('fr', self::LANG))->message($finding));
        $this->assertStringContainsString('12,345.5 GB', (string) (new Translator('en', self::LANG))->message($finding));
    }

    public function testIsoDatesRenderAsLocaleDates(): void
    {
        $finding = ['id' => 'F3', 'status' => 'pass', 'observed' => '8.3.11', 'data' => ['eol_date' => '2027-12-01']];

        $this->assertStringContainsString('1er décembre 2027', (string) (new Translator('fr', self::LANG))->message($finding));
        $this->assertStringContainsString('December 1, 2027', (string) (new Translator('en', self::LANG))->message($finding));
    }

    public function testRuleFromArrayReadsClientAction(): void
    {
        $base = ['id' => 'T', 'category' => 'DOMAIN', 'source' => 'DATA', 'severity' => Severity::Info, 'check' => static fn () => Check::pass()];

        $this->assertTrue(Rule::fromArray($base + ['client_action' => true])->clientAction);
        $this->assertFalse(Rule::fromArray($base)->clientAction);
    }

    // ---- individual rules ---------------------------------------------

    public function testHomepageRulesAreUnknownBehindHttpAuth(): void
    {
        $http = ['auth' => ['required' => true], 'status_code' => 401, 'robots' => ['present' => false], 'soft_404' => ['checked' => true, 'is_soft_404' => false]];
        $findings = $this->evaluate($this->payload(), ['http' => $http]);

        foreach (['C7', 'C8', 'C9', 'C9a', 'C10'] as $id) {
            $this->assertSame(Status::Unknown->value, $findings[$id]['status'], $id);
        }

        // Probe data predating the auth flag only carries the bare 401.
        unset($http['auth']);
        $this->assertSame(Status::Unknown->value, $this->evaluate($this->payload(), ['http' => $http])['C7']['status']);

        $http['status_code'] = 500;
        $this->assertSame(Status::Fail->value, $this->evaluate($this->payload(), ['http' => $http])['C7']['status']);
    }

    public function testNoClientTextFallsBackToAParenthesisedPlural(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $catalog = $this->lang($locale);
            array_walk_recursive($catalog, function (mixed $text, string|int $key) use ($locale): void {
                if (is_string($text)) {
                    $this->assertDoesNotMatchRegularExpression('/\((s|es|ies|x)\)/', $text, "{$locale}.{$key}: use {name|singular|plural}");
                }
            });
        }
    }

    public function testA6IsUnknownWhenALegacyVersionCouldNotBeTestedLocally(): void
    {
        $a6 = fn (array $protocols): string => $this->evaluate($this->payload(), ['tls' => ['protocols' => $protocols]])['A6']['status'];

        $this->assertSame(Status::Unknown->value, $a6(['tls1_0' => null, 'tls1_1' => false, 'tls1_2' => true]));
        $this->assertSame(Status::Fail->value, $a6(['tls1_0' => null, 'tls1_1' => true, 'tls1_2' => true]));
        $this->assertSame(Status::Pass->value, $a6(['tls1_0' => false, 'tls1_1' => false, 'tls1_2' => true]));
    }

    public function testDnsRulesAreUnknownWhenTheLookupFailed(): void
    {
        $dns = ['spf' => null, 'dmarc' => null, 'mx' => null, 'caa' => null, 'a' => null, 'aaaa' => null];
        $findings = $this->evaluate($this->payload(), ['dns' => $dns]);

        foreach (['C1', 'C2', 'D4'] as $id) {
            $this->assertSame(Status::Unknown->value, $findings[$id]['status'], $id);
        }
    }

    public function testMailAuthRulesFollowTheReceiversVerdicts(): void
    {
        $evaluate = fn (array $mail): array => $this->evaluate($this->payload(), ['mail' => $mail]);

        $pass = $evaluate(['found' => true, 'spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass']);
        foreach (['D1', 'D2', 'D3'] as $id) {
            $this->assertSame(Status::Pass->value, $pass[$id]['status'], $id);
        }

        $bad = $evaluate(['found' => true, 'spf' => 'softfail', 'dkim' => 'none', 'dmarc' => 'none']);
        $this->assertSame(Status::Fail->value, $bad['D1']['status']);
        $this->assertArrayNotHasKey('variant', $bad['D1']['data'] ?? []);
        $this->assertSame('none', $bad['D2']['data']['variant']);
        $this->assertSame('none', $bad['D3']['data']['variant']);

        $transient = $evaluate(['found' => true, 'spf' => 'temperror', 'dkim' => null, 'dmarc' => 'pass']);
        $this->assertSame(Status::Unknown->value, $transient['D1']['status']);
        $this->assertSame(Status::Unknown->value, $transient['D2']['status']);
        $this->assertSame(Status::Pass->value, $transient['D3']['status']);
    }

    public function testMailAuthRulesAreUnknownWithoutATestEmail(): void
    {
        $notFound = $this->evaluate($this->payload(), ['mail' => ['found' => false, 'spf' => null, 'dkim' => null, 'dmarc' => null]]);
        $noProbe  = $this->evaluate($this->payload());
        $errored  = array_column(
            (new RuleEngine(RuleCatalog::load(self::CATALOG)))->evaluate(new Context(
                $this->payload(),
                ['mail' => ['status' => 'error', 'data' => ['found' => true, 'spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass']]]
            ))['findings'],
            null,
            'id'
        );

        foreach ([$notFound, $noProbe, $errored] as $findings) {
            foreach (['D1', 'D2', 'D3'] as $id) {
                $this->assertSame(Status::Unknown->value, $findings[$id]['status'], $id);
            }
        }
    }

    public function testG1TreatsUnlimitedMemoryAsAPassAndLargeLimitsAsFine(): void
    {
        $g1 = function (string $limit): array {
            $payload = $this->payload();
            $payload['php']['memory_limit'] = $limit;

            return $this->evaluate($payload)['G1'];
        };

        $this->assertSame(Status::Pass->value, $g1('-1')['status']);
        $this->assertSame(Status::Pass->value, $g1('2G')['status']);
        $this->assertSame(Status::Fail->value, $g1('128M')['status']);
        $this->assertSame(Severity::Medium->value, $g1('128M')['severity']);
        $this->assertSame(Severity::High->value, $g1('32M')['severity']);
    }

    public function testF2FailsOnlyOnAMissingPatchNeverOnBranchAge(): void
    {
        $f2 = function (array $coreUpdate, array $verdicts = ['6.8.1' => '']) {
            $payload = $this->payload();
            $payload['core_update'] = $coreUpdate;

            return $this->evaluate($payload, [], ['wordpress_versions' => $this->wpVersions($verdicts)])['F2'];
        };

        $this->assertSame(Status::Pass->value, $f2(['minor_update_version' => '', 'available_version' => '6.9'])['status']);
        $patch = $f2(['minor_update_version' => '6.8.3']);
        $this->assertSame(Status::Fail->value, $patch['status']);
        $this->assertSame('6.8.3', $patch['data']['available']);
        $this->assertSame('insecure', $f2(['minor_update_version' => ''], ['6.8.1' => 'insecure'])['data']['variant']);
    }

    public function testF10CountsAVulnerabilitySeenByBothSourcesOnce(): void
    {
        $shared = ['cve_id' => 'CVE-2025-0001', 'title' => 'XSS'];
        $bv = [
            'vulnerabilities_total' => 2,
            'core'    => ['vulnerabilities' => []],
            'plugins' => ['items' => [['slug' => 'akismet', 'name' => 'Akismet', 'vulnerabilities' => [$shared, ['cve_id' => 'CVE-2025-0002']]]]],
        ];
        $wf = [
            'vulnerabilities_total' => 2,
            'core'    => ['vulnerabilities' => [['cve_id' => null, 'title' => 'Core issue']]],
            'plugins' => ['items' => [['slug' => 'akismet', 'name' => 'Akismet', 'vulnerabilities' => [$shared]]]],
        ];

        $f10 = $this->evaluate($this->payload(), ['blogvault' => $bv, 'wordfence' => $wf])['F10'];
        $this->assertSame(Status::Fail->value, $f10['status']);
        $this->assertSame(3, $f10['observed']);
        $this->assertSame(2, $f10['data']['components']);

        $this->assertSame(Status::Unknown->value, $this->evaluate($this->payload())['F10']['status']);
        $clean = ['vulnerabilities_total' => 0, 'core' => ['vulnerabilities' => []]];
        $this->assertSame(Status::Pass->value, $this->evaluate($this->payload(), ['wordfence' => $clean])['F10']['status']);
    }

    public function testF10IgnoresConfiguredVulnerabilityIds(): void
    {
        $wf = [
            'vulnerabilities_total' => 2,
            'core'    => ['vulnerabilities' => []],
            'plugins' => ['items' => [['slug' => 'akismet', 'name' => 'Akismet', 'vulnerabilities' => [
                ['id' => 'aaaa-1111', 'cve_id' => 'CVE-2025-0001', 'title' => 'One'],
                ['id' => 'bbbb-2222', 'cve_id' => 'CVE-2025-0002', 'title' => 'Two'],
            ]]]],
        ];

        $some = $this->evaluate($this->payload(), ['wordfence' => $wf], ['ignored_vulnerabilities' => ['aaaa-1111']])['F10'];
        $this->assertSame(Status::Fail->value, $some['status']);
        $this->assertSame(1, $some['observed']);

        $all = $this->evaluate($this->payload(), ['wordfence' => $wf], ['ignored_vulnerabilities' => ['aaaa-1111', 'bbbb-2222']])['F10'];
        $this->assertSame(Status::Pass->value, $all['status']);
    }

    public function testF12ReportsTheAutoUpdatePolicy(): void
    {
        $f12 = function (array $constants): array {
            $payload = $this->payload();
            $payload['constants'] = $constants + $payload['constants'];

            return $this->evaluate($payload)['F12'];
        };

        $this->assertSame(Status::Pass->value, $f12(['WP_AUTO_UPDATE_CORE' => 'minor'])['status']);
        $this->assertSame(Status::Fail->value, $f12(['WP_AUTO_UPDATE_CORE' => false])['status']);
        $this->assertSame('all_disabled', $f12(['AUTOMATIC_UPDATER_DISABLED' => true])['data']['variant']);
        $this->assertSame('file_mods', $f12(['DISALLOW_FILE_MODS' => true])['data']['variant']);
        $this->assertSame(Severity::Info->value, $f12(['WP_AUTO_UPDATE_CORE' => false])['severity']);
    }

    public function testF13ListsInactiveSoftwareButSparesTheParentThemeAndOneDefault(): void
    {
        $payload = $this->payload(); // akismet inactive; storefront is the active child's parent
        $payload['themes']['twentytwentyfour']  = ['name' => 'Twenty Twenty-Four', 'slug' => 'twentytwentyfour', 'active' => false];
        $payload['themes']['twentytwentythree'] = ['name' => 'Twenty Twenty-Three', 'slug' => 'twentytwentythree', 'active' => false];

        $f13 = $this->evaluate($payload)['F13'];
        $this->assertSame(Status::Fail->value, $f13['status']);
        $this->assertSame(2, $f13['observed']);
        $this->assertStringContainsString('Akismet', $f13['data']['names']);
        $this->assertStringNotContainsString('Storefront', $f13['data']['names']);

        $payload['is_multisite'] = true;
        $this->assertSame(Status::NotApplicable->value, $this->evaluate($payload)['F13']['status']);
    }

    public function testLongNameListsAreTruncatedWithACount(): void
    {
        $payload = $this->payload();
        $payload['plugins'] = [];
        for ($i = 1; $i <= 13; $i++) {
            $payload['plugins']["p{$i}/p{$i}.php"] = ['name' => "Plugin {$i}", 'slug' => "p{$i}/p{$i}.php", 'version' => '1', 'new_version' => '2', 'active' => true];
        }

        $f4 = $this->evaluate($payload)['F4'];
        $this->assertSame(3, $f4['data']['more']);
        $this->assertStringContainsString('et 3 autres', (string) (new Translator('fr', self::LANG))->message($f4));
    }

    public function testJ2GradesOnTheCountWhenOverdueMinutesIsMissing(): void
    {
        $j2 = function (int $overdue, ?int $minutes): array {
            $payload = $this->payload();
            $payload['cron']['overdue_events']  = $overdue;
            $payload['cron']['overdue_minutes'] = $minutes;

            return $this->evaluate($payload)['J2'];
        };

        $this->assertSame(Severity::Medium->value, $j2(4, null)['severity']);
        $this->assertSame(Severity::High->value, $j2(31, null)['severity']);
        $this->assertSame(Severity::High->value, $j2(4, 600)['severity']);
    }

    public function testK3FailsOnlyWhenTheDefaultLogIsActuallyReachable(): void
    {
        $payload = $this->payload();
        $payload['constants']['WP_DEBUG']     = true;
        $payload['constants']['WP_DEBUG_LOG'] = true;
        $k3 = fn (array $found): string => $this->evaluate($payload, ['http' => ['exposure' => ['sensitive_files' => $found]]])['K3']['status'];

        $this->assertSame(Status::Pass->value, $k3([]));
        $this->assertSame(Status::Fail->value, $k3(['wp-content/debug.log']));
        $this->assertSame(Status::Unknown->value, $this->evaluate($payload)['K3']['status']);
    }

    public function testK6PassesWhenTheWholeSiteIsForcedToHttps(): void
    {
        $payload = $this->payload();
        $payload['constants']['FORCE_SSL_ADMIN'] = 'N/A';

        $this->assertSame(Status::Fail->value, $this->evaluate($payload)['K6']['status']);
        $k6 = $this->evaluate($payload, ['http' => ['redirects' => ['forces_https' => true]]])['K6'];
        $this->assertSame(Status::Pass->value, $k6['status']);
        $this->assertSame('site_https', $k6['data']['variant']);
    }

    public function testRecalibratedSeverities(): void
    {
        $rules = [];
        foreach (RuleCatalog::load(self::CATALOG) as $rule) {
            $rules[$rule->id] = $rule->severity;
        }

        foreach (['C1', 'I4', 'K3', 'F12'] as $id) {
            $this->assertSame(Severity::Info, $rules[$id], $id);
        }
        // Green when present, coloured when missing: an Info severity would paint even a pass blue.
        $this->assertSame(Severity::High, $rules['B4']);
        foreach (['B3', 'B5', 'B7d', 'B7e', 'C9a', 'H4'] as $id) {
            $this->assertSame(Severity::Medium, $rules[$id], $id);
        }
        $this->assertArrayNotHasKey('X6', $rules);
        $this->assertArrayNotHasKey('BV2', $rules);
        $this->assertArrayNotHasKey('WF1', $rules);
        $this->assertArrayNotHasKey('PS2a', $rules);
        $this->assertArrayNotHasKey('PS3a', $rules);
    }

    public function testAccessibilityAndSeoScoreOnTheLowerOfDesktopAndMobile(): void
    {
        $pagespeed = [
            'desktop' => ['scores' => ['accessibility' => 95, 'seo' => 92]],
            'mobile'  => ['scores' => ['accessibility' => 80, 'seo' => 92]],
        ];
        $findings = $this->evaluate($this->payload(), ['pagespeed' => $pagespeed]);

        $this->assertSame(Status::Fail->value, $findings['PS2']['status']);
        $this->assertEquals(80, $findings['PS2']['observed']);
        $this->assertSame(Status::Pass->value, $findings['PS3']['status']);
    }

    /** @param array<string, string> $verdicts */
    private function wpVersions(array $verdicts): WordPressVersions
    {
        $file = $this->tmpDir . '/wordpress-versions.json';
        file_put_contents($file, (string) json_encode($verdicts));

        return new WordPressVersions($file);
    }
}
