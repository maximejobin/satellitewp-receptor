<?php
/**
 * Default configuration. Committed to git — no secrets here.
 * Override anything in config/config.local.php (gitignored).
 */

declare(strict_types=1);

return [
    'app' => [
        // Sidebar footer label, bumped by hand on a release.
        'version'  => 'v1',
        // Public origin of the admin UI (e.g. https://manager.example.com) for
        // absolute links handed outside the browser (report data key, report
        // icons, OAuth callback). Empty: derived from the request's Host header.
        'base_url' => '',
    ],

    // Absolute path to the runtime data directory.
    'data_dir' => dirname(__DIR__) . '/data',

    'crm_sync_freshness' => [
        'default_seconds' => 24 * 3600,
        'overrides'       => [
            // 'swp_websites' => 3600,
        ],
    ],

    'data_sync_freshness' => [
        'endoflife_seconds'          => 2 * 3600,  // wordpress/php/mysql/mariadb branch data
        'wordpress_versions_seconds' => 2 * 3600,  // wordpress.org's own stable-check list
        'wordfence_seconds'          => 36 * 3600, // Wordfence Intelligence vulnerability catalogue
    ],

    // Accept unsigned payloads (no X-SWP-Signature). Dev only.
    'allow_unsigned' => false,

    // Dev only: a "Debugging tools" panel on the extraction page that re-runs
    // chosen probes on demand, even on a done (frozen) extraction, spending
    // real probe quota. Never enable in production.
    'debugging_tools' => false,

    // Max age (seconds) of X-SWP-Timestamp, in both directions.
    'replay_window_seconds' => 300,

    // Max accepted request body size in bytes.
    'max_body_bytes' => 10 * 1024 * 1024,

    // Probes executed by the pipeline, in order.
    'probes' => [
        'enabled' => ['http', 'dns', 'tls', 'rdap', 'pagespeed', 'blogvault', 'crm', 'wordfence', 'wporg', 'mail'],
        'connect_timeout' => 5,
        'timeout' => 15,
        'user_agent' => 'SatelliteWP-Manager/1.0',
    ],

    // Base URL of the RDAP bootstrap service.
    'rdap_base_url' => 'https://rdap.org',

    // PageSpeed Insights (Lighthouse). Without an API key the anonymous quota is
    // exhausted almost immediately (HTTP 429) — set one in config.local.php.
    // Get a key: https://developers.google.com/speed/docs/insights/v5/get-started
    'pagespeed' => [
        'api_key'  => null,
        'strategy' => 'both',   // 'mobile' | 'desktop' | 'both'
        'timeout'  => 60,       // PSI can legitimately take 30 s+
        // Lighthouse categories to request. Each adds to the response size.
        'categories' => ['performance', 'accessibility', 'best-practices', 'seo'],
        // BCP-47 locale for localized audit titles and displayValue strings.
        'locale' => 'fr',
        // Minimum performance score (0-100) below which the probe reports "warn".
        'min_score' => 90,
    ],

    // BlogVault API v6 (generic client: base_url, auth scheme, default params).
    'blogvault' => [
        'base_url' => 'https://api.blogvault.net/api/v6',
        'api_key'  => null,     // set in config.local.php
        // /sites?perPage=100 takes ~8 s; leave room for a slow account.
        'timeout'  => 45,
        // How the key is sent: 'bearer' | 'header' | 'query' | 'basic' | 'none'.
        'auth' => ['type' => 'bearer', 'name' => 'Authorization'],
        // Params/headers sent on every request (e.g. a partner or account id).
        'default_query'   => [],
        'default_headers' => [],
    ],

    // Wordfence Intelligence v3: a rate-limited full dump, never called during
    // a scan — `wordfence:refresh` (daily cron) caches it under data/reference/
    // and WordfenceProbe reads that cache only.
    'wordfence' => [
        'base_url' => 'https://www.wordfence.com/api/intelligence/v3',
        'api_key'  => null,     // set in config.local.php
        'timeout'  => 120,      // the feed is tens of MB per variant
    ],

    // Validation mailbox the plugin's test email is sent to (MailProbe). Gmail
    // IMAP with an app password (Google account → Security → 2-Step Verification
    // → App passwords); username and password belong in config.local.php.
    'mail' => [
        'host'          => 'imap.gmail.com',
        'port'          => 993,
        'username'      => null,
        'password'      => null,
        'timeout'       => 15,
        // A test email older than this is ignored.
        'max_age_hours' => 24,
    ],

    // endoflife.date products cached by `reference:refresh`, read offline by rules.
    'reference' => [
        'products' => ['php', 'wordpress', 'mysql', 'mariadb'],
    ],

    // External CRM/billing MySQL database — read-only except re-linking a
    // subscription to a website. Credentials in config.local.php; until host
    // and database are set, the CRM pages show "not connected".
    'crm_db' => [
        'host'     => null,
        'port'     => 3306,
        'database' => null,
        'username' => null,
        'password' => null,
        'charset'  => 'utf8mb4',
    ],

    // report.json (the Google Docs report feed) accepts a one-hour token from
    // the "Report data key" button, or this standing key as
    // `Authorization: Bearer <api_key>` (null: token only).
    'reports' => [
        'api_key' => null, // set in config.local.php
        // Report contract report.json resolves against.
        'bilan_de_sante' => __DIR__ . '/reports/bilan-de-sante.php',
    ],

    'external_links' => [
        'teamwork_project_url'         => 'https://central.s2bsolution.com/app/projects/{id}/overview/summary', // {id} = swp_clients.teamwork_id
        'hubspot_company_url'          => 'https://app-na3.hubspot.com/contacts/2543139/record/0-2/{id}', // {id} = swp_clients.hubspot_id
        'blogvault_client_url'         => null, // {id} = swp_clients.blogvault_client_id
        'blogvault_view_website'       => null, // {id} = swp_websites.blogvault_site_id, first 8 characters only
        'wordpress_edit_user'          => null, // {id} = swp_clients.id (unconfirmed: may need the WordPress user id instead)
        'wordpress_edit_subscription'  => null, // {id} = swp_subscriptions.id
    ],

    // Findings stay language-neutral; sentences come from config/lang/<locale>.php (?lang=fr|en).
    'lang' => [
        'dir'     => __DIR__ . '/lang',
        'default' => 'en',
    ],

    // Vulnerabilities deliberately left out of findings, reports and the
    // extraction page. One Wordfence Intelligence id each (the UUID shown in
    // the row's JSON on /data/vulnerabilities; a BlogVault id works too). They
    // stay visible, tagged "Ignored", in the /data/vulnerabilities catalogue.
    // Edit the list here: a list in config.local.php is merged by position with
    // this one, not appended. Re-score stored extractions afterwards with
    // `bin/swpmgr rules:reevaluate`.
    'vulnerabilities' => [
        'ignored' => [
            '112ed4f2-fe91-4d83-a3f7-eaf889870af4',
            '9fda5e15-fdf9-4b67-93d3-2dbfa94aefe9',
        ],
    ],

    // Rule engine. The catalogue itself lives in config/rules.php; thresholds
    // can be overridden per rule id without touching it, e.g.:
    //   'thresholds' => ['I1' => 1048576, 'M1' => 3],
    'rules' => [
        'catalog'    => __DIR__ . '/rules.php',
        'thresholds' => [],
    ],

    // Web UI protection.
    //
    // Two mechanisms, checked in this order:
    //   1. Google OAuth (auth.google.*) — the real one. Enabled as soon as
    //      client_id and client_secret are set. Anyone signing in must also be
    //      listed in the users file below.
    //   2. Basic auth (web.*) — fallback for local dev, used only while OAuth
    //      is unconfigured.
    // With neither set, the UI is open: protect it at the server level.
    'web' => [
        'user' => null,
        'pass_hash' => null,
    ],

    'auth' => [
        'google' => [
            // Google Cloud Console → APIs & Services → Credentials
            //   → Create credentials → OAuth client ID → Web application.
            // Both values belong in config.local.php, never here.
            'client_id'     => null,
            'client_secret' => null,
            // Must match a registered redirect URI exactly; null derives it
            // from app.base_url (or the request host) + /auth/callback.
            'redirect_uri'  => null,
        ],

        // Allowed accounts with their roles; seed the first admin with `users:add`.
        'users_file' => dirname(__DIR__) . '/data/users.json',
    ],
];
