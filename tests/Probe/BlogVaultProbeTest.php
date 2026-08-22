<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Probe\BlogVaultProbe;

/**
 * Parsing is pure and runs offline against captured v6 responses for
 * hacked-demo.test — a site BlogVault reports as hacked with a vulnerable
 * core, so every interesting branch is exercised.
 */
final class BlogVaultProbeTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function raw(): array
    {
        $dir = dirname(__DIR__) . '/fixtures/blogvault';

        $raw = [];
        foreach (['site', 'core', 'plugins', 'themes', 'scan', 'users', 'settings'] as $name) {
            $raw[$name] = json_decode((string) file_get_contents("{$dir}/{$name}.json"), true);
        }

        return $raw;
    }

    /** Same fixture, with the six remediation-detail calls a hacked site triggers. */
    private function rawWithRemediation(): array
    {
        $dir = dirname(__DIR__) . '/fixtures/blogvault';
        $raw = $this->raw();

        $map = [
            'scan_files'        => 'scan_files',
            'scan_scripts'      => 'scan_scripts',
            'scan_plugins'      => 'scan_plugins',
            'scan_cron_jobs'    => 'scan_cron_jobs',
            'scan_redirections' => 'scan_redirections',
            'snapshots'         => 'snapshots',
        ];
        foreach ($map as $key => $file) {
            $raw[$key] = json_decode((string) file_get_contents("{$dir}/{$file}.json"), true);
        }

        return $raw;
    }

    public function testSiteIsMatchedOnExactHost(): void
    {
        $listed = ['sites' => [
            ['id' => 'aaa', 'url' => 'https://staging.example.com'],
            ['id' => 'bbb', 'url' => 'https://example.com'],
        ]];

        $this->assertSame('bbb', BlogVaultProbe::matchSite($listed, 'example.com')['id']);
        $this->assertSame('aaa', BlogVaultProbe::matchSite($listed, 'staging.example.com')['id']);
    }

    public function testUnrelatedHostDoesNotMatch(): void
    {
        // "url:contains" is a substring filter, so near-misses come back too.
        $listed = ['sites' => [['id' => 'aaa', 'url' => 'https://notexample.com']]];

        $this->assertNull(BlogVaultProbe::matchSite($listed, 'example.com'));
        $this->assertNull(BlogVaultProbe::matchSite(['sites' => []], 'example.com'));
    }

    public function testConnectionSecretsNeverReachTheProbeData(): void
    {
        $data = BlogVaultProbe::parse($this->raw());
        $json = (string) json_encode($data);

        $this->assertArrayNotHasKey('http_auth', $data['site']['connection']);
        $this->assertArrayNotHasKey('sticky_ip', $data['site']['connection']);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('PLACEHOLDER', $json);
        // The non-secret part of the connection survives.
        $this->assertSame('connected', $data['site']['connection']['status']);
    }

    public function testRedactConnectionKeepsEverythingElse(): void
    {
        $redacted = BlogVaultProbe::redactConnection([
            'status'    => 'connected',
            'sticky_ip' => '203.0.113.10',
            'http_auth' => ['username' => 'u', 'password' => 'p'],
        ]);

        $this->assertSame(['status' => 'connected'], $redacted);
    }

    public function testScannerReportsHackedWithUnresolvedDetections(): void
    {
        $scanner = BlogVaultProbe::parse($this->raw())['scanner'];

        $this->assertTrue($scanner['enabled']);
        $this->assertSame('hacked', $scanner['status']);
        $this->assertSame('2026-08-21T15:44:57Z', $scanner['malware_detected_at']);
        $this->assertSame(1, $scanner['detections']['files']['total']);
        $this->assertSame(0, $scanner['detections']['files']['marked_safe']);
        $this->assertSame(1, $scanner['unresolved_count']);
    }

    public function testDetectionsMarkedSafeDoNotCountAsUnresolved(): void
    {
        $raw = $this->raw();
        $raw['scan']['detections']['files'] = ['total' => 3, 'marked_safe' => 3];

        $this->assertSame(0, BlogVaultProbe::parse($raw)['scanner']['unresolved_count']);
    }

    public function testCoreVulnerabilitiesCarryCveAndSeverity(): void
    {
        $core = BlogVaultProbe::parse($this->raw())['core'];

        $this->assertTrue($core['vulnerable']);
        $this->assertTrue($core['update_available']);
        $this->assertSame('6.6.2-alpha-58805', $core['current_version']);
        $this->assertCount(14, $core['vulnerabilities']);

        $cves = array_column($core['vulnerabilities'], 'cve_id');
        $this->assertContains('CVE-2025-58674', $cves);

        $first = $core['vulnerabilities'][0];
        $this->assertSame('medium', $first['cvss_rating']);
        $this->assertSame(4.3, $first['cvss_score']);
        $this->assertSame('6.6.4', $first['patched_version']);
        $this->assertFalse($first['virtual_patch']);
    }

    public function testPluginsAndThemesAreCounted(): void
    {
        $data = BlogVaultProbe::parse($this->raw());

        $this->assertSame(20, $data['plugins']['total']);
        $this->assertSame(9, $data['plugins']['update_available']);
        $this->assertSame(0, $data['plugins']['malicious_count']);
        // 5 plugins carry 9 known CVEs between them on this site.
        $this->assertSame(5, $data['plugins']['vulnerable_count']);
        $this->assertSame(9, $data['plugins']['vulnerabilities_total']);
        $this->assertSame(14, $data['themes']['total']);
        $this->assertSame(14, $data['themes']['update_available']);

        $akismet = $data['plugins']['items'][0];
        $this->assertSame('akismet', $akismet['slug']);
        $this->assertSame('5.6', $akismet['current_version']);
        $this->assertTrue($akismet['update_available']);
        $this->assertFalse($akismet['active']);
    }

    public function testVulnerabilityTotalSumsCorePluginsAndThemes(): void
    {
        $data = BlogVaultProbe::parse($this->raw());

        $this->assertSame(
            count($data['core']['vulnerabilities'])
                + $data['plugins']['vulnerabilities_total']
                + $data['themes']['vulnerabilities_total'],
            $data['vulnerabilities_total']
        );
        $this->assertGreaterThan(0, $data['vulnerabilities_total']);
    }

    public function testVulnerablePluginIsCountedAndDetailed(): void
    {
        $raw = $this->raw();
        $raw['plugins']['sites'][0]['wp']['plugins'][0]['security'] = [
            'vulnerable'      => true,
            'vulnerabilities' => [[
                'vulnerability_id' => 'abc',
                'title'            => 'Akismet XSS',
                'cve_id'           => 'CVE-2026-0001',
                'cvss_rating'      => 'high',
                'cvss_score'       => 8.1,
                'patched_version'  => '5.7',
                'published_at'     => '2026-01-01T00:00:00Z',
                'virtual_patch'    => ['applied' => true],
            ]],
        ];

        $plugins = BlogVaultProbe::parse($raw)['plugins'];

        // One more on top of the five the fixture already carries.
        $this->assertSame(6, $plugins['vulnerable_count']);
        $this->assertSame(10, $plugins['vulnerabilities_total']);
        $this->assertTrue($plugins['items'][0]['vulnerable']);
        $this->assertSame('CVE-2026-0001', $plugins['items'][0]['vulnerabilities'][0]['cve_id']);
        $this->assertTrue($plugins['items'][0]['vulnerabilities'][0]['virtual_patch']);
    }

    public function testUsersExpose2faWithoutEmails(): void
    {
        $users = BlogVaultProbe::parse($this->raw())['users'];

        $this->assertSame(1, $users['total']);
        $this->assertSame(1, $users['administrators']);
        $this->assertSame(1, $users['administrators_without_2fa']);
        $this->assertSame(0, $users['default_logins']);
        $this->assertSame('not_applied', $users['items'][0]['two_fa_status']);
        $this->assertArrayNotHasKey('email', $users['items'][0]);
    }

    public function testOnlyAppliedCountsAsProtected(): void
    {
        $raw = $this->raw();
        $raw['users']['sites'][0]['wp']['users'] = [
            ['username' => 'a', 'role' => 'administrator', 'two_fa_status' => 'applied'],
            ['username' => 'b', 'role' => 'administrator', 'two_fa_status' => 'pending'],
            ['username' => 'c', 'role' => 'editor', 'two_fa_status' => 'not_applied'],
        ];

        $users = BlogVaultProbe::parse($raw)['users'];

        $this->assertSame(2, $users['administrators']);
        // "pending" is an invitation, not protection.
        $this->assertSame(1, $users['administrators_without_2fa']);
    }

    public function testBackupsAndFirewallAndHardening(): void
    {
        $data = BlogVaultProbe::parse($this->raw());

        $this->assertTrue($data['backups']['enabled']);
        $this->assertSame(90, $data['backups']['retention_days']);
        $this->assertSame('succeeded', $data['backups']['latest_snapshot']['status']);
        $this->assertIsInt($data['backups']['latest_snapshot']['age_days']);

        $this->assertTrue($data['firewall']['enabled']);
        $this->assertSame('protect', $data['firewall']['mode']);
        $this->assertTrue($data['firewall']['bot_protection']);

        $this->assertSame(['declared' => false, 'value' => null], $data['hardening']['disallow_file_edit']);
        $this->assertTrue($data['hardening']['wp_auto_updates']);
    }

    public function testServerAndHealthAreCarriedThrough(): void
    {
        $data = BlogVaultProbe::parse($this->raw());

        $this->assertSame('8.1', $data['server']['php_version']);
        $this->assertSame('8.4.7', $data['server']['mysql_version']);
        $this->assertNull($data['server']['hosting']);
        $this->assertSame(30, $data['health']['score']);
        $this->assertFalse($data['site']['woocommerce']);
    }

    public function testStatusIsWarnWhenSomethingNeedsAttention(): void
    {
        $this->assertSame(
            ProbeResult::STATUS_WARN,
            BlogVaultProbe::statusFor(BlogVaultProbe::parse($this->raw()))
        );
    }

    public function testStatusIsOkForACleanSite(): void
    {
        $clean = [
            'linked'  => true,
            'scanner' => ['status' => 'clean', 'unresolved_count' => 0],
            'backups' => ['enabled' => true],
            'vulnerabilities_total' => 0,
        ];

        $this->assertSame(ProbeResult::STATUS_OK, BlogVaultProbe::statusFor($clean));
    }

    public function testCleanSiteDataHasNoRemediationKey(): void
    {
        // The base fixture has no scan_files etc — the trigger never fired
        // for this parse. Confirms parseScanner() does not fabricate an
        // empty remediation block when the six calls were never made.
        $this->assertArrayNotHasKey('remediation', BlogVaultProbe::parse($this->raw())['scanner']);
    }

    public function testInfectedFilePathIsSurfacedWhenPresent(): void
    {
        $remediation = BlogVaultProbe::parse($this->rawWithRemediation())['scanner']['remediation'];

        $this->assertCount(1, $remediation['files']);
        $this->assertSame('./wp-includes/class-wp-hook.php', $remediation['files'][0]['path']);
        $this->assertFalse($remediation['files'][0]['marked_safe']);
        $this->assertArrayNotHasKey('id', $remediation['files'][0]);
    }

    public function testEmptyDetectionKindsParseToEmptyLists(): void
    {
        $remediation = BlogVaultProbe::parse($this->rawWithRemediation())['scanner']['remediation'];

        $this->assertSame([], $remediation['scripts']);
        $this->assertSame([], $remediation['plugins']);
        $this->assertSame([], $remediation['cron_jobs']);
        $this->assertSame([], $remediation['redirections']);
    }

    public function testCleanSnapshotsExcludeHackedOnes(): void
    {
        // Fixture carries 3 snapshots: two scanned "hacked", one "clean".
        $clean = BlogVaultProbe::parse($this->rawWithRemediation())['scanner']['remediation']['clean_snapshots'];

        $this->assertCount(1, $clean);
        $this->assertSame('6a8870c6310c013362b31e1e', $clean[0]['id']);
    }

    public function testRemediationDetailIsOnlyFetchedWhenSomethingIsUnresolved(): void
    {
        $hacked = $this->raw();
        $this->assertTrue(
            (new \ReflectionMethod(BlogVaultProbe::class, 'needsRemediationDetail'))
                ->invoke(null, $hacked)
        );

        $clean = $hacked;
        $clean['site']['site']['security']['scanner']['status'] = 'clean';
        $clean['scan']['detections'] = array_map(
            static fn (array $c): array => ['total' => $c['total'], 'marked_safe' => $c['total']],
            $hacked['scan']['detections']
        );
        $this->assertFalse(
            (new \ReflectionMethod(BlogVaultProbe::class, 'needsRemediationDetail'))
                ->invoke(null, $clean)
        );
    }

    public function testStatusIsOkWhenTheSiteIsNotInTheAccount(): void
    {
        // Not every site we audit is under BlogVault management — that is a
        // normal outcome, not a probe failure.
        $this->assertSame(ProbeResult::STATUS_OK, BlogVaultProbe::statusFor(['linked' => false]));
    }

    public function testBackupsDisabledIsWarnEvenWhenClean(): void
    {
        $this->assertSame(ProbeResult::STATUS_WARN, BlogVaultProbe::statusFor([
            'linked'  => true,
            'scanner' => ['status' => 'clean', 'unresolved_count' => 0],
            'backups' => ['enabled' => false],
            'vulnerabilities_total' => 0,
        ]));
    }
}
