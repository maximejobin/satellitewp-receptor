<?php

declare(strict_types=1);

/**
 * Display contract for the "Bilan de santé" Google Docs report template —
 * which {{variable}} in the document gets which value, table, or
 * category-grouped set of findings, and nothing about how Xtractor
 * measured any of it.
 *
 * Having a piece of data and deciding it belongs in this particular report
 * are two separate facts: config/rules.php and the probes decide what
 * Xtractor KNOWS about a site (language-neutral, true regardless of any
 * report); this file decides what ONE report SHOWS and where. Web\ReportBuilder
 * is the only code that reads this shape — it has no idea what a "bilan de
 * santé" is, it just resolves whatever contract it's handed against
 * whatever data context it's given. A second report (a monthly summary, an
 * English variant, …) is a second file in this directory, read the same
 * way — never a reason to touch the rules engine or this one's mapping.
 *
 * One flat 'fields' map, one entry per {{variable}}, each tagged 'type' —
 * this is what lets the Apps Script stay a single generic dispatcher
 * instead of three separate hardcoded loops (one per kind of thing it used
 * to know how to paste). Three types exist today:
 *
 *   value            — one {{variable}} = one string. 'from' is a dot-path
 *                       (or a list of dot-paths) into the data context
 *                       ReportBuilder is given (payload.*, probe.<name>.*,
 *                       site.*, meta.*, host, extraction_id — see
 *                       Router::extractionReportJson()). 'transform' names
 *                       one of ReportBuilder's small, reusable formatters
 *                       (never one-off logic written inline here). 'default'
 *                       fills in when the resolved value is empty. An
 *                       optional literal 'color' (green/orange/red/blue/grey)
 *                       renders that value's text in colour — no field needs
 *                       one yet, the mechanism is just ready.
 *
 *   table             — one {{variable}} = one named table builder
 *                       (ReportBuilder's own registry, keyed by 'source' — a
 *                       table's row shape, and which cells get coloured and
 *                       why, is genuinely specific to its data, not worth
 *                       forcing into a declarative form here).
 *
 *   observations      — one {{variable}} = every translated finding whose
 *                       category is in 'categories', OR whose id is
 *                       explicitly listed in 'ids' (an id escape hatch for
 *                       exactly this case: D1/SPF is category EMAIL, not
 *                       DOMAIN, but the real template wants it in the
 *                       domain section anyway) — pastille and all. Neither
 *                       given (both absent/empty) means every finding
 *                       qualifies — for a field whose only filter is colour
 *                       (below). An optional 'colors' (e.g. ['red',
 *                       'orange']) narrows the result to just those
 *                       pastilles, regardless of topic — this is what lets
 *                       "Enjeux"/"Bonne pratique"/"Information"-style
 *                       sections exist alongside (not instead of) the
 *                       topic-scoped ones; omitted/empty means no colour
 *                       restriction. Any analyst-authored observation
 *                       (DataStore::readObservations()) whose own
 *                       'section' names this field, and whose 'include' is
 *                       true, joins the same list — 'colors' filters it
 *                       exactly like a rule finding, by its own colour.
 *                       This is the ONLY place any of these decisions is
 *                       made — not in the report script, so a filled-in
 *                       report and a future pastille-per-section tally can
 *                       never disagree about what counts.
 */

