<?php

declare(strict_types=1);

/**
 * Display contract of the "Bilan de santé" Google Docs report: which
 * {{variable}} gets which value, table or set of findings. What Manager
 * knows lives in the rules and probes; this file only decides what this one
 * report shows. Web\ReportBuilder resolves it.
 *
 * Field types:
 *   value         'from' is a dot-path (or list) into the report context
 *                 (payload.*, probe.<name>.*, site.*, meta.*, reference.*,
 *                 host, extraction_id, report_by, today). Optional
 *                 'transform' (a ReportBuilder formatter), 'default' (literal)
 *                 or 'default_label' (a 'report' lang key) when empty, and
 *                 'color_transform' or a literal 'color'.
 *   table         'source' names a ReportBuilder table.
 *   observations  findings whose category is in 'categories' OR whose id is
 *                 in 'ids' (neither = every finding), plus analyst-authored
 *                 observations filed under this field's name. The Doc picks
 *                 colours and form per placeholder: {{name:red,orange}},
 *                 {{name:red,orange:short}}, {{name::short}}.
 *
 * Every catalogue rule must land in at least one topic field (tested).
 */

return [
    'fields' => [
        'site'            => ['type' => 'value', 'from' => 'site.site_url', 'transform' => 'site_display'],
        'site_url'        => ['type' => 'value', 'from' => 'site.site_url'],
        'extraction_id'   => ['type' => 'value', 'from' => 'extraction_id'],
        'extraction_date' => ['type' => 'value', 'from' => 'meta.received_at', 'transform' => 'date'],
        'report_by'       => ['type' => 'value', 'from' => 'report_by', 'default' => '—'],
        'date'            => ['type' => 'value', 'from' => 'today', 'transform' => 'date'],
        // From the CRM snapshot (probes/crm.json); empty when the site isn't linked or has no single active plan.
        'client'           => ['type' => 'value', 'from' => 'probe.crm.clients', 'transform' => 'crm_client_labels', 'default' => '—'],
        'maintenance_plan' => ['type' => 'value', 'from' => 'probe.crm.maintenance_plan.name', 'default' => '—'],

        // Nom de domaine.
        'domain_name'            => ['type' => 'value', 'from' => 'host', 'transform' => 'registrable_domain', 'default' => '—'],
        'domain_registrar'       => ['type' => 'value', 'from' => 'probe.rdap.registrar', 'default' => '—'],
        'domain_date_creation'   => ['type' => 'value', 'from' => 'probe.rdap.created_at', 'transform' => 'date', 'default' => '—'],
        'domain_date_update'     => ['type' => 'value', 'from' => 'probe.rdap.updated_at', 'transform' => 'date', 'default' => '—'],
        'domain_date_expiration' => ['type' => 'value', 'from' => 'probe.rdap.expires_at', 'transform' => 'date', 'default' => '—'],
        'domain_nameservers'     => ['type' => 'value', 'from' => 'probe.rdap.nameservers', 'transform' => 'join_lines', 'default' => '—'],
        'email_spf_record'       => ['type' => 'value', 'from' => 'probe.dns.spf.record', 'default' => '—'],
        'email_dmarc_policy'     => ['type' => 'value', 'from' => 'probe.dns.dmarc.policy', 'default' => '—'],
        'domain_observations'    => ['type' => 'observations', 'categories' => ['DOMAIN', 'DNS', 'EMAIL']],

        // Infrastructure web.
        'os_name'     => ['type' => 'value', 'from' => 'payload.os_name', 'default' => '—'],
        'http_server' => ['type' => 'value', 'from' => 'payload.web_server', 'default' => '—'],
        // The same compression/protocol tests as rules B1–B5, never the
        // single encoding or version the main request happened to negotiate.
        'gzip'   => ['type' => 'value', 'from' => 'probe.http.compression.gzip', 'transform' => 'enabled_label', 'color_transform' => 'bool_green_red'],
        'brotli' => ['type' => 'value', 'from' => 'probe.http.compression.brotli', 'transform' => 'enabled_label', 'color_transform' => 'bool_green_red'],
        'http1'  => ['type' => 'value', 'from' => 'probe.http.protocols.http1_1', 'transform' => 'yes_no', 'color_transform' => 'bool_green_red'],
        'http2'  => ['type' => 'value', 'from' => 'probe.http.protocols.http2', 'transform' => 'yes_no', 'color_transform' => 'bool_green_red'],
        'http3'  => ['type' => 'value', 'from' => 'probe.http.protocols.http3_advertised', 'transform' => 'yes_no', 'color_transform' => 'bool_green_red'],
        'post_max'   => ['type' => 'value', 'from' => 'payload.php.post_max_size', 'transform' => 'php_size', 'default' => '—'],
        'upload_max' => ['type' => 'value', 'from' => 'payload.php.upload_max_filesize', 'transform' => 'php_size', 'default' => '—'],

        'php_version'            => ['type' => 'value', 'from' => 'payload.php.version', 'default' => '—'],
        'php_status'             => ['type' => 'value', 'from' => 'reference.php_eol', 'transform' => 'eol_status_label', 'color_transform' => 'eol_red_green'],
        'php_extensions'         => ['type' => 'value', 'from' => 'payload.php.extensions', 'transform' => 'join_comma', 'default_label' => 'none_f'],
        'php_disabled_functions' => ['type' => 'value', 'from' => 'payload.php.disable_functions', 'transform' => 'join_lines', 'default_label' => 'none_f'],

        'database'                => ['type' => 'value', 'from' => ['payload.database_type', 'payload.database_version'], 'transform' => 'database_label', 'default' => '—'],
        'database_type'           => ['type' => 'value', 'from' => 'payload.database_type', 'transform' => 'database_type', 'default' => '—'],
        'database_version'        => ['type' => 'value', 'from' => 'payload.database_version', 'transform' => 'database_version', 'default' => '—'],
        'database_status'         => ['type' => 'value', 'from' => ['reference.database_eol', 'reference.database_eol_date'], 'transform' => 'database_status_label', 'color_transform' => 'eol_red_green'],
        'database_prefix'         => ['type' => 'value', 'from' => 'payload.db_table_prefix', 'default' => '—'],
        'database_largest_tables' => ['type' => 'table', 'source' => 'database_tables'],

        // F3 is an UPDATES rule about the PHP runtime, placed here with the rest of PHP.
        'infrastructure_observations' => ['type' => 'observations', 'categories' => ['HOSTING', 'PHP', 'DATABASE', 'SSL', 'HTTP'], 'ids' => ['F3']],

        // WordPress.
        'wp_core_version'      => ['type' => 'value', 'from' => 'payload.wp_version', 'default' => '—'],
        'wp_core_status'       => ['type' => 'value', 'from' => 'reference.wordpress_status', 'transform' => 'wordpress_status_label', 'color_transform' => 'wordpress_status_color'],
        // Manager's own wordpress.org cache — the site's self-report can be stale.
        'wp_latest_version'    => ['type' => 'value', 'from' => 'reference.wordpress_latest_version', 'default' => '—'],
        'wp_install_type'      => ['type' => 'value', 'from' => ['payload.is_multisite', 'payload.multisite_type'], 'transform' => 'install_type'],
        // The same constants as rule F12: undefined WP_AUTO_UPDATE_CORE is WordPress's minor-only default.
        'wp_core_auto_update'  => ['type' => 'value', 'from' => 'payload.constants', 'transform' => 'auto_update_core'],
        'wp_core_observations' => ['type' => 'observations', 'ids' => ['F1', 'F2', 'F12']],

        // Thèmes.
        'wp_theme'               => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme', 'default' => '—'],
        'wp_theme_version'       => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme_version', 'default' => '—'],
        'wp_parent_theme'        => ['type' => 'value', 'from' => 'payload.themes', 'transform' => 'active_theme_parent', 'default_label' => 'none_m'],
        'wp_themes_list'         => ['type' => 'table', 'source' => 'themes'],
        'wp_themes_observations' => ['type' => 'observations', 'ids' => ['F5']],

        // Extensions.
        'wp_plugins_list'         => ['type' => 'table', 'source' => 'plugins'],
        'wp_plugins_list_mu'      => ['type' => 'table', 'source' => 'mu_plugins'],
        'wp_plugins_list_dropins' => ['type' => 'table', 'source' => 'dropins'],
        'wp_plugins_observations' => ['type' => 'observations', 'ids' => ['F4', 'F7', 'F8']],

        // Sécurité et accès.
        'vulnerabilities'       => ['type' => 'table', 'source' => 'vulnerabilities'],
        'security_headers'      => ['type' => 'table', 'source' => 'security_headers'],
        'file_permissions'      => ['type' => 'table', 'source' => 'file_permissions'],
        'security_observations' => ['type' => 'observations', 'categories' => ['SECURITY']],
        // Same sources as the extraction page's Users card.
        'wp_users_count'        => ['type' => 'value', 'from' => 'payload.users_count.total_users', 'default' => '—'],
        'wp_admins_count'       => ['type' => 'value', 'from' => 'payload.administrators', 'transform' => 'count'],
        'wp_users_admins'       => ['type' => 'table', 'source' => 'administrators'],
        'wp_users_observations' => ['type' => 'observations', 'categories' => ['USERS']],

        // Performance.
        'performance_desktop_score' => ['type' => 'value', 'from' => 'probe.pagespeed.desktop.scores.performance', 'transform' => 'score_100', 'color_transform' => 'lighthouse', 'default' => '—'],
        'performance_mobile_score'  => ['type' => 'value', 'from' => 'probe.pagespeed.mobile.scores.performance', 'transform' => 'score_100', 'color_transform' => 'lighthouse', 'default' => '—'],
        'performance_observations'  => ['type' => 'observations', 'categories' => ['PERFORMANCE', 'CACHE']],

        // Contenu et réglages.
        'wp_content'               => ['type' => 'table', 'source' => 'content_types'],
        'wp_content_observations'  => ['type' => 'observations', 'categories' => ['CONTENT']],
        'wp_settings'              => ['type' => 'table', 'source' => 'settings'],
        'wp_constants'             => ['type' => 'table', 'source' => 'constants'],
        'wp_settings_observations' => ['type' => 'observations', 'categories' => ['CRON']],

        // Visibilité web — the template has no separate observations field here.
        'visibility' => ['type' => 'observations', 'categories' => ['SEO']],

        // Every finding, every colour. A manual observation joins only the one
        // field named in its 'section'; file it here to see it in the action plan.
        'all_observations' => ['type' => 'observations'],
    ],
];
