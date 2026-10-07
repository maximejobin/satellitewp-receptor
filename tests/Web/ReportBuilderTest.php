<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Rules\Pastille;
use SatelliteWP\Manager\Rules\RuleCatalog;
use SatelliteWP\Manager\Rules\Translator;
use SatelliteWP\Manager\Web\ReportBuilder;

final class ReportBuilderTest extends TestCase
{
    private function builder(string $locale = 'en'): ReportBuilder
    {
        $t = new Translator($locale, dirname(__DIR__, 2) . '/config/lang', 'en');

        return new ReportBuilder($t, 'https://manager.test/assets/report-icons');
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'payload' => [
                'wp_version'  => '6.6.1',
                'core_update' => ['available_version' => '6.7'],
                'plugins'     => [
                    // Deliberately out of alphabetical order, and out of
                    // vulnerability-data order, to prove both are resolved
                    // by the builder and not just "whatever order payload
                    // listed them in".
                    ['name' => 'Zzz Plugin', 'slug' => 'zzz/zzz.php', 'version' => '1.0', 'active' => true],
                    ['name' => 'Akismet', 'slug' => 'akismet/akismet.php', 'version' => '5.3', 'active' => true],
                    ['name' => 'Old Plugin', 'slug' => 'old/old.php', 'version' => '1.0', 'active' => false, 'new_version' => '2.0'],
                ],
                'themes' => [
                    // "Aaa Extra Theme" would sort first alphabetically —
                    // deliberately, to prove active-first/parent-second
                    // beats plain alphabetical order rather than agreeing
                    // with it by coincidence.
                    'extra'  => ['name' => 'Aaa Extra Theme', 'slug' => 'extra', 'version' => '0.9', 'active' => false],
                    'child'  => ['name' => 'Child Theme', 'slug' => 'child', 'version' => '1.0', 'template' => 'parent', 'active' => true],
                    'parent' => ['name' => 'Parent Theme', 'slug' => 'parent', 'version' => '2.0', 'new_version' => '2.1', 'active' => false],
                ],
                'mu_plugins'     => ['loader.php' => ['Name' => 'Loader', 'Version' => '1.0.0']],
                'dropin_plugins' => [
                    'advanced-cache.php'  => ['Name' => 'advanced-cache.php', 'Description' => ''],
                    'future-dropin.php'   => ['Name' => 'future-dropin.php', 'Description' => 'Vendor drop-in.'],
                ],
                'administrators' => [
                    ['id' => 1, 'login' => 'admin', 'email' => 'admin@example.com'],
                ],
                'super_admins' => [
                    ['id' => 2, 'login' => 'netadmin', 'email' => 'net@example.com'],
                ],
                'post_types' => ['post' => 'Posts', 'page' => 'Pages'],
                'post_type_count' => [
                    'post' => ['publish' => '10', 'draft' => '2', 'trash' => '1'],
                    'page' => ['publish' => '5'],
                ],
                'permalink_structure' => '/%postname%/',
                'connectors' => ['wpml' => ['default_language' => 'fr', 'active_languages' => ['fr', 'en']]],
                'is_multisite'   => true,
                'multisite_type' => 'subdomain',
                'database' => ['tables' => [
                    ['name' => 'wp_small', 'size_bytes' => 1024, 'row_count' => 3],
                    ['name' => 'wp_big', 'size_bytes' => 5 * 1024 * 1024, 'row_count' => 9000, 'overhead_bytes' => 1024 * 1024],
                    ['name' => 'wp_medium', 'size_bytes' => 2048, 'row_count' => 40],
                ]],
                'filesystem' => ['permissions' => [
                    'wp_config' => ['mode' => '0644', 'writable' => false, 'readable' => true],
                    'uploads_dir' => ['mode' => '0775', 'writable' => true, 'readable' => false],
                ]],
            ],
            'site' => ['site_url' => 'https://www.example.com'],
            'host' => 'www.example.com',
            'probe' => [
                'wordfence' => ['plugins' => ['items' => [
                    ['slug' => 'akismet', 'vulnerabilities' => [
                        ['cve_id' => 'CVE-2024-0001', 'title' => 'Test vuln', 'cvss_rating' => 'High', 'cvss_score' => 8.5, 'patched_versions' => ['5.4']],
                    ]],
                ]], 'themes' => ['items' => []], 'core' => []],
                'blogvault' => ['plugins' => ['items' => []], 'themes' => ['items' => []], 'core' => []],
                'http' => ['security_headers' => [
                    'strict-transport-security' => 'max-age=63072000',
                    'content-security-policy'   => null,
                    'x-content-type-options'    => 'nosniff',
                    'x-frame-options'           => null,
                    'referrer-policy'           => null,
                    'permissions-policy'        => null,
                ]],
                'pagespeed' => [
                    'desktop' => ['scores' => ['performance' => 95]],
                    'mobile'  => ['scores' => ['performance' => 68]],
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function findings(): array
    {
        return [
            ['id' => 'W1', 'category_code' => 'DOMAIN', 'pastille' => 'blue', 'severity' => 'Info', 'title' => 'T1', 'message' => 'M1'],
            ['id' => 'D1', 'category_code' => 'EMAIL', 'pastille' => 'orange', 'severity' => 'Moyenne', 'title' => 'T2', 'message' => 'M2'],
            ['id' => 'S1', 'category_code' => 'SSL', 'pastille' => 'green', 'severity' => 'Élevée', 'title' => 'T3', 'message' => 'M3'],
        ];
    }

    public function testValueFieldResolvesTransformsAndDefault(): void
    {
        $contract = ['fields' => [
            'wp_core_version' => ['type' => 'value', 'from' => 'payload.wp_version'],
            'missing'         => ['type' => 'value', 'from' => 'payload.nope', 'default' => '—'],
        ]];

        $report = $this->builder()->build($contract, $this->context(), []);

        self::assertSame(['type' => 'value', 'value' => '6.6.1', 'color' => null], $report['fields']['wp_core_version']);
        self::assertSame(['type' => 'value', 'value' => '—', 'color' => null], $report['fields']['missing']);
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function phpSizes(): iterable
    {
        yield 'megabytes fr'  => ['fr', '64M', '64 Mo'];
        yield 'megabytes en'  => ['en', '64M', '64 MB'];
        yield 'kilobytes'     => ['fr', '512K', '512 Ko'];
        yield 'gigabytes'     => ['fr', '2G', '2 Go'];
        yield 'lowercase'     => ['en', '128m', '128 MB'];
        yield 'plain bytes'   => ['en', '1048576', '1 MB'];
        yield 'unlimited -1'  => ['fr', '-1', 'Illimité'];
        yield 'unlimited 0'   => ['en', '0', 'Unlimited'];
    }

    #[DataProvider('phpSizes')]
    public function testPhpSizeShorthandIsReadable(string $locale, mixed $raw, string $expected): void
    {
        $contract = ['fields' => ['post_max' => ['type' => 'value', 'from' => 'payload.php.post_max_size', 'transform' => 'php_size']]];
        $context  = ['payload' => ['php' => ['post_max_size' => $raw]]];

        $value = $this->builder($locale)->build($contract, $context, [])['fields']['post_max']['value'];

        self::assertSame($expected, $value);
    }

    public function testValueFieldCarriesALiteralColorWhenGiven(): void
    {
        $contract = ['fields' => [
            'x' => ['type' => 'value', 'from' => 'host', 'color' => 'red'],
        ]];

        $report = $this->builder()->build($contract, $this->context(), []);

        self::assertSame('red', $report['fields']['x']['color']);
    }

    public function testPluginsTableIsAlphabeticalWithMergedVersionAndStatusIcons(): void
    {
        $contract = ['fields' => ['wp_plugins_list' => ['type' => 'table', 'source' => 'plugins']]];

        $report = $this->builder()->build($contract, $this->context(), []);
        $table  = $report['fields']['wp_plugins_list'];

        self::assertSame('table', $table['type']);
        self::assertSame(['Name', 'Version', 'Status'], $table['headers']);

        // Alphabetical (Akismet, Old Plugin, Zzz Plugin), not payload order
        // (Zzz Plugin, Akismet, Old Plugin).
        self::assertSame(['Akismet', 'Old Plugin', 'Zzz Plugin'], array_column(array_column($table['rows'], 0), 'text'));

        // Akismet: active, no update, one Wordfence-only vulnerability.
        self::assertSame(['text' => '5.3', 'color' => null], $table['rows'][0][1]);
        self::assertSame([
            'text'  => '',
            'color' => null,
            'icons' => [
                'https://manager.test/assets/report-icons/dot-green.png',
                'https://manager.test/assets/report-icons/vulnerable.png',
            ],
        ], $table['rows'][0][2]);

        // Old Plugin: inactive, update 1.0 → 2.0, no known vulnerability.
        self::assertSame(['text' => '1.0 → 2.0', 'color' => 'orange'], $table['rows'][1][1]);
        self::assertSame([
            'https://manager.test/assets/report-icons/dot-red.png',
            'https://manager.test/assets/report-icons/upgrade.png',
        ], $table['rows'][1][2]['icons']);
    }

    public function testPluginsTableAddsALicenseIconOnlyWhenOneApplies(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'plugins']]];
        $context  = $this->context();
        $context['licenses'] = [
            'plugin:akismet' => 'active',
            'plugin:old'     => 'missing',
            // 'plugin:zzz' intentionally absent — same as an explicit 'n_a'.
        ];

        $report = $this->builder()->build($contract, $context, []);
        $rows   = $report['fields']['x']['rows'];
        // rows are alphabetical: Akismet, Old Plugin, Zzz Plugin (see the order test above).

        self::assertStringContainsString('license-active.png', implode(' ', $rows[0][2]['icons']));
        self::assertStringContainsString('license-missing.png', implode(' ', $rows[1][2]['icons']));
        self::assertStringNotContainsString('license-', implode(' ', $rows[2][2]['icons'])); // no status recorded — no icon at all
    }

    public function testPluginsTableHeadersFollowTheRequestedLocale(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'plugins']]];

        $fr = $this->builder('fr')->build($contract, $this->context(), []);
        self::assertSame(['Nom', 'Version', 'Statut'], $fr['fields']['x']['headers']);

        $en = $this->builder('en')->build($contract, $this->context(), []);
        self::assertSame(['Name', 'Version', 'Status'], $en['fields']['x']['headers']);
    }

    public function testThemesTablePutsActiveThemeFirstAndItsParentSecond(): void
    {
        $contract = ['fields' => ['wp_themes_list' => ['type' => 'table', 'source' => 'themes']]];

        $report = $this->builder()->build($contract, $this->context(), []);
        $table  = $report['fields']['wp_themes_list'];

        self::assertSame(['Name', 'Version', 'Status'], $table['headers']);
        // Not alphabetical (Aaa Extra Theme, Child Theme, Parent Theme) —
        // active (Child Theme) leads, its parent (Parent Theme) follows,
        // the rest (Aaa Extra Theme) comes after despite sorting first.
        self::assertSame(['Child Theme', 'Parent Theme', 'Aaa Extra Theme'], array_column(array_column($table['rows'], 0), 'text'));

        self::assertSame(['text' => '1.0', 'color' => null], $table['rows'][0][1]); // active, no update offered
        self::assertSame(['text' => '2.0 → 2.1', 'color' => 'orange'], $table['rows'][1][1]); // parent, update offered
        self::assertSame(['https://manager.test/assets/report-icons/dot-green.png'], $table['rows'][0][2]['icons']);
        self::assertSame(
            ['https://manager.test/assets/report-icons/dot-red.png', 'https://manager.test/assets/report-icons/upgrade.png'],
            $table['rows'][1][2]['icons']
        );
    }

    public function testClientAndMaintenancePlanComeFromTheCrmSnapshot(): void
    {
        $contract = require dirname(__DIR__, 2) . '/config/reports/bilan-de-sante.php';
        $crm      = [
            'linked'           => true,
            'clients'          => [['id' => 1, 'label' => 'Acme Inc'], ['id' => 2, 'label' => 'Bob Builder']],
            'maintenance_plan' => ['subscription_id' => 1, 'name' => 'Care Gold', 'next_renewal' => '2027-01-01'],
        ];

        $fields = $this->builder()->build($contract, ['probe' => ['crm' => $crm]] + $this->context(), [])['fields'];
        self::assertSame('Acme Inc, Bob Builder', $fields['client']['value']);
        self::assertSame('Care Gold', $fields['maintenance_plan']['value']);

        $fields = $this->builder()->build($contract, ['probe' => ['crm' => ['linked' => false, 'reason' => 'website_not_found']]] + $this->context(), [])['fields'];
        self::assertSame('—', $fields['client']['value']);
        self::assertSame('—', $fields['maintenance_plan']['value']);
    }

    public function testAdministratorsTableShowsRoleAndIncludesSuperAdmins(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'administrators']]];

        $report = $this->builder()->build($contract, $this->context(), []);
        $table  = $report['fields']['x']['rows'];

        self::assertSame(['Login', 'Email', 'Role'], $report['fields']['x']['headers']);
        self::assertCount(2, $table); // one administrator + one super admin, not deduplicated
        self::assertSame(['admin', 'admin@example.com', 'Administrator'], array_column($table[0], 'text'));
        self::assertSame(['netadmin', 'net@example.com', 'Super admin'], array_column($table[1], 'text'));
    }

    public function testYesNoTransformHandlesTrueFalseAndUnknown(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'value', 'from' => 'probe.http.gzip', 'transform' => 'yes_no']]];

        $context = $this->context();
        $context['probe']['http']['gzip'] = true;
        self::assertSame('Yes', $this->builder()->build($contract, $context, [])['fields']['x']['value']);

        $context['probe']['http']['gzip'] = false;
        self::assertSame('No', $this->builder()->build($contract, $context, [])['fields']['x']['value']);

        unset($context['probe']['http']['gzip']);
        self::assertSame('Unknown', $this->builder()->build($contract, $context, [])['fields']['x']['value']);
    }

    public function testEolStatusLabelTransformHandlesTrueFalseAndUnknown(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'value', 'from' => 'reference.php_eol', 'transform' => 'eol_status_label']]];

        $context = $this->context();
        $context['reference']['php_eol'] = true;
        self::assertSame('End of life', $this->builder()->build($contract, $context, [])['fields']['x']['value']);

        $context['reference']['php_eol'] = false;
        self::assertSame('Supported', $this->builder()->build($contract, $context, [])['fields']['x']['value']);

        $context['reference']['php_eol'] = null;
        self::assertSame('Unknown', $this->builder()->build($contract, $context, [])['fields']['x']['value']);
    }

    public function testDatabaseStatusLabelIncludesTheDate(): void
    {
        $contract = ['fields' => ['x' => [
            'type' => 'value', 'from' => ['reference.database_eol', 'reference.database_eol_date'], 'transform' => 'database_status_label',
        ]]];

        $context = $this->context();
        $context['reference']['database_eol']      = false;
        $context['reference']['database_eol_date'] = '2027-07-01';
        self::assertSame('Supported until July 1, 2027', $this->builder()->build($contract, $context, [])['fields']['x']['value']);

        $context['reference']['database_eol'] = true;
        self::assertSame('Not supported since July 1, 2027', $this->builder()->build($contract, $context, [])['fields']['x']['value']);

        // No date on file — falls back to the plain status word rather than
        // an empty/broken "since " sentence.
        $context['reference']['database_eol_date'] = '';
        self::assertSame('End of life', $this->builder()->build($contract, $context, [])['fields']['x']['value']);

        $context['reference']['database_eol'] = null;
        self::assertSame('Unknown', $this->builder()->build($contract, $context, [])['fields']['x']['value']);
    }

    public function testEnabledLabelTransform(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'value', 'from' => 'probe.http.gzip', 'transform' => 'enabled_label']]];

        $context = $this->context();
        $context['probe']['http']['gzip'] = true;
        self::assertSame('Enabled', $this->builder()->build($contract, $context, [])['fields']['x']['value']);

        $context['probe']['http']['gzip'] = false;
        self::assertSame('Not enabled', $this->builder()->build($contract, $context, [])['fields']['x']['value']);
    }

    public function testJoinCommaTransform(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'value', 'from' => 'payload.php.extensions', 'transform' => 'join_comma']]];
        $context  = $this->context();
        $context['payload']['php'] = ['extensions' => ['curl', 'gd', 'mbstring']];

        self::assertSame('curl, gd, mbstring', $this->builder()->build($contract, $context, [])['fields']['x']['value']);
    }

    public function testColorTransformFollowsTheRawValueNotTheTranslatedLabel(): void
    {
        $contract = ['fields' => [
            'a' => ['type' => 'value', 'from' => 'probe.http.gzip', 'transform' => 'enabled_label', 'color_transform' => 'bool_green_red'],
        ]];

        $context = $this->context();
        $context['probe']['http']['gzip'] = true;
        self::assertSame('green', $this->builder()->build($contract, $context, [])['fields']['a']['color']);

        $context['probe']['http']['gzip'] = false;
        self::assertSame('red', $this->builder()->build($contract, $context, [])['fields']['a']['color']);

        // Never checked at all — no colour, not a false "red".
        unset($context['probe']['http']['gzip']);
        self::assertNull($this->builder()->build($contract, $context, [])['fields']['a']['color']);
    }

    public function testInstallTypeTransform(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'value', 'from' => ['payload.is_multisite', 'payload.multisite_type'], 'transform' => 'install_type']]];

        $multisite = $this->builder()->build($contract, $this->context(), []);
        self::assertSame('Multisite (subdomains)', $multisite['fields']['x']['value']);

        $context = $this->context();
        $context['payload']['is_multisite'] = false;
        $single = $this->builder()->build($contract, $context, []);
        self::assertSame('Single site', $single['fields']['x']['value']);
    }

    public function testActiveThemeVersionTransform(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme_version']]];

        $report = $this->builder()->build($contract, $this->context(), []);

        self::assertSame('1.0', $report['fields']['x']['value']); // Child Theme, the active one
    }

    public function testDatabaseTablesTableIsTopTenBySizeDescending(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'database_tables']]];

        $report = $this->builder()->build($contract, $this->context(), []);
        $rows   = $report['fields']['x']['rows'];

        self::assertSame(['Table', 'Size', 'Rows', 'Overhead'], $report['fields']['x']['headers']);
        self::assertSame(['wp_big', 'wp_medium', 'wp_small'], array_column(array_column($rows, 0), 'text'));
        self::assertSame('5 MB', $rows[0][1]['text']);
        self::assertSame(['text' => '1 MB', 'color' => 'orange'], $rows[0][3]); // wp_big has overhead — worth attention
        self::assertSame(['text' => '0 B', 'color' => null], $rows[1][3]); // wp_medium has none
    }

    public function testFilePermissionsFlagOnlyUnexpectedWritablePaths(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'file_permissions']]];
        $context  = $this->context();
        $context['payload']['filesystem']['permissions']['index'] = ['mode' => '0666', 'writable' => true, 'readable' => true];

        $report = $this->builder()->build($contract, $context, []);
        $rows   = $report['fields']['x']['rows'];

        self::assertSame(['Location', 'Mode', 'Writable'], $report['fields']['x']['headers']);
        self::assertSame(['text' => 'No', 'color' => 'green'], $rows[0][2]);   // wp-config.php locked down
        self::assertSame(['text' => 'Yes', 'color' => null], $rows[1][2]);     // uploads must be writable
        self::assertSame(['text' => 'Yes', 'color' => 'orange'], $rows[2][2]); // index.php should not be
    }

    public function testSecurityHeadersTableUsesAFixedOrderAndFlagsMissing(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'security_headers']]];

        $report = $this->builder()->build($contract, $this->context(), []);
        $rows   = $report['fields']['x']['rows'];

        self::assertSame('HSTS', $rows[0][0]['text']);
        self::assertSame(['text' => 'max-age=63072000', 'color' => 'green'], $rows[0][1]);
        self::assertSame('Content-Security-Policy', $rows[1][0]['text']);
        self::assertSame(['text' => 'Missing', 'color' => 'orange'], $rows[1][1]);
    }

    public function testSecurityHeaderSentEmptyCountsAsMissing(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'security_headers']]];
        $context  = $this->context();
        $context['probe']['http']['security_headers']['x-content-type-options'] = '  ';

        $rows = $this->builder()->build($contract, $context, [])['fields']['x']['rows'];

        self::assertSame(['text' => 'Missing', 'color' => 'orange'], $rows[2][1]);
    }

    /** @return iterable<string, array{array<string, mixed>|null}> */
    public static function unreadableHomepages(): iterable
    {
        yield 'probe did not run'      => [null];
        yield 'main request failed'    => [['auth' => ['required' => false]]];
        yield 'behind Basic auth'      => [['status_code' => 401, 'auth' => ['required' => true], 'security_headers' => ['strict-transport-security' => null]]];
    }

    /** @param array<string, mixed>|null $http */
    #[DataProvider('unreadableHomepages')]
    public function testSecurityHeadersAreUndeterminedWhenTheHomepageWasNotRead(?array $http): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'security_headers']]];
        $context  = $this->context();
        if ($http === null) {
            unset($context['probe']['http']);
        } else {
            $context['probe']['http'] = $http;
        }

        $rows = $this->builder('fr')->build($contract, $context, [])['fields']['x']['rows'];

        self::assertCount(6, $rows);
        foreach ($rows as $row) {
            self::assertSame(['text' => 'Indéterminé', 'color' => null], $row[1]);
        }
    }

    public function testIgnoredVulnerabilityShowsNoVulnerableIconInTheComponentTable(): void
    {
        $t       = new Translator('en', dirname(__DIR__, 2) . '/config/lang', 'en');
        $builder = new ReportBuilder($t, 'https://manager.test/assets/report-icons', ['wf-0001']);
        $context = $this->context();
        $context['probe']['wordfence']['plugins']['items'][0]['vulnerabilities'][0]['id'] = 'WF-0001';

        $rows = $builder->build(['fields' => ['x' => ['type' => 'table', 'source' => 'plugins']]], $context, [])['fields']['x']['rows'];

        self::assertSame(['https://manager.test/assets/report-icons/dot-green.png'], $rows[0][2]['icons']); // Akismet
    }

    /** @return iterable<string, array{string}> */
    public static function hostileNames(): iterable
    {
        yield 'image shortcode' => ['X [img url="https://attacker.example/p.png"]'];
        yield 'link'            => ['[Mettre à jour](https://phish.example)'];
        yield 'bold'            => ['**x**'];
        yield 'italic'          => ['_x_'];
    }

    #[DataProvider('hostileNames')]
    public function testSiteControlledNamesNeverReachTheDocAsMarkup(string $name): void
    {
        $context = $this->context();
        $context['payload']['plugins'][0]['name'] = $name;
        $findings = [[
            'id' => 'F13', 'category_code' => 'X', 'pastille' => 'orange', 'severity' => 'Medium',
            'title' => 'Inactive', 'message' => "Unused: {$name}.",
        ]];
        $contract = ['fields' => [
            'list' => ['type' => 'table', 'source' => 'plugins'],
            'obs'  => ['type' => 'observations', 'categories' => ['X']],
        ]];

        $report = $this->builder()->build($contract, $context, $findings);

        // The message's markup characters are backslash-escaped for the script to render literally.
        $message = $report['fields']['obs']['items'][0]['message'];
        self::assertSame('Unused: ' . ReportBuilder::escapeMarkup($name) . '.', $message);
        self::assertDoesNotMatchRegularExpression('/(?<!\\\\)[\[\]*_]/', $message);

        // A table cell is plain text: the icons travel apart, so a name can't summon an image.
        $cells = array_merge(...$report['fields']['list']['rows']);
        self::assertContains($name, array_column($cells, 'text'));
        foreach ($cells as $cell) {
            foreach ($cell['icons'] ?? [] as $url) {
                self::assertStringStartsWith('https://manager.test/assets/report-icons/', $url);
            }
        }
    }

    public function testEscapeMarkupCoversEveryMarkupCharacterAndTheBackslash(): void
    {
        self::assertSame('a\\*\\*b\\*\\* \\_c\\_ \\[d\\](https://e) f\\\\g', ReportBuilder::escapeMarkup('a**b** _c_ [d](https://e) f\\g'));
    }

    public function testAnalystObservationKeepsItsFormattingWhileFindingsAreEscaped(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'observations', 'categories' => ['X']]]];
        $findings = [['id' => 'A', 'category_code' => 'X', 'pastille' => 'red', 'title' => 'WP_DEBUG', 'message' => '**x**']];
        $manual   = [[
            'section' => 'x', 'color' => 'red', 'include' => true,
            'title'   => 'Voir _ceci_', 'description' => '**Important** : [le guide](https://example.com/guide)',
        ]];

        $items = $this->builder()->build($contract, $this->context(), $findings, $manual)['fields']['x']['items'];

        self::assertSame('WP\\_DEBUG', $items[0]['title']);
        self::assertSame('\\*\\*x\\*\\*', $items[0]['message']);
        self::assertSame('Voir _ceci_', $items[1]['title']);
        self::assertSame('**Important** : [le guide](https://example.com/guide)', $items[1]['message']);
    }

    public function testVulnerabilitiesTableAggregatesCoreAndComponents(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'vulnerabilities']]];

        $report = $this->builder()->build($contract, $this->context(), []);
        $table  = $report['fields']['x'];

        self::assertSame(['Vulnerability', 'CVSS', 'Fix'], $table['headers']);

        $rows = $table['rows'];
        self::assertCount(1, $rows); // Akismet's Wordfence-only CVE — nothing else in the fixture has one
        self::assertSame('Test vuln', $rows[0][0]['text']);
        self::assertSame(['text' => '8.5', 'color' => 'red'], $rows[0][1]); // High rating
        self::assertSame(['text' => 'Available', 'color' => 'green'], $rows[0][2]); // patched_versions: ['5.4']
    }

    public function testVulnerabilitiesFixColumnIsRedWhenNoPatchIsKnown(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'vulnerabilities']]];
        $context  = $this->context();
        $context['probe']['wordfence']['plugins']['items'][0]['vulnerabilities'][0]['patched_versions'] = [];

        $report = $this->builder()->build($contract, $context, []);

        self::assertSame(['text' => 'Not available', 'color' => 'red'], $report['fields']['x']['rows'][0][2]);
    }

    public function testPerformanceScoresResolveFromThePagespeedProbe(): void
    {
        $contract = ['fields' => [
            'desktop' => ['type' => 'value', 'from' => 'probe.pagespeed.desktop.scores.performance'],
            'mobile'  => ['type' => 'value', 'from' => 'probe.pagespeed.mobile.scores.performance'],
        ]];

        $report = $this->builder()->build($contract, $this->context(), []);

        self::assertSame('95', $report['fields']['desktop']['value']);
        self::assertSame('68', $report['fields']['mobile']['value']);
    }

    public function testUnknownTableSourceIsSkippedRatherThanErroring(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'nope']]];

        $report = $this->builder()->build($contract, $this->context(), []);

        self::assertArrayNotHasKey('x', $report['fields']);
    }

    public function testObservationsFieldUnionsCategoriesAndExplicitIds(): void
    {
        $contract = ['fields' => [
            'domain_observations' => ['type' => 'observations', 'categories' => ['DOMAIN'], 'ids' => ['D1']],
        ]];

        $report = $this->builder()->build($contract, $this->context(), $this->findings());
        $items  = $report['fields']['domain_observations']['items'];

        self::assertCount(2, $items); // W1 (category DOMAIN) + D1 (explicit id, category EMAIL)
        self::assertSame(['title' => 'T1', 'message' => 'M1', 'color' => 'blue', 'severity' => 'Info'], $items[0]);
        self::assertSame(['title' => 'T2', 'message' => 'M2', 'color' => 'orange', 'severity' => 'Moyenne'], $items[1]);
    }

    public function testObservationsFieldOrdersBlueThenRedThenOrangeThenGreen(): void
    {
        $findings = [
            ['id' => 'A', 'category_code' => 'X', 'pastille' => 'green', 'title' => 'green', 'message' => ''],
            ['id' => 'B', 'category_code' => 'X', 'pastille' => 'red', 'title' => 'red', 'message' => ''],
            ['id' => 'C', 'category_code' => 'X', 'pastille' => 'orange', 'title' => 'orange', 'message' => ''],
            ['id' => 'D', 'category_code' => 'X', 'pastille' => 'blue', 'title' => 'blue', 'message' => ''],
        ];
        $contract = ['fields' => ['x' => ['type' => 'observations', 'categories' => ['X']]]];

        $report = $this->builder()->build($contract, $this->context(), $findings);
        $titles = array_column($report['fields']['x']['items'], 'title');

        self::assertSame(['blue', 'red', 'orange', 'green'], $titles);
    }

    public function testManualObservationJoinsTheMatchingSectionOnly(): void
    {
        $contract = ['fields' => [
            'domain_observations'    => ['type' => 'observations', 'categories' => ['DOMAIN']],
            'security_observations'  => ['type' => 'observations', 'categories' => ['SECURITY']],
        ]];
        $manual = [
            ['id' => 'x1', 'section' => 'domain_observations', 'color' => 'red', 'title' => 'Manual', 'description' => 'Hand-typed', 'include' => true],
        ];

        $report = $this->builder()->build($contract, $this->context(), $this->findings(), $manual);

        self::assertCount(2, $report['fields']['domain_observations']['items']); // W1 (rule) + the manual one
        self::assertCount(0, $report['fields']['security_observations']['items']); // different section — untouched
    }

    public function testManualObservationCarriesTheTranslatedPastilleAsItsSeverity(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'observations', 'categories' => []]]];
        $manual   = [['id' => 'x1', 'section' => 'x', 'color' => 'red', 'title' => 'T', 'description' => 'D', 'include' => true]];

        $report = $this->builder('fr')->build($contract, $this->context(), [], $manual);

        self::assertSame(['title' => 'T', 'message' => 'D', 'color' => 'red', 'severity' => 'Critique'], $report['fields']['x']['items'][0]);
    }

    public function testManualObservationExcludedWhenIncludeIsFalse(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'observations', 'categories' => []]]];
        $manual   = [['id' => 'x1', 'section' => 'x', 'color' => 'red', 'title' => 'T', 'description' => 'D', 'include' => false]];

        $report = $this->builder()->build($contract, $this->context(), [], $manual);

        self::assertSame([], $report['fields']['x']['items']);
    }

    public function testManualObservationWithAnUnknownColorFallsBackToGrey(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'observations', 'categories' => []]]];
        $manual   = [['id' => 'x1', 'section' => 'x', 'color' => 'magenta', 'title' => 'T', 'description' => 'D', 'include' => true]];

        $report = $this->builder()->build($contract, $this->context(), [], $manual);

        self::assertSame('grey', $report['fields']['x']['items'][0]['color']);
    }

    public function testNoCategoriesOrIdsMatchesEveryFinding(): void
    {
        // Neither restricts the topic — "Enjeux"-style: the only filter
        // that matters for this field is colour, applied separately below.
        $contract = ['fields' => ['x' => ['type' => 'observations']]];

        $report = $this->builder()->build($contract, $this->context(), $this->findings());

        self::assertCount(3, $report['fields']['x']['items']); // every finding() fixture entry, regardless of category
    }

    public function testColorsFilterNarrowsRegardlessOfTopic(): void
    {
        $contract = ['fields' => [
            'enjeux'        => ['type' => 'observations', 'colors' => ['red', 'orange']],
            'bonne_pratique' => ['type' => 'observations', 'colors' => ['green']],
        ]];

        $report = $this->builder()->build($contract, $this->context(), $this->findings());

        // findings() fixture: W1=blue, D1=orange, S1=green — spans DOMAIN/EMAIL/SSL.
        self::assertSame(['T2'], array_column($report['fields']['enjeux']['items'], 'title'));
        self::assertSame(['T3'], array_column($report['fields']['bonne_pratique']['items'], 'title'));
    }

    public function testColorsFilterAlsoAppliesToManualObservations(): void
    {
        $contract = ['fields' => ['enjeux' => ['type' => 'observations', 'colors' => ['red', 'orange']]]];
        $manual = [
            ['id' => 'm1', 'section' => 'enjeux', 'color' => 'red', 'title' => 'Kept', 'description' => 'D', 'include' => true],
            ['id' => 'm2', 'section' => 'enjeux', 'color' => 'green', 'title' => 'Dropped', 'description' => 'D', 'include' => true],
        ];

        $report = $this->builder()->build($contract, $this->context(), [], $manual);

        self::assertSame(['Kept'], array_column($report['fields']['enjeux']['items'], 'title'));
    }

    public function testUnknownFieldTypeIsSkipped(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'mystery']]];

        $report = $this->builder()->build($contract, $this->context(), []);

        self::assertSame([], $report['fields']);
    }

    public function testLatestWpVersionReadsFromTheReferenceContextNotThePayload(): void
    {
        $contract = ['fields' => [
            'latest' => ['type' => 'value', 'from' => 'reference.wordpress_latest_version', 'default' => '—'],
        ]];
        $context = $this->context();
        $context['reference'] = ['wordpress_latest_version' => '6.8'];

        $report = $this->builder()->build($contract, $context, []);

        // 6.7 sits in payload.core_update.available_version (the site's own
        // self-report) — must NOT win over the reference cache's 6.8.
        self::assertSame('6.8', $report['fields']['latest']['value']);
    }

    public function testActiveThemeAndItsParentAreResolvedFromThePlainThemesMap(): void
    {
        $contract = ['fields' => [
            'theme'  => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme'],
            'parent' => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme_parent'],
        ]];

        $report = $this->builder()->build($contract, $this->context(), []);

        self::assertSame('Child Theme', $report['fields']['theme']['value']);
        self::assertSame('Parent Theme', $report['fields']['parent']['value']);
    }

    public function testActiveThemeParentIsEmptyForAThemeWithNoTemplate(): void
    {
        $context = $this->context();
        unset($context['payload']['themes']['child']);
        $context['payload']['themes']['parent']['active'] = true;
        $contract = ['fields' => [
            'parent' => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme_parent', 'default' => '—'],
        ]];

        $report = $this->builder()->build($contract, $context, []);

        self::assertSame('—', $report['fields']['parent']['value']);
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function tableSourceProvider(): array
    {
        return [
            'mu_plugins'     => ['mu_plugins', ['Name', 'Version']],
            'dropins'        => ['dropins', ['File', 'Description']],
            'content_types'  => ['content_types', ['Type', 'Total', 'Published', 'Drafts', 'Trash']],
            'settings'       => ['settings', ['Setting', 'Value']],
        ];
    }

    /** @param list<string> $expectedHeaders */
    #[DataProvider('tableSourceProvider')]
    public function testEachNewTableSourceProducesItsExpectedHeaders(string $source, array $expectedHeaders): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => $source]]];

        $report = $this->builder()->build($contract, $this->context(), []);

        self::assertSame($expectedHeaders, $report['fields']['x']['headers']);
        self::assertNotSame([], $report['fields']['x']['rows']);
    }

    public function testMuPluginsAndDropinsTablesCarryNoActiveStatusColumn(): void
    {
        $contract = ['fields' => [
            'mu'      => ['type' => 'table', 'source' => 'mu_plugins'],
            'dropins' => ['type' => 'table', 'source' => 'dropins'],
        ]];

        $report = $this->builder()->build($contract, $this->context(), []);

        self::assertSame([['text' => 'Loader', 'color' => null], ['text' => '1.0.0', 'color' => null]], $report['fields']['mu']['rows'][0]);
        // The role comes from the catalogue; a file it does not know falls back to its own header.
        self::assertSame(
            [['text' => 'advanced-cache.php', 'color' => null], ['text' => 'Page cache: serves stored pages before WordPress loads (needs WP_CACHE).', 'color' => null]],
            $report['fields']['dropins']['rows'][0]
        );
        self::assertSame('Vendor drop-in.', $report['fields']['dropins']['rows'][1][1]['text']);
    }

    public function testContentTypesTableSumsCountsAcrossStatuses(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'content_types']]];

        $report = $this->builder()->build($contract, $this->context(), []);
        $rows   = $report['fields']['x']['rows'];

        self::assertSame('Posts', $rows[0][0]['text']);
        self::assertSame('13', $rows[0][1]['text']); // 10 + 2 + 1
        self::assertSame('Pages', $rows[1][0]['text']);
        self::assertSame('5', $rows[1][1]['text']);
    }

    public function testSettingsTableReadsPolylangAndTranslatePressLocales(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'settings']]];

        foreach (['polylang' => ['fr', 'en'], 'translatepress' => ['fr_CA', 'en_US']] as $key => $codes) {
            $context = $this->context();
            $context['payload']['connectors'] = [$key => ['default_language' => $codes[0], 'active_languages' => $codes]];

            $rows = $this->builder()->build($contract, $context, [])['fields']['x']['rows'];

            self::assertCount(4, $rows, $key);
            self::assertSame('French, English', $rows[3][1]['text'], $key);
        }
    }

    public function testSettingsTableIncludesWpmlOnlyWhenPresent(): void
    {
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'settings']]];

        $report = $this->builder()->build($contract, $this->context(), []);
        self::assertCount(4, $report['fields']['x']['rows']); // Admin email + Permalinks + 2 WPML rows
        self::assertSame('French, English', $report['fields']['x']['rows'][3][1]['text']);

        $context = $this->context();
        unset($context['payload']['connectors']);
        $report = $this->builder()->build($contract, $context, []);
        self::assertCount(2, $report['fields']['x']['rows']); // Admin email + Permalinks only
    }

    /** WordPress fills 'template' with a non-child theme's OWN slug — that is not a parent. */
    public function testANonChildThemeWhoseTemplateIsItsOwnSlugHasNoParent(): void
    {
        $context = $this->context();
        $context['payload']['themes'] = [
            'astra' => ['name' => 'Astra', 'slug' => 'astra', 'version' => '4.0', 'template' => 'astra', 'active' => true],
            'extra' => ['name' => 'Aaa Extra Theme', 'slug' => 'extra', 'version' => '0.9', 'template' => 'extra', 'active' => false],
        ];
        $contract = ['fields' => [
            'parent' => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme_parent', 'default' => '—'],
            'list'   => ['type' => 'table', 'source' => 'themes'],
        ]];

        $report = $this->builder()->build($contract, $context, []);

        self::assertSame('—', $report['fields']['parent']['value']);
        // Active first, then the rest alphabetically — no fake "parent" slot.
        self::assertSame(['Astra', 'Aaa Extra Theme'], array_map(static fn (array $r): string => $r[0]['text'], $report['fields']['list']['rows']));
    }

    public function testParentSlugFromThePluginWinsOverTemplate(): void
    {
        $context = $this->context();
        $context['payload']['themes']['child']['template']    = 'child';
        $context['payload']['themes']['child']['parent_slug'] = 'parent';
        $contract = ['fields' => [
            'parent' => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme_parent'],
        ]];

        $report = $this->builder()->build($contract, $context, []);

        self::assertSame('Parent Theme', $report['fields']['parent']['value']);
    }

    public function testSmallTablesAreTranslatedLikeEveryOtherTable(): void
    {
        $contract = ['fields' => [
            'settings' => ['type' => 'table', 'source' => 'settings'],
            'content'  => ['type' => 'table', 'source' => 'content_types'],
            'mu'       => ['type' => 'table', 'source' => 'mu_plugins'],
            'dropins'  => ['type' => 'table', 'source' => 'dropins'],
        ]];

        $report = $this->builder('fr')->build($contract, $this->context(), []);

        self::assertSame(['Réglage', 'Valeur'], $report['fields']['settings']['headers']);
        self::assertSame('Permaliens', $report['fields']['settings']['rows'][1][0]['text']);
        self::assertSame(['Type', 'Total', 'Publiés', 'Brouillons', 'Corbeille'], $report['fields']['content']['headers']);
        self::assertSame(['Nom', 'Version'], $report['fields']['mu']['headers']);
        self::assertSame(['Fichier', 'Description'], $report['fields']['dropins']['headers']);
    }

    /** @param array<string, mixed> $spec */
    private function value(array $spec, mixed $raw, string $locale = 'fr'): array
    {
        $context = $this->context();
        $context['v'] = $raw;
        $spec['type'] = 'value';
        $spec['from'] ??= 'v';

        return $this->builder($locale)->build(['fields' => ['x' => $spec]], $context, [])['fields']['x'];
    }

    public function testDatesAreLongAndLocalized(): void
    {
        self::assertSame('5 septembre 2008', $this->value(['transform' => 'date'], '2008-09-05T21:02:24Z')['value']);
        self::assertSame('1er juillet 2027', $this->value(['transform' => 'date'], '2027-07-01')['value']);
        self::assertSame('September 5, 2008', $this->value(['transform' => 'date'], '2008-09-05', 'en')['value']);
        self::assertSame('—', $this->value(['transform' => 'date', 'default' => '—'], null)['value']);
    }

    public function testDatabaseStatusCarriesALocalizedDateAndEolColour(): void
    {
        $field = $this->value(['transform' => 'database_status_label', 'color_transform' => 'eol_red_green'], [true, '2026-07-06']);

        self::assertSame('Plus prise en charge depuis le 6 juillet 2026', $field['value']);
        self::assertSame('red', $field['color']);
        self::assertSame('green', $this->value(['transform' => 'eol_status_label', 'color_transform' => 'eol_red_green'], false)['color']);
        self::assertNull($this->value(['transform' => 'eol_status_label', 'color_transform' => 'eol_red_green'], null)['color']);
    }

    public function testDatabaseLabelsAreReadable(): void
    {
        self::assertSame('MariaDB 10.6.22', $this->value(['transform' => 'database_label'], ['mariadb', '10.6.22-MariaDB-0ubuntu0.22.04.1-log'])['value']);
        self::assertSame('MySQL', $this->value(['transform' => 'database_type'], 'mysql')['value']);
        self::assertSame('8.4.7', $this->value(['transform' => 'database_version'], '8.4.7')['value']);
    }

    public function testCoreAutoUpdateFollowsTheConstantsLikeRuleF12(): void
    {
        $policy = fn (array $constants): string => $this->value(['transform' => 'auto_update_core'], $constants + [
            'WP_AUTO_UPDATE_CORE' => 'N/A', 'AUTOMATIC_UPDATER_DISABLED' => 'N/A', 'DISALLOW_FILE_MODS' => 'N/A',
        ])['value'];

        self::assertSame('Désactivée', $policy(['WP_AUTO_UPDATE_CORE' => false]));
        self::assertSame('Mineures seulement', $policy(['WP_AUTO_UPDATE_CORE' => 'minor']));
        self::assertSame('Toutes', $policy(['WP_AUTO_UPDATE_CORE' => true]));
        // Not defined: WordPress's default policy, minor releases only — never "unknown".
        self::assertSame('Mineures seulement', $policy([]));
        self::assertSame('Désactivée', $policy(['WP_AUTO_UPDATE_CORE' => true, 'AUTOMATIC_UPDATER_DISABLED' => true]));
        self::assertSame('Désactivée', $policy(['DISALLOW_FILE_MODS' => true]));
        self::assertSame('Indéterminé', $this->value(['transform' => 'auto_update_core'], null)['value']);
    }

    public function testShippedContractReadsCoreAutoUpdateFromTheConstants(): void
    {
        $contract = require dirname(__DIR__, 2) . '/config/reports/bilan-de-sante.php';
        $context  = ['payload' => ['constants' => ['WP_AUTO_UPDATE_CORE' => 'N/A'], 'core_update' => ['auto_update_core' => null]]];

        $field = $this->builder('fr')->build(['fields' => ['x' => $contract['fields']['wp_core_auto_update']]], $context, [])['fields']['x'];

        self::assertSame('Mineures seulement', $field['value']);
    }

    public function testLighthouseScoreIsOutOfAHundredAndBanded(): void
    {
        $spec = ['transform' => 'score_100', 'color_transform' => 'lighthouse', 'default' => '—'];

        self::assertSame(['type' => 'value', 'value' => '74/100', 'color' => 'orange'], $this->value($spec, 74));
        self::assertSame('green', $this->value($spec, 90)['color']);
        self::assertSame('red', $this->value($spec, 37)['color']);
        self::assertSame(['type' => 'value', 'value' => '—', 'color' => null], $this->value($spec, null));
    }

    public function testWordpressStatusLabelAndColour(): void
    {
        $spec = ['transform' => 'wordpress_status_label', 'color_transform' => 'wordpress_status_color'];

        self::assertSame(['type' => 'value', 'value' => 'Non sécurisée — mise à jour urgente', 'color' => 'red'], $this->value($spec, 'insecure'));
        self::assertSame(['type' => 'value', 'value' => 'À jour', 'color' => 'green'], $this->value($spec, 'latest'));
        self::assertSame(['type' => 'value', 'value' => 'Mise à jour disponible', 'color' => 'orange'], $this->value($spec, ''));
        self::assertSame(['type' => 'value', 'value' => 'Indéterminé', 'color' => null], $this->value($spec, null));
    }

    public function testDefaultLabelIsTranslatedAndCountCountsTheList(): void
    {
        self::assertSame('Aucune', $this->value(['transform' => 'join_lines', 'default_label' => 'none_f'], [])['value']);
        self::assertSame('2', $this->value(['transform' => 'count'], [['login' => 'a'], ['login' => 'b']])['value']);
        self::assertSame('0', $this->value(['transform' => 'count'], null)['value']);
    }

    public function testNumbersAndBytesFollowTheLocale(): void
    {
        $context = $this->context();
        $context['payload']['database']['tables'] = [['name' => 'wp_posts', 'size_bytes' => 54_630_000, 'row_count' => 3_297_779]];
        $contract = ['fields' => ['x' => ['type' => 'table', 'source' => 'database_tables']]];

        $fr = $this->builder('fr')->build($contract, $context, [])['fields']['x']['rows'][0];
        self::assertSame('52,1 Mo', $fr[1]['text']);
        self::assertSame("3\u{202F}297\u{202F}779", $fr[2]['text']);

        $en = $this->builder('en')->build($contract, $context, [])['fields']['x']['rows'][0];
        self::assertSame('52.1 MB', $en[1]['text']);
        self::assertSame('3,297,779', $en[2]['text']);
    }

    public function testContentTypesHideWordpressAndPluginMachinery(): void
    {
        $context = $this->context();
        $context['payload']['post_types'] = array_combine(
            $slugs = ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'acf-field', 'wpcf7_contact_form', 'temoignage', 'guide'],
            $slugs
        );
        $context['payload']['post_type_count'] = [
            'post'       => ['publish' => '481', 'draft' => '5'],
            'page'       => ['publish' => '32'],
            'attachment' => ['inherit' => '1925'],
            'revision'   => ['inherit' => '4928'],
            'acf-field'  => ['publish' => '19'],
            'wpcf7_contact_form' => ['publish' => '6'],
            'temoignage' => ['publish' => '11'],
            'guide'      => ['publish' => 0],
        ];

        $rows = $this->builder('fr')->build(['fields' => ['x' => ['type' => 'table', 'source' => 'content_types']]], $context, [])['fields']['x']['rows'];

        self::assertSame(['Articles', 'Pages', 'Médias', 'Temoignage'], array_column(array_column($rows, 0), 'text'));
        self::assertSame("1\u{202F}925", $rows[2][2]['text']); // media count under "published"
    }

    public function testConstantsTableFlagsOnlyDebugOutputLeaks(): void
    {
        $context = $this->context();
        $context['payload']['constants'] = ['WP_DEBUG' => true, 'WP_DEBUG_LOG' => true, 'DISALLOW_FILE_EDIT' => false, 'MULTISITE' => 'N/A'];

        $rows = $this->builder()->build(['fields' => ['x' => ['type' => 'table', 'source' => 'constants']]], $context, [])['fields']['x']['rows'];

        self::assertSame([['text' => 'WP_DEBUG', 'color' => null], ['text' => 'true', 'color' => 'red']], $rows[0]);
        self::assertSame(['text' => 'true', 'color' => null], $rows[1][1]);
        self::assertSame(['text' => 'false', 'color' => null], $rows[2][1]);
        self::assertSame(['text' => 'N/A', 'color' => null], $rows[3][1]);
    }

    public function testObservationReadingOrderCoversEveryPastille(): void
    {
        self::assertEqualsCanonicalizing(Pastille::values(), array_keys(ReportBuilder::OBSERVATION_COLOR_ORDER));
    }

    public function testEveryCatalogueRuleLandsInATopicSectionOfTheReport(): void
    {
        $root     = dirname(__DIR__, 2);
        $contract = require $root . '/config/reports/bilan-de-sante.php';

        $categories = [];
        $ids        = [];
        foreach ($contract['fields'] as $name => $spec) {
            if (($spec['type'] ?? null) !== 'observations' || $name === 'all_observations') {
                continue;
            }
            $categories = array_merge($categories, (array) ($spec['categories'] ?? []));
            $ids        = array_merge($ids, (array) ($spec['ids'] ?? []));
        }

        $orphans = [];
        foreach (RuleCatalog::load($root . '/config/rules.php') as $rule) {
            if (!in_array($rule->category, $categories, true) && !in_array($rule->id, $ids, true)) {
                $orphans[] = $rule->id;
            }
        }

        self::assertSame([], $orphans, 'rules shown nowhere but {{all_observations}}');
    }

    public function testTheShippedContractResolvesWithoutGaps(): void
    {
        $contract = require dirname(__DIR__, 2) . '/config/reports/bilan-de-sante.php';

        $report = $this->builder('fr')->build($contract, $this->context(), $this->findings());

        self::assertSame(array_keys($contract['fields']), array_keys($report['fields']));
    }
}
