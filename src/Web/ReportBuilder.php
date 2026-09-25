<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Web;

use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Rules\Translator;

require_once __DIR__ . '/helpers.php';

/**
 * Resolves one report contract (config/reports/*.php — see that file's own
 * docblock for the shape) against one extraction's data context into the
 * JSON a report template's script consumes. Knows nothing about "bilan de
 * santé", Google Docs, or HTTP — a value transform, a table shape, and a
 * category filter are all it understands. Router::extractionReportJson()
 * owns fetching the data and the contract file; this class only resolves
 * one against the other, so a second report type never means touching it.
 *
 * Every resolved field carries an explicit 'type' ('value' | 'table' |
 * 'observations') and, where it makes sense, a 'color' — so the report
 * script has exactly one generic dispatcher keyed off 'type', never a
 * separate hardcoded loop per kind and never a per-{{variable}} special
 * case. Adding a new field kind (a new way to show something on a report)
 * means a new case in build()/a new private buildXField() method here plus
 * a matching renderer in the script — never touching a variable name.
 */
final class ReportBuilder
{
    // Display order for an 'observations' field's items — informational
    // first, then worst-to-best, matching the order the real report
    // template reads them in. Not Pastille's own declaration order (which
    // is severity-derived, green/orange/red/blue/grey): this is purely a
    // reading-order choice for this one field kind, so it lives here, not
    // on the enum. An unlisted colour (there is none today) sorts last.
    private const OBSERVATION_COLOR_ORDER = ['blue' => 0, 'red' => 1, 'orange' => 2, 'green' => 3, 'grey' => 4];

    /** @var array<string, callable(mixed): string> */
    private array $transforms;

    /**
     * Named, reusable "what colour does this raw value mean" functions —
     * the 'value' counterpart to a table cell's own colour, named by a
     * field's optional 'color_transform' and run against the SAME raw
     * value 'transform' formats (never the already-translated text, which
     * would break the moment a locale's label changed).
     *
     * @var array<string, callable(mixed): ?string>
     */
    private array $colorTransforms;

    /** @var array<string, callable(array<string, mixed>): array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}> */
    private array $tableBuilders;

