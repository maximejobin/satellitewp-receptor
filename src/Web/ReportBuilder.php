<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Web;

use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Reference\WordPressVersions;
use SatelliteWP\Xtractor\Rules\Pastille;
use SatelliteWP\Xtractor\Rules\Translator;

require_once __DIR__ . '/helpers.php';

/**
 * Resolves a report contract (config/reports/*.php) against one extraction's
 * data context into the JSON the report script renders. Every field carries
 * its 'type' ('value' | 'table' | 'observations') so the script stays one
 * generic dispatcher; all client-facing formatting (dates, numbers, labels,
 * colours) happens here, in the report's locale.
 */
final class ReportBuilder
{
    /** Reading order of an observations field: client actions, info, then worst to best. */
    public const array OBSERVATION_COLOR_ORDER = ['purple' => 0, 'blue' => 1, 'red' => 2, 'orange' => 3, 'green' => 4, 'grey' => 5];

    /** WordPress core post types that are machinery, not content a client manages. */
    private const array INTERNAL_POST_TYPES = [
        'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_family', 'wp_font_face',
    ];

    /** Prefixes of builder/form/field plugins' own configuration post types. */
    private const array CONFIG_POST_TYPE_PREFIXES = [
        'acf-', 'wpcf7', 'vc_', 'vc4_', 'wpb_', 'pum_', 'popup_theme', 'elementor_', 'wpforms', 'jet-',
        'fl-builder', 'et_pb_', 'shop_', 'wc_', 'wp_',
    ];

    /** Core types shown whether or not anything is published (attachments are never "published"). */
    private const array CORE_CONTENT_TYPES = ['post', 'page', 'attachment'];

    /** Paths that must stay writable for WordPress to work — writable is expected there, not a finding. */
    private const array WRITABLE_BY_DESIGN = ['uploads_dir'];

    /** DataStore::setLicenseStatus() status => icon file; 'n_a' shows no icon. */
    private const array LICENSE_STATUS_ICONS = [
        'active'      => 'license-active',
        'missing'     => 'license-missing',
        'to_validate' => 'license-to-validate',
    ];

    /**
     * @param Translator $t           the report's locale (config/lang 'report' block)
     * @param string     $iconBaseUrl absolute base URL of the status icons
     * @param list<string> $ignoredVulnerabilities ids left out of the vulnerability tables
     */
    public function __construct(
        private readonly Translator $t,
        private readonly string $iconBaseUrl = '',
        private readonly array $ignoredVulnerabilities = [],
    ) {
    }

