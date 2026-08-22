<?php
/**
 * Default configuration. Committed to git — no secrets here.
 * Override anything in config/config.local.php (gitignored).
 */

declare(strict_types=1);

return [
    // Absolute path to the runtime data directory.
    'data_dir' => dirname(__DIR__) . '/data',

    // Accept unsigned payloads (no X-SWP-Signature). Dev only.
    'allow_unsigned' => false,

    // Max age (seconds) of X-SWP-Timestamp, in both directions.
    'replay_window_seconds' => 300,

    // Max accepted request body size in bytes.
    'max_body_bytes' => 10 * 1024 * 1024,

    // Probes executed by the pipeline, in order.
    'probes' => [
        'enabled' => ['http', 'dns', 'tls', 'rdap', 'pagespeed', 'blogvault', 'wordfence'],
        'connect_timeout' => 5,
        'timeout' => 15,
        'user_agent' => 'SatelliteWP-Xtractor/1.0',
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

    // BlogVault API v6 — the decided source for vulnerabilities/malware/backup.
    // The client is generic: set base_url, auth scheme and any always-present
    // params here (fill them from BlogVault's v6 docs), then call any endpoint
    // by path. api_key belongs in config.local.php.
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

    // Wordfence Intelligence v3 — second, independent vulnerability source
    // alongside BlogVault. Confirmed live: the feed is a full dump (~100+ MB,
    // no pagination) with a strict rate limit, not a per-site API — never
    // called during a site scan. `wordfence:refresh` (suggested: daily cron)
    // downloads it into data/reference/wordfence.json; WordfenceProbe reads
    // that local cache only.
    'wordfence' => [
        'base_url' => 'https://www.wordfence.com/api/intelligence/v3',
        'api_key'  => null,     // set in config.local.php
        'timeout'  => 120,      // the feed is tens of MB per variant
    ],

    // Server-side reference data (SOURCE 14). endoflife.date products cached
    // under data/reference/ by `reference:refresh` and read offline by rules.
    'reference' => [
        'products' => ['php', 'wordpress', 'mysql', 'mariadb'],
    ],

    // Display language. Findings and raw data stay language-neutral; sentences
    // are rendered from config/lang/<locale>.php. UI default is English; the
    // web UI accepts ?lang=fr to switch.
    'lang' => [
        'dir'     => __DIR__ . '/lang',
        'default' => 'en',
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
            // Must match an "Authorised redirect URI" registered on that client,
            // exactly, scheme and all. Leave null to derive it from the incoming
            // request (scheme://host/auth/callback) — fine behind a correctly
            // configured vhost, set it explicitly if you terminate TLS upstream.
            'redirect_uri'  => null,
        ],

        // Allowed accounts, one email per entry. The FIRST entry is the admin:
        // the only one who may add or remove users from the web UI.
        // Created on first use if absent; seed it with your own address.
        'users_file' => dirname(__DIR__) . '/data/users.json',
    ],
];