return [
    'fields' => [
        'site'                    => ['type' => 'value', 'from' => 'site.site_url', 'transform' => 'site_display'],
        'site_url'                => ['type' => 'value', 'from' => 'site.site_url'],
        'wp_core_version'         => ['type' => 'value', 'from' => 'payload.wp_version', 'default' => '—'],
        'php_version'             => ['type' => 'value', 'from' => 'payload.php.version', 'default' => '—'],
        'database'                => ['type' => 'value', 'from' => ['payload.database_type', 'payload.database_version'], 'transform' => 'join_space'],

        // Server/infrastructure detail. {{os_name}} is NOT wired — nothing
        // in payload or any probe carries the host's OS at all (confirmed
        // by searching a real payload); it would need a new plugin-side
        // collector field (e.g. php_uname()), not something Xtractor can
        // derive from here.
        'http_server'             => ['type' => 'value', 'from' => 'probe.http.fingerprint.server', 'default' => '—'],
        // "Enabled"/"Not enabled", red/green — distinct wording from the
        // HTTP version fields below (which keep Yes/No).
        'gzip'                    => ['type' => 'value', 'from' => 'probe.http.gzip', 'transform' => 'enabled_label', 'color_transform' => 'bool_green_red'],
        'brotli'                  => ['type' => 'value', 'from' => 'probe.http.brotli', 'transform' => 'enabled_label', 'color_transform' => 'bool_green_red'],
        // Whichever ONE version the probe's own request actually used —
        // not a survey of every version this server supports (one request
        // only ever observes one).
        'http1'                   => ['type' => 'value', 'from' => 'reference.http1', 'transform' => 'yes_no', 'color_transform' => 'bool_green_red'],
        'http2'                   => ['type' => 'value', 'from' => 'reference.http2', 'transform' => 'yes_no', 'color_transform' => 'bool_green_red'],
        'http3'                   => ['type' => 'value', 'from' => 'reference.http3', 'transform' => 'yes_no', 'color_transform' => 'bool_green_red'],
        'php_status'              => ['type' => 'value', 'from' => 'reference.php_eol', 'transform' => 'eol_status_label'],
        // Comma-separated (join_comma), not one per line — reads as one
        // paragraph for a list this long rather than dozens of stacked
        // lines.
        'php_extensions'          => ['type' => 'value', 'from' => 'payload.php.extensions', 'transform' => 'join_comma'],
        'php_disabled_functions'  => ['type' => 'value', 'from' => 'payload.php.disable_functions', 'transform' => 'join_lines'],
        'database_type'           => ['type' => 'value', 'from' => 'payload.database_type', 'default' => '—'],
        'database_version'        => ['type' => 'value', 'from' => 'payload.database_version', 'default' => '—'],
        // "Supported until <date>" / "Not supported since <date>" — the
        // date is endoflife.date's own branch date, same source
        // /data/databases already reads (see database_status_label()).
        'database_status'         => ['type' => 'value', 'from' => ['reference.database_eol', 'reference.database_eol_date'], 'transform' => 'database_status_label'],
        'database_prefix'         => ['type' => 'value', 'from' => 'payload.db_table_prefix', 'default' => '—'],

        'extraction_id'           => ['type' => 'value', 'from' => 'extraction_id'],
        'extraction_date'         => ['type' => 'value', 'from' => 'meta.received_at', 'transform' => 'date_ymd'],
        // The signed-in analyst who clicked "Report data key" (captured on
        // the token itself, not a session — report.json runs with none) and
        // today's date, Y-m-d, at the moment the report was fetched.
        'report_by'               => ['type' => 'value', 'from' => 'report_by', 'default' => '—'],
        'date'                    => ['type' => 'value', 'from' => 'today'],

        'domain_name'             => ['type' => 'value', 'from' => 'host', 'transform' => 'registrable_domain', 'default' => '—'],
        'domain_registrar'        => ['type' => 'value', 'from' => 'probe.rdap.registrar', 'default' => '—'],
        'domain_date_creation'    => ['type' => 'value', 'from' => 'probe.rdap.created_at', 'transform' => 'date_ymd'],
        'domain_date_update'      => ['type' => 'value', 'from' => 'probe.rdap.updated_at', 'transform' => 'date_ymd'],
        'domain_date_expiration'  => ['type' => 'value', 'from' => 'probe.rdap.expires_at', 'transform' => 'date_ymd'],
        'domain_nameservers'      => ['type' => 'value', 'from' => 'probe.rdap.nameservers', 'transform' => 'join_lines'],

        'domain_observations' => ['type' => 'observations', 'categories' => ['DOMAIN'], 'ids' => ['D1']],

        // Infrastructure web — server-level facts: PHP config, database,
        // TLS cert, disk/filesystem. F3 (PHP version supported) is
        // UPDATES-categorised but PHP-infra in nature, hence the explicit
        // id rather than a bare category filter — same D1 pattern as above.
        'infrastructure_observations' => [
            'type' => 'observations',
            'categories' => ['HOSTING', 'PHP', 'DATABASE', 'SSL'],
            'ids' => ['F3'],
        ],
        'wp_install_type'         => ['type' => 'value', 'from' => ['payload.is_multisite', 'payload.multisite_type'], 'transform' => 'install_type'],
        'database_largest_tables' => ['type' => 'table', 'source' => 'database_tables'],
        'file_permissions'        => ['type' => 'table', 'source' => 'file_permissions'],
        'security_headers'        => ['type' => 'table', 'source' => 'security_headers'],
        'vulnerabilities'         => ['type' => 'table', 'source' => 'vulnerabilities'],

        // WordPress core. ({{wp_core_version}} is defined once, at the top.)
        // Xtractor's own reference cache (wordpress.org's stable-check,
        // refreshed by reference:refresh) — not the extraction's own
        // payload.core_update.available_version, which is only the site's
        // own WordPress install self-reporting (can be stale/blocked).
        'wp_latest_version'       => ['type' => 'value', 'from' => 'reference.wordpress_latest_version', 'default' => '—'],
        // Shown exactly as the plugin reports it (payload.* convention —
        // "as received", never independently verified/reformatted here):
        // its own value domain (bool vs. 'minor'/'major') isn't pinned
        // down, so guessing a translated label would risk showing the
        // wrong thing confidently instead of the right thing plainly.
        'wp_core_auto_update'     => ['type' => 'value', 'from' => 'payload.core_update.auto_update_core', 'default' => '—'],
        'wp_core_observations' => ['type' => 'observations', 'ids' => ['F1', 'F2']],

        // Thèmes.
        'wp_theme'                  => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme', 'default' => '—'],
        'wp_theme_version'          => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme_version', 'default' => '—'],
        'wp_parent_theme'           => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme_parent', 'default' => '—'],
        'wp_themes_list'            => ['type' => 'table', 'source' => 'themes'],
        'wp_themes_observations' => ['type' => 'observations', 'ids' => ['F5']],

        // Extensions (plugins).
        'wp_plugins_list'            => ['type' => 'table', 'source' => 'plugins'],
        'wp_plugins_list_mu'         => ['type' => 'table', 'source' => 'mu_plugins'],
        'wp_plugins_list_dropins'    => ['type' => 'table', 'source' => 'dropins'],
        'wp_plugins_observations' => ['type' => 'observations', 'ids' => ['F4', 'F7']],

        // Sécurité et accès.
        'security_observations' => ['type' => 'observations', 'categories' => ['SECURITY']],
        'wp_users_admins'          => ['type' => 'table', 'source' => 'administrators'],
        'wp_users_observations' => ['type' => 'observations', 'categories' => ['USERS']],

        // Performance — Lighthouse/PageSpeed facts (PERFORMANCE) plus
        // object-cache/autoload weight (CACHE): the same grouping the web
        // UI's own §Performance card already uses.
        'performance_observations'    => ['type' => 'observations', 'categories' => ['PERFORMANCE', 'CACHE']],
        'performance_desktop_score'      => ['type' => 'value', 'from' => 'probe.pagespeed.desktop.scores.performance', 'default' => '—'],
        'performance_mobile_score'       => ['type' => 'value', 'from' => 'probe.pagespeed.mobile.scores.performance', 'default' => '—'],

        // Contenu et réglages.
        'wp_content'                 => ['type' => 'table', 'source' => 'content_types'],
        'wp_content_observations' => ['type' => 'observations', 'categories' => ['CONTENT']],
        'wp_settings'                => ['type' => 'table', 'source' => 'settings'],
        // No dedicated "settings" category exists — CRON (WP-Cron health)
        // is the best fit among what's left unplaced elsewhere in this
        // contract; nothing else currently maps here.
        'wp_settings_observations' => ['type' => 'observations', 'categories' => ['CRON']],

        // Visibilité web — one field, no separate _observations
        // variable in the template, so this one carries the SEO findings
        // directly.
        'visibility' => ['type' => 'observations', 'categories' => ['SEO']],

        // {{all_observations}} — every finding across every category,
        // ALL colours, regardless of which topic section it also appears
        // under. Deliberately unfiltered here (no 'colors' key) — the Doc
        // template itself picks the colour(s) it wants per placeholder via
        // an optional ":colour[,colour...]" suffix on the {{variable}} name
        // (e.g. {{all_observations:red}}, {{all_observations:red,orange}}),
        // parsed by the Apps Script (rapport-poc.gs), not this contract —
        // "dans mon appel" was the explicit ask, i.e. chosen at the point of
        // use in the Doc, not baked into one fixed PHP field per colour
        // combination. A bare {{all_observations}} (no suffix) shows every
        // colour — there is deliberately no hidden default anywhere (script
        // or contract): for a "Plan d'action" of just red+orange, write
        // {{all_observations:red,orange}} in the Doc. NOTE one real limit:
        // an analyst-authored observation only joins a field when its own
        // stored 'section' equals that field's name exactly — one filed
        // under e.g. 'domain_observations' will NOT also appear here
        // automatically, since an observation has exactly one section. To
        // have a manual item show up in the action plan too, file it
        // directly under 'all_observations'.
        'all_observations' => ['type' => 'observations'],

        // {{maintenance_plan}} / {{maintenance_renewal_date}} — NOT wired.
        // Both need a real fact this contract has no way to reach: which
        // CRM (swp_websites) row this extraction's site actually is. No
        // such link exists yet (site_id is an Xtractor UUID, swp_websites
        // has its own int id — never matched, on purpose, see CLAUDE.md).
        // Building this means deciding how that match happens (by host?
        // by a stored pairing?) before it's a report field question at
        // all — on standby until that's actually decided.
    ],
];