    /**
     * @param array<string, mixed>        $contract     one config/reports/*.php file
     * @param array<string, mixed>        $context      payload/site/meta/probe/host/reference/licenses
     * @param list<array<string, mixed>>  $findings     translated findings (category_code, pastille, title, message)
     * @param list<mixed>                 $observations analyst-authored items, each naming its 'section'
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
                default        => null,
            };
            if ($field !== null) {
                $fields[(string) $docVar] = $field;
            }
        }

        return ['fields' => $fields];
    }

    // ---------------------------------------------------------------- fields

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $spec
     * @return array{type: string, value: string, color: ?string}
     */
    private function buildValueField(array $context, array $spec): array
    {
        $raw = $this->rawFromSpec($context, $spec);

        $transform = $spec['transform'] ?? null;
        $value     = is_string($transform) ? $this->transform($transform, $raw) : $raw;
        if ($value === null || $value === '' || $value === []) {
            $value = isset($spec['default_label'])
                ? $this->t->report((string) $spec['default_label'])
                : ($spec['default'] ?? '');
        }

        // Colour is decided from the raw fact, never from translated text.
        $colorTransform = $spec['color_transform'] ?? null;
        $color = is_string($colorTransform)
            ? $this->color($colorTransform, $raw)
            : (isset($spec['color']) ? (string) $spec['color'] : null);

        return ['type' => 'value', 'value' => is_scalar($value) ? (string) $value : '', 'color' => $color];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $spec
     * @return ?array{type: string, headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function buildTableField(array $context, array $spec): ?array
    {
        $table = match ((string) ($spec['source'] ?? '')) {
            'plugins'          => $this->pluginsTable($context),
            'themes'           => $this->themesTable($context),
            'mu_plugins'       => $this->muPluginsTable($context),
            'dropins'          => $this->dropinsTable($context),
            'administrators'   => $this->administratorsTable($context),
            'content_types'    => $this->contentTypesTable($context),
            'settings'         => $this->settingsTable($context),
            'constants'        => $this->constantsTable($context),
            'database_tables'  => $this->databaseTablesTable($context),
            'file_permissions' => $this->filePermissionsTable($context),
            'security_headers' => $this->securityHeadersTable($context),
            'vulnerabilities'  => $this->vulnerabilitiesTable($context),
            default            => null,
        };

        return $table === null ? null : ['type' => 'table', 'headers' => $table['headers'], 'rows' => $table['rows']];
    }

    /**
     * Findings matching the field's categories OR ids (union — a rule can be
     * placed in a section outside its own category), plus analyst-authored
     * observations filed under this field, optionally narrowed by colour.
     *
     * @param list<array<string, mixed>>  $findings
     * @param array<string, mixed>        $spec
     * @param list<mixed>                 $observations
     * @return array{type: string, items: list<array{title: string, message: string, color: string, severity: string}>}
     */
    private function buildObservationsField(string $fieldName, array $findings, array $spec, array $observations): array
    {
        $categories = (array) ($spec['categories'] ?? []);
        $ids        = (array) ($spec['ids'] ?? []);

        $matched = $categories === [] && $ids === []
            ? $findings
            : array_filter(
                $findings,
                static fn (array $f): bool => in_array($f['category_code'] ?? null, $categories, true)
                    || in_array($f['id'] ?? null, $ids, true)
            );

        $items = [];
        foreach ($matched as $f) {
            $items[] = [
                'title'    => (string) ($f['title'] ?? ''),
                'message'  => (string) ($f['message'] ?? ''),
                'color'    => (string) ($f['pastille'] ?? Pastille::Grey->value),
                'severity' => (string) ($f['severity'] ?? ''),
            ];
        }

        foreach ($observations as $r) {
            if (!is_array($r) || ($r['section'] ?? null) !== $fieldName || empty($r['include'])) {
                continue;
            }
            $color   = Pastille::isValid($r['color'] ?? null) ? (string) $r['color'] : Pastille::Grey->value;
            $items[] = [
                'title'    => (string) ($r['title'] ?? ''),
                'message'  => (string) ($r['description'] ?? ''),
                'color'    => $color,
                // No rule severity exists for a manual line; its pastille label reads naturally there.
                'severity' => $this->t->pastille($color),
            ];
        }

        $colors = array_values(array_filter((array) ($spec['colors'] ?? []), 'is_string'));
        if ($colors !== []) {
            $items = array_filter($items, static fn (array $i): bool => in_array($i['color'], $colors, true));
        }
        $items = array_values($items);

        // usort is stable: items of one colour keep their catalogue/manual order.
        usort($items, static fn (array $a, array $b): int =>
            (self::OBSERVATION_COLOR_ORDER[$a['color']] ?? 99) <=> (self::OBSERVATION_COLOR_ORDER[$b['color']] ?? 99));

        return ['type' => 'observations', 'items' => $items];
    }

    // ------------------------------------------------------------ transforms

    private function transform(string $name, mixed $v): mixed
    {
        return match ($name) {
            'site_display'           => site_display((string) ($v ?? '')),
            'date'                   => $this->formatDate($v),
            'join_lines'             => implode("\n", array_map('strval', (array) ($v ?? []))),
            'join_comma'             => implode(', ', array_map('strval', (array) ($v ?? []))),
            'registrable_domain'     => is_string($v) && $v !== '' ? SiteContext::registrableDomain($v) : '',
            'active_theme'           => (string) (self::activeTheme($v)['name'] ?? ''),
            'active_theme_version'   => (string) (self::activeTheme($v)['version'] ?? ''),
            'active_theme_parent'    => self::activeThemeParent($v),
            'install_type'           => $this->installType((array) $v),
            'yes_no'                 => $this->yesNo($v),
            'enabled_label'          => $v === null ? $this->unknown() : $this->t->report($v ? 'label_enabled' : 'label_disabled'),
            'eol_status_label'       => $this->eolStatus($v, ''),
            'database_status_label'  => $this->eolStatus(((array) $v)[0] ?? null, (string) (((array) $v)[1] ?? '')),
            'database_label'         => $this->databaseLabel((array) $v),
            'database_type'          => $v === null ? '' : self::databaseType($v),
            'database_version'       => $v === null ? '' : self::databaseVersion($v),
            'wordpress_status_label' => $this->wordpressStatus($v),
            'auto_update_core'       => $this->autoUpdateCore($v),
            'score_100'              => is_numeric($v) ? ((int) round((float) $v)) . '/100' : '',
            'count'                  => (string) count((array) $v),
            'php_size'               => $this->phpSize($v),
            default                  => $v,
        };
    }

    private function color(string $name, mixed $v): ?string
    {
        // null means "never observed" — no colour, never a false red.
        return match ($name) {
            'bool_green_red'         => $v === null ? null : ($v ? 'green' : 'red'),
            'eol_red_green'          => self::eolColor(is_array($v) ? ($v[0] ?? null) : $v),
            'wordpress_status_color' => $v === null ? null : match (WordPressVersions::status((string) $v)) {
                'unsecure' => 'red',
                'uptodate' => 'green',
                default    => 'orange',
            },
            'lighthouse'             => is_numeric($v) ? match (true) {
                (float) $v >= 90 => 'green',
                (float) $v >= 50 => 'orange',
                default          => 'red',
            } : null,
            default                  => null,
        };
    }

    private static function eolColor(mixed $isEol): ?string
    {
        return $isEol === null ? null : ($isEol ? 'red' : 'green');
    }

    private function unknown(): string
    {
        return $this->t->report('status_unknown', 'Unknown');
    }

    private function yesNo(mixed $v): string
    {
        return $v === null ? $this->unknown() : $this->t->report($v ? 'bool_yes' : 'bool_no');
    }

    private function eolStatus(mixed $isEol, string $date): string
    {
        if ($isEol === null) {
            return $this->unknown();
        }
        $formatted = $this->formatDate($date);
        if ($formatted === '') {
            return $this->t->report($isEol ? 'status_eol' : 'status_supported');
        }

        return sprintf($this->t->report($isEol ? 'status_eol_since' : 'status_supported_until'), $formatted);
    }

    /** @param array<array-key, mixed> $parts [is_multisite, multisite_type] */
    private function installType(array $parts): string
    {
        if (empty($parts[0])) {
            return $this->t->report('install_single');
        }

        return $this->t->report(($parts[1] ?? '') === 'subdomain' ? 'install_multisite_subdomain' : 'install_multisite_directory');
    }

    /** @param array<array-key, mixed> $parts [database_type, database_version] */
    private function databaseLabel(array $parts): string
    {
        return trim(self::databaseType($parts[0] ?? null) . ' ' . self::databaseVersion($parts[1] ?? null));
    }

    private static function databaseType(mixed $type): string
    {
        $type = strtolower((string) $type);

        return match (true) {
            str_contains($type, 'maria') => 'MariaDB',
            str_contains($type, 'mysql') => 'MySQL',
            default                      => ucfirst($type),
        };
    }

    /** "10.6.22-MariaDB-0ubuntu0.22.04.1-log" → "10.6.22". */
    private static function databaseVersion(mixed $version): string
    {
        return preg_match('/^\d+(?:\.\d+)*/', (string) $version, $m) === 1 ? $m[0] : (string) $version;
    }

    private function wordpressStatus(mixed $raw): string
    {
        if ($raw === null) {
            return $this->unknown();
        }

        return $this->t->report(match (WordPressVersions::status((string) $raw)) {
            'unsecure' => 'wp_status_unsecure',
            'uptodate' => 'wp_status_uptodate',
            default    => 'wp_status_outdated',
        });
    }

    /** payload.core_update.auto_update_core: false / 'minor' / true|'major' (WP_AUTO_UPDATE_CORE semantics). */
    private function autoUpdateCore(mixed $v): string
    {
        return match (true) {
            $v === null || $v === ''                             => $this->unknown(),
            $v === false || in_array($v, ['false', '0'], true)   => $this->t->report('auto_update_off'),
            $v === 'minor'                                       => $this->t->report('auto_update_minor'),
            default                                              => $this->t->report('auto_update_all'),
        };
    }

    /** An ISO date or timestamp as a long, localized date ("5 septembre 2008"); '' when unparseable. */
    private function formatDate(mixed $v): string
    {
        if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) !== 1) {
            return '';
        }
        $day = (int) $m[3];

        return strtr($this->t->report('date_format', '{month} {day}, {year}'), [
            '{day}'   => $day === 1 ? $this->t->report('date_first_day', '1') : (string) $day,
            '{month}' => $this->t->report('month_' . (int) $m[2]),
            '{year}'  => $m[1],
        ]);
    }

