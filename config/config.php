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
        'enabled' => ['http', 'dns', 'tls', 'rdap', 'pagespeed'],
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
        'base_url' => '', // e.g. https://api.blogvault.net/v6  (from their docs)
        'api_key'  => null,
        'timeout'  => 20,
        // How the key is sent: 'bearer' | 'header' | 'query' | 'basic' | 'none'.
        'auth' => ['type' => 'bearer', 'name' => 'Authorization'],
        // Params/headers sent on every request (e.g. a partner or account id).
        'default_query'   => [],
        'default_headers' => [],
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

    // Web UI protection. Set both in config.local.php to enable Basic auth.
    // 'web_pass_hash' is a password_hash() value.
    'web' => [
        'user' => null,
        'pass_hash' => null,
    ],
];
