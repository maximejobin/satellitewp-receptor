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
        'strategy' => 'mobile', // 'mobile' | 'desktop' | 'both'
        'timeout'  => 60,       // PSI can legitimately take 30 s+
        // Minimum performance score (0-100) below which the probe reports "warn".
        'min_score' => 90,
    ],

    // Web UI protection. Set both in config.local.php to enable Basic auth.
    // 'web_pass_hash' is a password_hash() value.
    'web' => [
        'user' => null,
        'pass_hash' => null,
    ],
];