    private function formatNumber(float $n, int $decimals = 0): string
    {
        return number_format($n, $decimals, $this->t->report('num_decimal', '.'), $this->t->report('num_thousands', ','));
    }

    /** A php.ini size ("64M", "512K", "2G", plain bytes) as "64 Mo"; -1 or 0 means no limit. */
    private function phpSize(mixed $v): string
    {
        $raw = strtoupper(trim((string) ($v ?? '')));
        if ($raw === '') {
            return '';
        }
        if ($raw === '-1' || $raw === '0') {
            return $this->t->report('size_unlimited', 'Unlimited');
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])B?$/', $raw, $m) === 1) {
            $unit = ['K' => 'unit_kb', 'M' => 'unit_mb', 'G' => 'unit_gb'][$m[2]];

            return $this->formatNumber((float) $m[1], str_contains($m[1], '.') ? 1 : 0) . ' ' . $this->t->report($unit);
        }

        return ctype_digit($raw) ? $this->formatBytes((int) $raw) : (string) $v;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['unit_b', 'unit_kb', 'unit_mb', 'unit_gb', 'unit_tb'];
        $value = (float) $bytes;
        $i     = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        $value = round($value, 1);

        return $this->formatNumber($value, $value === floor($value) ? 0 : 1) . ' ' . $this->t->report($units[$i]);
    }

    // ------------------------------------------------------------- themes

    /** @return ?array<array-key, mixed> the active theme entry of payload.themes */
    private static function activeTheme(mixed $themes): ?array
    {
        foreach (is_array($themes) ? $themes : [] as $theme) {
            if (is_array($theme) && !empty($theme['active'])) {
                return $theme;
            }
        }

        return null;
    }

    private static function activeThemeParent(mixed $themes): string
    {
        $active = self::activeTheme($themes);
        if ($active === null) {
            return '';
        }
        $parentSlug = self::parentSlugOf($active);
        if ($parentSlug === '') {
            return '';
        }
        $parent = is_array($themes) ? ($themes[$parentSlug] ?? null) : null;

        return is_array($parent)
            ? (string) ($parent['name'] ?? $parentSlug)
            : (string) ($active['parent_name'] ?? $parentSlug);
    }

    /**
     * The parent theme's slug, or '' for a theme that isn't a child. WordPress
     * fills 'template' with a non-child theme's own slug, so that alone is not a parent.
     *
     * @param array<array-key, mixed> $theme
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

    // --------------------------------------------------------------- tables

    /**
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function pluginsTable(array $context): array
    {
        $plugins = array_values(array_filter((array) ($context['payload']['plugins'] ?? []), 'is_array'));
        usort($plugins, static fn (array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $this->componentTable('plugin', $plugins, $context);
    }

    /**
     * Active theme first, then its parent, then the rest alphabetically — a
     * child theme reads best next to what it's built on.
     *
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function themesTable(array $context): array
    {
        $themes = array_filter((array) ($context['payload']['themes'] ?? []), 'is_array');

        $ordered = [];
        foreach ($themes as $key => $th) {
            if (!empty($th['active'])) {
                $ordered[$key] = $th;
                $parentSlug    = self::parentSlugOf($th);
                if ($parentSlug !== '' && isset($themes[$parentSlug])) {
                    $ordered[$parentSlug] = $themes[$parentSlug];
                }
                break;
            }
        }
        $rest = array_diff_key($themes, $ordered);
        uasort($rest, static fn (array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $this->componentTable('theme', array_values($ordered + $rest), $context);
    }

    /**
     * Name, Version ("current → available" when an update exists) and a
     * Status cell of icon shortcodes the script turns into images.
     *
     * @param list<array<array-key, mixed>> $components
     * @param array<string, mixed>          $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function componentTable(string $type, array $components, array $context): array
    {
        $bvBySlug = array_column((array) ($context['probe']['blogvault'][$type . 's']['items'] ?? []), null, 'slug');
        $wfBySlug = array_column((array) ($context['probe']['wordfence'][$type . 's']['items'] ?? []), null, 'slug');
        $licenses = (array) ($context['licenses'] ?? []);

        $rows = [];
        foreach ($components as $c) {
            $update  = !empty($c['new_version']) ? (string) $c['new_version'] : '';
            $version = (string) ($c['version'] ?? '');
            $slug    = SoftwareCatalog::normalizeSlug($type, (string) ($c['slug'] ?? ''));
            $vulns   = merge_vulnerabilities(
                (array) ($bvBySlug[$slug]['vulnerabilities'] ?? []),
                (array) ($wfBySlug[$slug]['vulnerabilities'] ?? []),
                $version !== '' ? $version : null
            );
            $license = $licenses[$type . ':' . $slug] ?? null;

            $rows[] = [
                self::cell((string) ($c['name'] ?? $c['slug'] ?? '?')),
                self::cell($update !== '' ? "{$version} → {$update}" : $version, $update !== '' ? 'orange' : null),
                self::cell($this->statusShortcodes(!empty($c['active']), $update !== '', $vulns !== [], is_string($license) ? $license : null)),
            ];
        }

        return ['headers' => $this->headers('col_name', 'col_version', 'col_status'), 'rows' => $rows];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function muPluginsTable(array $context): array
    {
        $rows = [];
        foreach ((array) ($context['payload']['mu_plugins'] ?? []) as $file => $mu) {
            if (is_array($mu)) {
                $rows[] = [self::cell((string) ($mu['Name'] ?? $file)), self::cell((string) ($mu['Version'] ?? ''))];
            }
        }

        return ['headers' => $this->headers('col_name', 'col_version'), 'rows' => $rows];
    }

    /**
     * get_dropins() map: filename => [description, …] — drop-ins carry no version.
     *
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function dropinsTable(array $context): array
    {
        $rows = [];
        foreach ((array) ($context['payload']['dropin_plugins'] ?? []) as $file => $dropin) {
            $rows[] = [self::cell((string) $file), self::cell(is_array($dropin) ? (string) ($dropin[0] ?? '?') : (string) $dropin)];
        }

        return ['headers' => $this->headers('col_file', 'col_description'), 'rows' => $rows];
    }

    /**
     * Site administrators and network super admins are independent lists; a
     * person holding both roles appears once per role.
     *
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function administratorsTable(array $context): array
    {
        $rows = [];
        foreach (['administrators' => 'role_administrator', 'super_admins' => 'role_super_admin'] as $list => $roleKey) {
            foreach ((array) ($context['payload'][$list] ?? []) as $a) {
                if (is_array($a)) {
                    $rows[] = [
                        self::cell((string) ($a['login'] ?? '?')),
                        self::cell((string) ($a['email'] ?? '')),
                        self::cell($this->t->report($roleKey)),
                    ];
                }
            }
        }

        return ['headers' => $this->headers('col_login', 'col_email', 'col_role'), 'rows' => $rows];
    }

    /**
     * Content a client manages: posts, pages, media, and any other type with
     * published items that isn't WordPress or plugin machinery.
     *
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function contentTypesTable(array $context): array
    {
        $postTypes = (array) ($context['payload']['post_types'] ?? []);
        $counts    = (array) ($context['payload']['post_type_count'] ?? []);

        $rows = [];
        foreach ($postTypes as $slug => $label) {
            $slug = (string) $slug;
            $c    = array_map('intval', (array) ($counts[$slug] ?? []));
            // Attachments live in the 'inherit' status, never 'publish'.
            $published = ($c['publish'] ?? 0) + ($slug === 'attachment' ? ($c['inherit'] ?? 0) : 0);
            if (!in_array($slug, self::CORE_CONTENT_TYPES, true) && ($published === 0 || self::isMachineryPostType($slug))) {
                continue;
            }
            $rows[] = [
                self::cell($this->postTypeLabel($slug, (string) $label)),
                self::cell($this->formatNumber(array_sum($c))),
                self::cell($this->formatNumber($published)),
                self::cell($this->formatNumber($c['draft'] ?? 0)),
                self::cell($this->formatNumber($c['trash'] ?? 0)),
            ];
        }

        return ['headers' => $this->headers('col_type', 'col_total', 'col_published', 'col_draft', 'col_trash'), 'rows' => $rows];
    }

    private static function isMachineryPostType(string $slug): bool
    {
        if (in_array($slug, self::INTERNAL_POST_TYPES, true)) {
            return true;
        }
        foreach (self::CONFIG_POST_TYPE_PREFIXES as $prefix) {
            if (str_starts_with($slug, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function postTypeLabel(string $slug, string $label): string
    {
        if (in_array($slug, self::CORE_CONTENT_TYPES, true)) {
            return $this->t->report('content_' . $slug);
        }

        // The payload's label is the slug itself for most custom types.
        return $label !== '' && $label !== $slug ? $label : ucfirst(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function settingsTable(array $context): array
    {
        $p    = (array) ($context['payload'] ?? []);
        $rows = [
            [self::cell($this->t->report('setting_admin_email')), self::cell((string) ($p['website_administrator_email'] ?? '—'))],
            [self::cell($this->t->report('setting_permalinks')), self::cell((string) ($p['permalink_structure'] ?? '') ?: $this->t->report('permalinks_plain'))],
        ];

        $multilingual = Multilingual::fromPayload($p);
        if ($multilingual !== null) {
            $rows[] = [self::cell($this->t->report('setting_default_language')), self::cell($this->languageName($multilingual['default_language']) ?: '—')];
            $rows[] = [self::cell($this->t->report('setting_active_languages')), self::cell(implode(', ', array_map(
                fn (string $code): string => $this->languageName($code),
                $multilingual['active_languages']
            )))];
        }

        return ['headers' => $this->headers('col_setting', 'col_value'), 'rows' => $rows];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function constantsTable(array $context): array
    {
        $rows = [];
        foreach (HardeningConstants::rows((array) ($context['payload']['constants'] ?? [])) as $c) {
            $rows[] = [self::cell($c['name']), self::cell($c['display'], $c['flagged'] ? 'red' : null)];
        }

        return ['headers' => $this->headers('col_constant', 'col_value'), 'rows' => $rows];
    }

    /**
     * The ten largest tables — past that, nobody reads a "largest tables" list.
     *
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function databaseTablesTable(array $context): array
    {
        $tables = array_values(array_filter((array) ($context['payload']['database']['tables'] ?? []), 'is_array'));
        usort($tables, static fn (array $a, array $b): int => (int) ($b['size_bytes'] ?? 0) <=> (int) ($a['size_bytes'] ?? 0));

        $rows = [];
        foreach (array_slice($tables, 0, 10) as $table) {
            $overhead = (int) ($table['overhead_bytes'] ?? 0);
            $rows[] = [
                self::cell((string) ($table['name'] ?? '?')),
                self::cell($this->formatBytes((int) ($table['size_bytes'] ?? 0))),
                self::cell($this->formatNumber((int) ($table['row_count'] ?? 0))),
                self::cell($this->formatBytes($overhead), $overhead > 0 ? 'orange' : null),
            ];
        }

        return ['headers' => $this->headers('col_table', 'col_size', 'col_rows', 'col_overhead'), 'rows' => $rows];
    }

    /**
     * Writable is what matters in production (readability is a given for a
     * running site); a path that must be writable is not flagged.
     *
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function filePermissionsTable(array $context): array
    {
        $labels = [
            'wp_config'   => 'wp-config.php',
            'htaccess'    => '.htaccess',
            'root'        => $this->t->report('perm_root'),
            'index'       => 'index.php',
            'content_dir' => 'wp-content',
            'plugins_dir' => 'wp-content/plugins',
            'themes_dir'  => 'wp-content/themes',
            'uploads_dir' => 'wp-content/uploads',
        ];

        $rows = [];
        foreach ((array) ($context['payload']['filesystem']['permissions'] ?? []) as $key => $perm) {
            if (!is_array($perm)) {
                continue;
            }
            $writable = !empty($perm['writable']);
            $color    = in_array($key, self::WRITABLE_BY_DESIGN, true) ? null : ($writable ? 'orange' : 'green');
            $rows[] = [
                self::cell((string) ($labels[$key] ?? $key)),
                self::cell((string) ($perm['mode'] ?? '?')),
                self::cell($this->yesNo($writable), $color),
            ];
        }

        return ['headers' => $this->headers('col_location', 'col_mode', 'col_writable'), 'rows' => $rows];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function securityHeadersTable(array $context): array
    {
        $labels = [
            'strict-transport-security' => 'HSTS',
            'content-security-policy'   => 'Content-Security-Policy',
            'x-content-type-options'    => 'X-Content-Type-Options',
            'x-frame-options'           => 'X-Frame-Options',
            'referrer-policy'           => 'Referrer-Policy',
            'permissions-policy'        => 'Permissions-Policy',
        ];
        $headers = (array) ($context['probe']['http']['security_headers'] ?? []);

        $rows = [];
        foreach ($labels as $key => $label) {
            $value   = $headers[$key] ?? null;
            $present = $value !== null && $value !== '';
            $rows[]  = [self::cell($label), self::cell($present ? (string) $value : $this->t->report('missing'), $present ? 'green' : 'orange')];
        }

        return ['headers' => $this->headers('col_header', 'col_value'), 'rows' => $rows];
    }

    /**
     * Every known vulnerability across core, plugins and themes, cross-
     * referenced by merge_vulnerabilities() like the per-component tables.
     *
     * @param array<string, mixed> $context
     * @return array{headers: list<string>, rows: list<list<array{text: string, color: ?string}>>}
     */
    private function vulnerabilitiesTable(array $context): array
    {
        $payload = (array) ($context['payload'] ?? []);
        $bv      = (array) ($context['probe']['blogvault'] ?? []);
        $wf      = (array) ($context['probe']['wordfence'] ?? []);

        $all = merge_vulnerabilities(
            (array) ($bv['core']['vulnerabilities'] ?? []),
            (array) ($wf['core']['vulnerabilities'] ?? []),
            is_string($payload['wp_version'] ?? null) ? $payload['wp_version'] : null,
            $this->ignoredVulnerabilities
        );
        foreach (['plugin', 'theme'] as $type) {
            $all = array_merge($all, $this->componentVulnerabilities(
                $type,
                (array) ($payload[$type . 's'] ?? []),
                (array) ($bv[$type . 's']['items'] ?? []),
                (array) ($wf[$type . 's']['items'] ?? [])
            ));
        }

        $rows = [];
        foreach ($all as $v) {
            $rating  = strtolower((string) ($v['cvss_rating'] ?? ''));
            $patched = $v['patched_version'] ?? null;
            $fixed   = $patched !== null && $patched !== '';
            $rows[]  = [
                self::cell((string) ($v['title'] ?? '—')),
                self::cell(isset($v['cvss_score']) ? $this->formatNumber((float) $v['cvss_score'], 1) : '—', match (true) {
                    in_array($rating, ['critical', 'high'], true) => 'red',
                    $rating === 'medium'                          => 'orange',
                    default                                       => 'grey', // a vulnerability is never green
                }),
                self::cell($this->t->report($fixed ? 'label_available' : 'label_not_available'), $fixed ? 'green' : 'red'),
            ];
        }

        return ['headers' => [$this->t->report('col_vulnerability'), 'CVSS', $this->t->report('col_fix')], 'rows' => $rows];
    }

    /**
     * @param array<array-key, mixed>     $components payload.plugins / payload.themes
     * @param list<array<string, mixed>>  $bvItems
     * @param list<array<string, mixed>>  $wfItems
     * @return list<array<string, mixed>>
     */
    private function componentVulnerabilities(string $type, array $components, array $bvItems, array $wfItems): array
    {
        $bvBySlug = array_column($bvItems, null, 'slug');
        $wfBySlug = array_column($wfItems, null, 'slug');

        $found = [];
        foreach ($components as $c) {
            if (!is_array($c)) {
                continue;
            }
            $slug = SoftwareCatalog::normalizeSlug($type, (string) ($c['slug'] ?? ''));
            foreach (merge_vulnerabilities(
                (array) ($bvBySlug[$slug]['vulnerabilities'] ?? []),
                (array) ($wfBySlug[$slug]['vulnerabilities'] ?? []),
                is_string($c['version'] ?? null) ? $c['version'] : null,
                $this->ignoredVulnerabilities
            ) as $v) {
                $found[] = $v;
            }
        }

        return $found;
    }

    // --------------------------------------------------------------- helpers

    /** A language code ("fr", "pt-br") as its name in the report's locale ("Français"). */
    private function languageName(string $code): string
    {
        if ($code === '' || !class_exists(\Locale::class)) {
            return $code;
        }
        $name = \Locale::getDisplayLanguage(str_replace('-', '_', $code), $this->t->locale);

        return is_string($name) && $name !== $code ? mb_convert_case($name, MB_CASE_TITLE) : $code;
    }

    /** @return list<string> */
    private function headers(string ...$keys): array
    {
        return array_values(array_map(fn (string $k): string => $this->t->report($k), $keys));
    }

    /** @return array{text: string, color: ?string} */
    private static function cell(string $text, ?string $color = null): array
    {
        return ['text' => $text, 'color' => $color];
    }

    /** "[img url=…]" tokens, space-separated — the only place that decides which icon a status means. */
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
     * $spec['from'] (a dot-path or a list of them) resolved against $context.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $spec
     */
    private function rawFromSpec(array $context, array $spec): mixed
    {
        $from = $spec['from'] ?? null;

        return is_array($from)
            ? array_map(static fn (mixed $p): mixed => self::dotGet($context, (string) $p), $from)
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