    /**
     * @param Translator $t           renders report-only labels (config/lang/*.php's
     *                                 'report' block) in the requested locale —
     *                                 never the rules/findings sentences, those
     *                                 are already translated before reaching here
     * @param string     $iconBaseUrl absolute base URL for the status-column
     *                                 icons (e.g. ".../assets/report-icons"),
     *                                 built by Router from the live request so
     *                                 dev/prod never need a hardcoded host
     */
    public function __construct(
        private readonly Translator $t,
        private readonly string $iconBaseUrl = '',
    ) {
        $this->transforms = [
            'site_display' => static fn (mixed $v): string => site_display((string) ($v ?? '')),
            // Xtractor stores every timestamp as ISO 8601 ("…T14:35:39Z") —
            // this is the one place any report contract truncates to a
            // plain date, so nothing downstream has to reformat per value.
            'date_ymd' => static fn (mixed $v): string => is_string($v) && $v !== '' ? substr($v, 0, 10) : '',
            // One value per line, not comma-separated — a real line break
            // once pasted, for a short list that doesn't need a table.
            'join_lines' => static fn (mixed $v): string => implode("\n", array_map('strval', (array) ($v ?? []))),
            // Same idea as 'join_lines' but comma-separated — for a list
            // that reads better as one paragraph (PHP extensions) than as
            // dozens of stacked lines.
            'join_comma' => static fn (mixed $v): string => implode(', ', array_map('strval', (array) ($v ?? []))),
            'join_space' => static function (mixed $v): string {
                $parts = array_filter((array) $v, static fn (mixed $p): bool => $p !== null && $p !== '');

                return trim(implode(' ', array_map('strval', $parts)));
            },
            'registrable_domain' => static fn (mixed $v): string => is_string($v) && $v !== ''
                ? SiteContext::registrableDomain($v) : '',
            // 'from' payload.themes (the whole map) — finds the active entry
            // itself rather than needing a dot-path into an unknown slug key.
            'active_theme' => static function (mixed $v): string {
                foreach (is_array($v) ? $v : [] as $theme) {
                    if (is_array($theme) && !empty($theme['active'])) {
                        $name = (string) ($theme['name'] ?? '?');
                        $ver  = (string) ($theme['version'] ?? '');

                        return $ver !== '' ? "{$name} {$ver}" : $name;
                    }
                }

                return '';
            },
            // Same input as 'active_theme' — the active theme's parent, by
            // name (see parentSlugOf() for what counts as a child theme).
            'active_theme_parent' => static function (mixed $v): string {
                $themes = is_array($v) ? $v : [];
                foreach ($themes as $theme) {
                    if (!is_array($theme) || empty($theme['active'])) {
                        continue;
                    }
                    $parentSlug = self::parentSlugOf($theme);
                    if ($parentSlug === '') {
                        return '';
                    }
                    $parent = $themes[$parentSlug] ?? null;

                    return is_array($parent)
                        ? (string) ($parent['name'] ?? $parentSlug)
                        : (string) ($theme['parent_name'] ?? $parentSlug);
                }

                return '';
            },
            // Same input as 'active_theme' — just the version, when a
            // {{variable}} wants that alone rather than "Name version".
            'active_theme_version' => static function (mixed $v): string {
                foreach (is_array($v) ? $v : [] as $theme) {
                    if (is_array($theme) && !empty($theme['active'])) {
                        return (string) ($theme['version'] ?? '');
                    }
                }

                return '';
            },
            // 'from' [payload.is_multisite, payload.multisite_type] — the
            // plugin's own two raw facts, turned into one translated label.
            'install_type' => function (mixed $v): string {
                $parts = (array) $v;
                if (empty($parts[0])) {
                    return $this->t->report('install_single', 'Single site');
                }

                return ($parts[1] ?? '') === 'subdomain'
                    ? $this->t->report('install_multisite_subdomain', 'Multisite (subdomain)')
                    : $this->t->report('install_multisite_directory', 'Multisite (subdirectory)');
            },
            // A tri-state boolean: true/false render Yes/No, null (probe
            // didn't run, or the fact was never observed) renders Unknown
            // rather than silently reading as "No" — same distinction
            // eol_status_label() below draws for EOL data.
            'yes_no' => function (mixed $v): string {
                if ($v === null) {
                    return $this->t->report('status_unknown', 'Unknown');
                }

                return $v ? $this->t->report('bool_yes', 'Yes') : $this->t->report('bool_no', 'No');
            },
            // 'from' reference.php_eol / reference.database_eol — the same
            // true/false/null EndOfLife::eolStatus() already gives rules
            // F3/H1 (true = past end of life), computed once by Router and
            // handed in as plain data, never a live lookup from here.
            'eol_status_label' => function (mixed $v): string {
                if ($v === null) {
                    return $this->t->report('status_unknown', 'Unknown');
                }

                return $v ? $this->t->report('status_eol', 'End of life') : $this->t->report('status_supported', 'Supported');
            },
            // Same true/false/null input as 'eol_status_label', but 'from'
            // is a 2-item list [isEol, date] (reference.database_eol,
            // .database_eol_date) — the date endoflife.date itself gives
            // (same source /data/databases already reads), folded into the
            // sentence instead of a bare status word. Deliberately its own
            // transform, not a change to 'eol_status_label': php_status
            // wasn't asked to grow a date too.
            'database_status_label' => function (mixed $v): string {
                $parts = (array) $v;
                $isEol = $parts[0] ?? null;
                $date  = (string) ($parts[1] ?? '');
                if ($isEol === null) {
                    return $this->t->report('status_unknown', 'Unknown');
                }
                if ($date === '') {
                    return $isEol ? $this->t->report('status_eol', 'End of life') : $this->t->report('status_supported', 'Supported');
                }

                return $isEol
                    ? sprintf($this->t->report('status_eol_since', 'Not supported since %s'), $date)
                    : sprintf($this->t->report('status_supported_until', 'Supported until %s'), $date);
            },
            // Enabled/Not enabled — distinct wording from 'yes_no' (used
            // for gzip/brotli specifically; HTTP version fields keep
            // Yes/No).
            'enabled_label' => function (mixed $v): string {
                if ($v === null) {
                    return $this->t->report('status_unknown', 'Unknown');
                }

                return $v ? $this->t->report('label_enabled', 'Enabled') : $this->t->report('label_disabled', 'Not enabled');
            },
        ];

        $this->colorTransforms = [
            // null (never observed/checked) gets no colour at all — same
            // "grey means nothing was actually checked" rule the rest of
            // this report already follows, never a false "red".
            'bool_green_red' => static fn (mixed $v): ?string => $v === null ? null : ($v ? 'green' : 'red'),
        ];

        $this->tableBuilders = [
            // Alphabetical by name (case-insensitive — no locale collation
            // attempted, same "simple sort" the rest of this project uses),
            // 3 columns: Name, Version (current, or "current → available"
            // when an update is offered), Status — a shortcode string
            // ("[img url=\"...\"]", space-separated) the report script
            // resolves into small icons: a green/red dot for active/inactive,
            // an upgrade icon when an update is offered, a vulnerability
            // icon when merge_vulnerabilities() found one. Vulnerability
            // data is the same BlogVault+Wordfence cross-reference the web
            // UI's own extraction report already does — reused, not
            // reimplemented, straight from helpers.php.
            'plugins' => function (array $context): array {
                $plugins = array_values(array_filter(
                    (array) ($context['payload']['plugins'] ?? []),
                    static fn (mixed $pl): bool => is_array($pl)
                ));
                usort($plugins, static fn (array $a, array $b): int =>
                    strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

                $bvBySlug  = array_column((array) ($context['probe']['blogvault']['plugins']['items'] ?? []), null, 'slug');
                $wfBySlug  = array_column((array) ($context['probe']['wordfence']['plugins']['items'] ?? []), null, 'slug');
                $licenses  = (array) ($context['licenses'] ?? []);

                $rows = [];
                foreach ($plugins as $pl) {
                    $active  = !empty($pl['active']);
                    $update  = !empty($pl['new_version']) ? (string) $pl['new_version'] : '';
                    $version = (string) ($pl['version'] ?? '');
                    $slug    = SoftwareCatalog::normalizeSlug('plugin', (string) ($pl['slug'] ?? ''));
                    $vulns   = merge_vulnerabilities(
                        (array) ($bvBySlug[$slug]['vulnerabilities'] ?? []),
                        (array) ($wfBySlug[$slug]['vulnerabilities'] ?? []),
                        $version !== '' ? $version : null
                    );
                    $license = $licenses['plugin:' . $slug] ?? null;

                    $rows[] = [
                        self::cell((string) ($pl['name'] ?? $pl['slug'] ?? '?')),
                        self::cell($update !== '' ? "{$version} → {$update}" : $version, $update !== '' ? 'orange' : null),
                        self::cell($this->statusShortcodes($active, $update !== '', $vulns !== [], is_string($license) ? $license : null)),
                    ];
                }

                return [
                    'headers' => [
                        $this->t->report('col_name', 'Name'),
                        $this->t->report('col_version', 'Version'),
                        $this->t->report('col_status', 'Status'),
                    ],
                    'rows' => $rows,
                ];
            },

            // Same 3-column shape as 'plugins' (translated headers, merged
            // version, status shortcodes, real vulnerability data) — except
            // the order isn't alphabetical: the active theme always leads,
            // its parent (if it's a child theme) always follows it, then
            // everything else alphabetical. A child theme rarely carries
            // its own vulnerability data or even much of a "version" story
            // on its own — seeing it right next to what it's built on is
            // more useful here than where the alphabet would put it.
            'themes' => function (array $context): array {
                $themes = (array) ($context['payload']['themes'] ?? []);

                $activeKey = null;
                foreach ($themes as $key => $th) {
                    if (is_array($th) && !empty($th['active'])) {
                        $activeKey = $key;
                        break;
                    }
                }

                $parentKey = null;
                if ($activeKey !== null && is_array($themes[$activeKey])) {
                    $parentSlug = self::parentSlugOf($themes[$activeKey]);
                    if ($parentSlug !== '' && isset($themes[$parentSlug])) {
                        $parentKey = $parentSlug;
                    }
                }

                $ordered = [];
                if ($activeKey !== null) {
                    $ordered[$activeKey] = $themes[$activeKey];
                }
                if ($parentKey !== null) {
                    $ordered[$parentKey] = $themes[$parentKey];
                }

                $rest = array_diff_key($themes, $ordered);
                uasort($rest, static fn (mixed $a, mixed $b): int =>
                    strcasecmp((string) (is_array($a) ? ($a['name'] ?? '') : ''), (string) (is_array($b) ? ($b['name'] ?? '') : '')));

                $bvBySlug = array_column((array) ($context['probe']['blogvault']['themes']['items'] ?? []), null, 'slug');
                $wfBySlug = array_column((array) ($context['probe']['wordfence']['themes']['items'] ?? []), null, 'slug');
                $licenses = (array) ($context['licenses'] ?? []);

                $rows = [];
                foreach ($ordered + $rest as $th) {
                    if (!is_array($th)) {
                        continue;
                    }
                    $active  = !empty($th['active']);
                    $update  = !empty($th['new_version']) ? (string) $th['new_version'] : '';
                    $version = (string) ($th['version'] ?? '');
                    $slug    = SoftwareCatalog::normalizeSlug('theme', (string) ($th['slug'] ?? ''));
                    $vulns   = merge_vulnerabilities(
                        (array) ($bvBySlug[$slug]['vulnerabilities'] ?? []),
                        (array) ($wfBySlug[$slug]['vulnerabilities'] ?? []),
                        $version !== '' ? $version : null
                    );
                    $license = $licenses['theme:' . $slug] ?? null;

                    $rows[] = [
                        self::cell((string) ($th['name'] ?? $th['slug'] ?? '?')),
                        self::cell($update !== '' ? "{$version} → {$update}" : $version, $update !== '' ? 'orange' : null),
                        self::cell($this->statusShortcodes($active, $update !== '', $vulns !== [], is_string($license) ? $license : null)),
                    ];
                }

                return [
                    'headers' => [
                        $this->t->report('col_name', 'Name'),
                        $this->t->report('col_version', 'Version'),
                        $this->t->report('col_status', 'Status'),
                    ],
                    'rows' => $rows,
                ];
            },

            // Always-active by definition (a WordPress must-use plugin has no
            // "inactive" state) — no Status column, unlike 'plugins'/'themes'.
            'mu_plugins' => function (array $context): array {
                $rows = [];
                foreach ((array) ($context['payload']['mu_plugins'] ?? []) as $file => $mu) {
                    if (!is_array($mu)) {
                        continue;
                    }
                    $rows[] = [
                        self::cell((string) ($mu['Name'] ?? (string) $file)),
                        self::cell((string) ($mu['Version'] ?? '')),
                    ];
                }

                return ['headers' => [$this->t->report('col_name', 'Name'), $this->t->report('col_version', 'Version')], 'rows' => $rows];
            },

            // WordPress core's own get_dropins() map: filename => [description,
            // ...] — a plain description string, not a full plugin header
            // (drop-ins don't carry a version), see the plugin's file check.
            'dropins' => function (array $context): array {
                $rows = [];
                foreach ((array) ($context['payload']['dropin_plugins'] ?? []) as $file => $dropin) {
                    $desc   = is_array($dropin) ? (string) ($dropin[0] ?? '?') : (string) $dropin;
                    $rows[] = [self::cell((string) $file), self::cell($desc)];
                }

                return ['headers' => [$this->t->report('col_file', 'File'), $this->t->report('col_description', 'Description')], 'rows' => $rows];
            },

            // The plugin's UsersCollector added email alongside login on
            // 2026-08-31 — confirmed live in a real payload, so this table
            // needs no placeholder/fallback for a missing email. Site
            // administrators and network super admins are two independent
            // collector lists (a super admin need not be an administrator
            // on this particular site, or vice versa) — shown together,
            // each row tagged with its own Role, rather than merged/
            // deduplicated by login or email: someone holding both shows
            // up twice, which is the honest reading of two separate facts.
            'administrators' => function (array $context): array {
                $payload = (array) ($context['payload'] ?? []);
                $row = fn (array $a, string $role): array => [
                    self::cell((string) ($a['login'] ?? '?')),
                    self::cell((string) ($a['email'] ?? '')),
                    self::cell($role),
                ];

                $rows = [];
                foreach ((array) ($payload['administrators'] ?? []) as $a) {
                    if (is_array($a)) {
                        $rows[] = $row($a, $this->t->report('role_administrator', 'Administrator'));
                    }
                }
                foreach ((array) ($payload['super_admins'] ?? []) as $a) {
                    if (is_array($a)) {
                        $rows[] = $row($a, $this->t->report('role_super_admin', 'Super Admin'));
                    }
                }

                return [
                    'headers' => [
                        $this->t->report('col_login', 'Login'),
                        $this->t->report('col_email', 'Email'),
                        $this->t->report('col_role', 'Role'),
                    ],
                    'rows' => $rows,
                ];
            },

            // post_types (slug => label) and post_type_count (slug =>
            // {status => count}) are two separate payload keys that only
            // make sense joined — same join extraction.php's own "Content
            // types" table already does.
            'content_types' => function (array $context): array {
                $postTypes = (array) ($context['payload']['post_types'] ?? []);
                $counts    = (array) ($context['payload']['post_type_count'] ?? []);
                $rows      = [];
                foreach ($postTypes as $slug => $label) {
                    $c     = (array) ($counts[$slug] ?? []);
                    $total = array_sum(array_map('intval', $c));
                    $rows[] = [
                        self::cell((string) $label),
                        self::cell((string) $total),
                        self::cell((string) ($c['publish'] ?? '0')),
                        self::cell((string) ($c['draft'] ?? '0')),
                        self::cell((string) ($c['trash'] ?? '0')),
                    ];
                }

                return [
                    'headers' => [
                        $this->t->report('col_type', 'Type'),
                        $this->t->report('col_total', 'Total'),
                        $this->t->report('col_published', 'Published'),
                        $this->t->report('col_draft', 'Draft'),
                        $this->t->report('col_trash', 'Trash'),
                    ],
                    'rows' => $rows,
                ];
            },

            // {{wp_settings}} — the one field this contract is least sure
            // about (no dedicated rule category backs it, unlike every
            // other new field added alongside this one): permalinks +
            // WPML, the two concrete "site configuration" facts the
            // payload actually carries that fit the template's own
            // "réglages" description (permaliens, multilingue). Flagged so
            // this is the first one to revisit if it doesn't match.
            'settings' => function (array $context): array {
                $p    = (array) ($context['payload'] ?? []);
                $rows = [
                    [self::cell($this->t->report('setting_admin_email', 'Admin email')), self::cell((string) ($p['website_administrator_email'] ?? '—'))],
                    [self::cell($this->t->report('setting_permalinks', 'Permalinks')), self::cell((string) ($p['permalink_structure'] ?? '—'))],
                ];

                $wpml = (array) ($p['connectors']['wpml'] ?? []);
                if ($wpml !== []) {
                    $rows[] = [self::cell($this->t->report('setting_default_language', 'Default language')), self::cell((string) ($wpml['default_language'] ?? '—'))];
                    $rows[] = [self::cell($this->t->report('setting_active_languages', 'Active languages')), self::cell(implode(', ', (array) ($wpml['active_languages'] ?? [])))];
                }

                return ['headers' => [$this->t->report('col_setting', 'Setting'), $this->t->report('col_value', 'Value')], 'rows' => $rows];
            },

            // Top 10 by size — payload.database.tables carries every table
            // on a real site (100+ on the one this was checked against);
            // nobody reads a "largest tables" report past the first 10.
            'database_tables' => function (array $context): array {
                $tables = array_values(array_filter(
                    (array) ($context['payload']['database']['tables'] ?? []),
                    static fn (mixed $t): bool => is_array($t)
                ));
                usort($tables, static fn (array $a, array $b): int =>
                    (int) ($b['size_bytes'] ?? 0) <=> (int) ($a['size_bytes'] ?? 0));

                $rows = [];
                foreach (array_slice($tables, 0, 10) as $t) {
                    $overhead = (int) ($t['overhead_bytes'] ?? 0);
                    $rows[] = [
                        self::cell((string) ($t['name'] ?? '?')),
                        self::cell($this->formatBytes((int) ($t['size_bytes'] ?? 0))),
                        self::cell(number_format((int) ($t['row_count'] ?? 0))),
                        // Same "worth attention, not critical" orange rule
                        // as an available plugin update — some overhead
                        // (fragmentation) is a maintenance signal, not a
                        // failure on its own.
                        self::cell($this->formatBytes($overhead), $overhead > 0 ? 'orange' : null),
                    ];
                }

                return [
                    'headers' => [
                        $this->t->report('col_table', 'Table'),
                        $this->t->report('col_size', 'Size'),
                        $this->t->report('col_rows', 'Rows'),
                        $this->t->report('col_overhead', 'Overhead'),
                    ],
                    'rows' => $rows,
                ];
            },

            // Same 8 paths and the same writable/readable colour logic the
            // web UI's own "File permissions" card already uses (writable
            // is the thing worth flagging in production, not readable).
            'file_permissions' => function (array $context): array {
                $labels = [
                    'wp_config'   => 'wp-config.php',
                    'htaccess'    => '.htaccess',
                    'root'        => $this->t->report('perm_root', 'Site root'),
                    'index'       => 'index.php',
                    'content_dir' => 'wp-content',
                    'plugins_dir' => 'wp-content/plugins',
                    'themes_dir'  => 'wp-content/themes',
                    'uploads_dir' => 'wp-content/uploads',
                ];
                $yes = $this->t->report('bool_yes', 'Yes');
                $no  = $this->t->report('bool_no', 'No');

                $rows = [];
                foreach ((array) ($context['payload']['filesystem']['permissions'] ?? []) as $key => $perm) {
                    if (!is_array($perm)) {
                        continue;
                    }
                    $writable = !empty($perm['writable']);
                    $readable = !empty($perm['readable']);
                    $rows[] = [
                        self::cell((string) ($labels[$key] ?? $key)),
                        self::cell((string) ($perm['mode'] ?? '?')),
                        self::cell($writable ? $yes : $no, $writable ? 'orange' : 'green'),
                        self::cell($readable ? $yes : $no, $readable ? 'green' : 'red'),
                    ];
                }

                return [
                    'headers' => [
                        $this->t->report('col_location', 'Location'),
                        $this->t->report('col_mode', 'Mode'),
                        $this->t->report('col_writable', 'Writable'),
                        $this->t->report('col_readable', 'Readable'),
                    ],
                    'rows' => $rows,
                ];
            },

            // Fixed label order (not the probe's own map order) so the
            // report reads the same every time — same 6 headers the web
            // UI's own "Security headers" card checks.
            'security_headers' => function (array $context): array {
                $labels = [
                    'strict-transport-security' => 'HSTS',
                    'content-security-policy'   => 'Content-Security-Policy',
                    'x-content-type-options'    => 'X-Content-Type-Options',
                    'x-frame-options'           => 'X-Frame-Options',
                    'referrer-policy'           => 'Referrer-Policy',
                    'permissions-policy'        => 'Permissions-Policy',
                ];
                $headers = (array) ($context['probe']['http']['security_headers'] ?? []);
                $missing = $this->t->report('missing', 'Missing');

                $rows = [];
                foreach ($labels as $key => $label) {
                    $value   = $headers[$key] ?? null;
                    $present = $value !== null && $value !== '';
                    $rows[] = [
                        self::cell($label),
                        self::cell($present ? (string) $value : $missing, $present ? 'green' : 'orange'),
                    ];
                }

                return [
                    'headers' => [$this->t->report('col_header', 'Header'), $this->t->report('col_value', 'Value')],
                    'rows'    => $rows,
                ];
            },

            // Every known vulnerability across core + plugins + themes, one
            // flat table — the same merge_vulnerabilities() cross-reference
            // the 'plugins'/'themes' tables and the web UI's own extraction
            // report already use, just not filtered down to a single
            // component here.
            'vulnerabilities' => function (array $context): array {
                $payload = (array) ($context['payload'] ?? []);
                $bv      = (array) ($context['probe']['blogvault'] ?? []);
                $wf      = (array) ($context['probe']['wordfence'] ?? []);

                $all = [];
                foreach (merge_vulnerabilities(
                    (array) ($bv['core']['vulnerabilities'] ?? []),
                    (array) ($wf['core']['vulnerabilities'] ?? []),
                    is_string($payload['wp_version'] ?? null) ? $payload['wp_version'] : null
                ) as $v) {
                    $all[] = $v + ['component' => 'WordPress'];
                }

                $all = array_merge($all, $this->componentVulnerabilities(
                    'plugin',
                    (array) ($payload['plugins'] ?? []),
                    (array) ($bv['plugins']['items'] ?? []),
                    (array) ($wf['plugins']['items'] ?? [])
                ));
                $all = array_merge($all, $this->componentVulnerabilities(
                    'theme',
                    (array) ($payload['themes'] ?? []),
                    (array) ($bv['themes']['items'] ?? []),
                    (array) ($wf['themes']['items'] ?? [])
                ));

                $rows = [];
                foreach ($all as $v) {
                    $rating = strtolower((string) ($v['cvss_rating'] ?? ''));
                    $color  = match (true) {
                        in_array($rating, ['critical', 'high'], true) => 'red',
                        $rating === 'medium' => 'orange',
                        default              => 'grey', // never green — see cvss_badge()/style.css's badge-low
                    };
                    $patched = $v['patched_version'] ?? null;
                    $fixAvailable = $patched !== null && $patched !== '';
                    $rows[] = [
                        self::cell((string) ($v['title'] ?? '—')),
                        self::cell($v['cvss_score'] !== null ? (string) $v['cvss_score'] : '—', $color),
                        // The patched version itself isn't shown here —
                        // just whether one exists at all. component/CVE/
                        // source were dropped from this table on request.
                        self::cell(
                            $fixAvailable ? $this->t->report('label_available', 'Available') : $this->t->report('label_not_available', 'Not available'),
                            $fixAvailable ? 'green' : 'red'
                        ),
                    ];
                }

                return [
                    'headers' => [
                        $this->t->report('col_title', 'Title'),
                        'CVSS',
                        $this->t->report('col_fix', 'Fix'),
                    ],
                    'rows' => $rows,
                ];
            },
        ];
    }

    /**
     * merge_vulnerabilities() for every plugin or theme in $components
     * (payload.plugins / payload.themes — a slug-keyed map), tagged with
     * each one's own display name.
     *
     * @param string                      $catalogType 'plugin' or 'theme' — SoftwareCatalog::normalizeSlug()'s own type
     * @param array<string, mixed>        $components
     * @param list<array<string, mixed>>  $bvItems
     * @param list<array<string, mixed>>  $wfItems
     * @return list<array<string, mixed>>
     */
    private function componentVulnerabilities(string $catalogType, array $components, array $bvItems, array $wfItems): array
    {
        $bvBySlug = array_column($bvItems, null, 'slug');
        $wfBySlug = array_column($wfItems, null, 'slug');

        $found = [];
        foreach ($components as $c) {
            if (!is_array($c)) {
                continue;
            }
            $slug   = SoftwareCatalog::normalizeSlug($catalogType, (string) ($c['slug'] ?? ''));
            $merged = merge_vulnerabilities(
                (array) ($bvBySlug[$slug]['vulnerabilities'] ?? []),
                (array) ($wfBySlug[$slug]['vulnerabilities'] ?? []),
                is_string($c['version'] ?? null) ? $c['version'] : null
            );
            foreach ($merged as $v) {
                $found[] = $v + ['component' => (string) ($c['name'] ?? $slug)];
            }
        }

        return $found;
    }

    /** Locale-aware, unlike the web UI's own fmt_bytes() (French units, unconditionally — fine for that English-only chrome, wrong for a report that follows ?lang=). */
    private function formatBytes(int $bytes): string
    {
        $units = [
            $this->t->report('unit_b', 'B'),
            $this->t->report('unit_kb', 'KB'),
            $this->t->report('unit_mb', 'MB'),
            $this->t->report('unit_gb', 'GB'),
            $this->t->report('unit_tb', 'TB'),
        ];

        $value = (float) $bytes;
        $last  = $units[count($units) - 1];
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === $last) {
                return round($value, 1) . ' ' . $unit;
            }
            $value /= 1024;
        }

        return round($value, 1) . ' ' . $last;
    }

    /**
     * @param array<string, mixed>        $contract     one config/reports/*.php file
     * @param array<string, mixed>        $context      payload/site/meta/probe/host — see Router::extractionReportJson()
     * @param list<array<string, mixed>>  $findings     already-translated findings, each carrying category_code + pastille
     * @param list<mixed>                 $observations analyst-authored (DataStore::readObservations()) — each carries
     *                                                   its own 'section' naming which observations-type field it belongs to
     * @return array{fields: array<string, array<string, mixed>>}
     */
    public function build(array $contract, array $context, array $findings, array $observations = []): array
    {
        $fields = [];
        foreach ((array) ($contract['fields'] ?? []) as $docVar => $spec) {
            $spec  = (array) $spec;
            $field = match ($spec['type'] ?? null) {
                'value'        => $this->buildValueField($context, $spec),
                'table'        => $this->buildTableField($context, $spec),
                'observations' => $this->buildObservationsField((string) $docVar, $findings, $spec, $observations),
                default        => null, // unknown/missing type — skip rather than send the script something it can't dispatch
            };
            if ($field !== null) {
                $fields[(string) $docVar] = $field;
            }
        }

        return ['fields' => $fields];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $spec
     * @return array{type: string, value: string, color: ?string}
     */
    private function buildValueField(array $context, array $spec): array
    {
        $colorTransformName = $spec['color_transform'] ?? null;
        $color = is_string($colorTransformName) && isset($this->colorTransforms[$colorTransformName])
            // Runs against the RAW value (before 'transform' formats it),
            // never the already-translated text — a colour keyed to a
            // locale's own label wording would break the moment that
            // wording changed.
            ? ($this->colorTransforms[$colorTransformName])($this->rawFromSpec($context, $spec))
            : (isset($spec['color']) ? (string) $spec['color'] : null);

        return [
            'type'  => 'value',
            'value' => $this->resolveValue($context, $spec),
            'color' => $color,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $spec
     * @return ?array{type: string, headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function buildTableField(array $context, array $spec): ?array
    {
        $source = (string) ($spec['source'] ?? '');
        if (!isset($this->tableBuilders[$source])) {
            return null;
        }
        $table = ($this->tableBuilders[$source])($context);

        return ['type' => 'table', 'headers' => $table['headers'], 'rows' => $table['rows']];
    }

    /**
     * A field's findings are every finding whose category matches OR whose
     * id is explicitly listed — the union matters: D1 (SPF) is category
     * EMAIL, not DOMAIN, but belongs in the domain section anyway per the
     * real report template, and a category filter alone would silently
     * drop it. Any analyst-authored observation whose own 'section'
     * equals this field's name — and whose 'include' flag is true — joins
     * the same list: an omitted one still exists in observations.json
     * (so re-including it later doesn't mean retyping it), it just never
     * reaches a resolved field.
     *
     * @param string                      $fieldName    this field's own {{variable}} name — what a
     *                                                   custom observation's 'section' is matched against
     * @param list<array<string, mixed>>  $findings
     * @param array<string, mixed>        $spec
     * @param list<mixed>                 $observations
     * @return array{type: string, items: list<array{title: string, message: string, color: string, severity: string}>}
     */
    private function buildObservationsField(string $fieldName, array $findings, array $spec, array $observations): array
    {
        $categories = (array) ($spec['categories'] ?? []);
        $ids        = (array) ($spec['ids'] ?? []);

        // No topic filter at all (neither categories nor ids) means every
        // finding qualifies — the field this enables ("Enjeux": every red/
        // orange finding, whatever the topic) has nothing narrower to say;
        // its only filter dimension is colour, below.
        $matched = $categories === [] && $ids === []
            ? $findings
            : array_values(array_filter(
                $findings,
                static fn (array $f): bool => in_array($f['category_code'] ?? null, $categories, true)
                    || in_array($f['id'] ?? null, $ids, true)
            ));

        $items = array_map(static fn (array $f): array => [
            'title'    => (string) ($f['title'] ?? ''),
            'message'  => (string) ($f['message'] ?? ''),
            'color'    => (string) ($f['pastille'] ?? 'grey'),
            // Already translated (e.g. "Critique") — the eyebrow label on a
            // report card. Case/weight is a rendering choice, left to the
            // script, same as everywhere else here.
            'severity' => (string) ($f['severity'] ?? ''),
        ], $matched);

        $validColors = ['green', 'orange', 'red', 'blue', 'grey'];
        foreach ($observations as $r) {
            if (!is_array($r) || ($r['section'] ?? null) !== $fieldName || empty($r['include'])) {
                continue;
            }
            $color   = in_array($r['color'] ?? null, $validColors, true) ? (string) $r['color'] : 'grey';
            $items[] = [
                'title'    => (string) ($r['title'] ?? ''),
                'message'  => (string) ($r['description'] ?? ''),
                'color'    => $color,
                // No rule severity to show — the pastille's own translated
                // label ("Info"/"Critique"/…) reads naturally in that spot
                // instead of leaving it blank.
                'severity' => $this->t->pastille($color),
            ];
        }

        // Optional pastille filter ('colors' => ['red', 'orange']) — this is
        // what lets one field show only a colour subset ("Enjeux" = red +
        // orange, "Bonne pratique" = green, …) regardless of topic. Absent
        // or empty means no colour restriction, same "nothing specified
        // means everything" rule 'categories'/'ids' already follow.
        $colors = array_values(array_filter((array) ($spec['colors'] ?? []), 'is_string'));
        if ($colors !== []) {
            $items = array_values(array_filter($items, static fn (array $i): bool => in_array($i['color'], $colors, true)));
        }

        // Blue (informational) first, then red, orange, green — usort is
        // stable (PHP 8+), so items sharing a colour keep their original
        // (category/id match, then manual) relative order.
        usort($items, static fn (array $a, array $b): int =>
            (self::OBSERVATION_COLOR_ORDER[$a['color']] ?? 99) <=> (self::OBSERVATION_COLOR_ORDER[$b['color']] ?? 99));

        return ['type' => 'observations', 'items' => $items];
    }

    /**
     * The parent theme's slug, or '' for a theme that isn't a child.
     * 'parent_slug' is what the plugin sets only when WP_Theme::parent()
     * exists; 'template' alone is not enough — WordPress fills it with the
     * theme's own slug for an ordinary (non-child) theme.
     *
     * @param array<mixed> $theme
     */
    private static function parentSlugOf(array $theme): string
    {
        $slug = (string) ($theme['slug'] ?? '');
        foreach ([(string) ($theme['parent_slug'] ?? ''), (string) ($theme['template'] ?? '')] as $candidate) {
            if ($candidate !== '' && strcasecmp($candidate, $slug) !== 0) {
                return $candidate;
            }
        }

        return '';
    }

    /** @return array{text: string, color: ?string} */
    private static function cell(string $text, ?string $color = null): array
    {
        return ['text' => $text, 'color' => $color];
    }

    /** DataStore::setLicenseStatus()'s own status strings — icon file, keyed the same way. 'n_a' deliberately has no entry: not applicable shows no icon, same as a clean vulnerability cell shows nothing rather than a green "—" badge. */
    private const array LICENSE_STATUS_ICONS = [
        'active'      => 'license-active',
        'missing'     => 'license-missing',
        'to_validate' => 'license-to-validate',
    ];

    /**
     * A Status cell's content: "[img url=\"...\"]" tokens, one per icon that
     * applies, space-separated — the report script's own small, generic
     * regex resolves each into an inserted image (see rapport-poc's
     * insererStatut()/imageDe()). This is the ONLY place that decides which
     * icon a status means; the script never guesses from a column name.
     */
    private function statusShortcodes(bool $active, bool $hasUpdate, bool $hasVulnerability, ?string $licenseStatus): string
    {
        $icons = [$active ? 'dot-green' : 'dot-red'];
        if ($hasUpdate) {
            $icons[] = 'upgrade';
        }
        if ($hasVulnerability) {
            $icons[] = 'vulnerable';
        }
        if ($licenseStatus !== null && isset(self::LICENSE_STATUS_ICONS[$licenseStatus])) {
            $icons[] = self::LICENSE_STATUS_ICONS[$licenseStatus];
        }

        return implode(' ', array_map(
            fn (string $name): string => sprintf('[img url="%s/%s.png"]', rtrim($this->iconBaseUrl, '/'), $name),
            $icons
        ));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $spec
     */
    private function resolveValue(array $context, array $spec): string
    {
        $raw = $this->rawFromSpec($context, $spec);

        $transformName = $spec['transform'] ?? null;
        $value = is_string($transformName) && isset($this->transforms[$transformName])
            ? ($this->transforms[$transformName])($raw)
            : $raw;

        if ($value === null || $value === '') {
            $value = $spec['default'] ?? '';
        }

        return (string) $value;
    }

    /**
     * $spec['from'] resolved against $context, before any 'transform' —
     * shared by resolveValue() and a 'color_transform', so a colour
     * decision is made from the same raw fact the displayed text is.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $spec
     */
    private function rawFromSpec(array $context, array $spec): mixed
    {
        $from = $spec['from'] ?? null;

        return is_array($from)
            ? array_map(fn (mixed $p): mixed => self::dotGet($context, (string) $p), $from)
            : (is_string($from) ? self::dotGet($context, $from) : null);
    }

    /** @param array<string, mixed> $data */
    private static function dotGet(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
