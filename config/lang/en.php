<?php
/**
 * English display strings. This is the ONLY place English text for rules lives;
 * findings.json stays language-neutral. Keyed by rule id: 'title' (short label),
 * 'fail' (sentence when the check fails), optional 'pass' (positive statement).
 * Placeholders: {observed}, {threshold}, and rule-specific data (e.g. {eol_date}).
 */

declare(strict_types=1);

return [
    'ui' => [
        'sites'          => 'Sites',
        'site'           => 'Site',
        'extraction'     => 'Extraction',
        'findings'       => 'Findings',
        'information'    => 'Information',
        'search'         => 'Search',
        'no_data'        => 'No data yet.',
        'received'       => 'Received',
        'status'         => 'Status',
        'rule'           => 'Rule',
        'category'       => 'Category',
        'severity'       => 'Severity',
        'observation'    => 'Observation',
        'legend'         => 'Category legend',
        'eol'            => 'end of life',
        'supported_until' => 'supported until',
        'passed'         => 'compliant',
        'not_applicable' => 'not applicable',
        'unknown'        => 'undetermined',
        'compliant_hidden' => 'Compliant, not-applicable and undetermined rules',
    ],
    'status' => [
        'pass'    => 'Compliant',
        'fail'    => 'To fix',
        'na'      => 'N/A',
        'unknown' => 'Undetermined',
    ],
    'severity' => [
        'C' => 'Critical',
        'E' => 'High',
        'M' => 'Medium',
        'I' => 'Info',
    ],
    'pastille' => [
        'green'  => 'Good',
        'orange' => 'Attention',
        'red'    => 'Critical',
        'blue'   => 'Info',
        'grey'   => 'N/A',
    ],
    'categories' => [
        'DOMAIN'      => 'Domain',
        'SSL'         => 'SSL / TLS',
        'SECURITY'    => 'Security',
        'HTTP'        => 'HTTP',
        'DNS'         => 'DNS',
        'EMAIL'       => 'Email',
        'PERFORMANCE' => 'Performance',
        'SEO'         => 'SEO',
        'UPDATES'     => 'Versions & updates',
        'PHP'         => 'PHP',
        'DATABASE'    => 'Database',
        'HOSTING'     => 'Hosting',
        'CRON'        => 'Cron',
        'USERS'       => 'Users',
        'CACHE'       => 'Cache',
        'CONTENT'     => 'Content',
    ],
    'rules' => [
        // SSL
        'A1'  => ['title' => 'SSL certificate valid', 'fail' => 'The SSL certificate has expired. Renew it immediately.', 'pass' => 'The SSL certificate is valid.'],
        'A2'  => ['title' => 'Certificate expiry not imminent', 'fail' => 'The certificate expires in {observed} days. Check auto-renewal.', 'pass' => 'The certificate is valid for {observed} more days.'],
        'A3'  => ['title' => 'Complete certificate chain', 'fail' => 'The certificate chain is incomplete (missing intermediate).'],
        'A4'  => ['title' => 'Hostname covered by certificate', 'fail' => 'The site hostname is not covered by the certificate (CN/SAN).'],
        'A5'  => ['title' => 'Trusted issuer (not self-signed)', 'fail' => 'The certificate is self-signed: browsers will show a security warning.'],
        'A6'  => ['title' => 'Legacy TLS 1.0/1.1 disabled', 'fail' => 'The server still accepts {observed}. Disable TLS 1.0 and 1.1.', 'pass' => 'Only modern TLS versions are accepted.'],
        'A8'  => ['title' => 'HSTS header present', 'fail' => 'The Strict-Transport-Security header is missing.'],
        'A10' => ['title' => 'HTTP to HTTPS redirect', 'fail' => 'The site answers over HTTP without redirecting to HTTPS. Add a 301 redirect.', 'pass' => 'HTTP is redirected to HTTPS.'],
        // HTTP
        'B1'  => ['title' => 'HTML compression', 'fail' => 'The HTML is served without compression. Enable gzip or Brotli.', 'pass' => 'The HTML is compressed ({observed}).'],
        'B1b' => ['title' => 'Static asset compression', 'fail' => 'Static assets are served without compression, unlike the HTML.', 'pass' => 'Static assets are compressed ({observed}).'],
        'B2'  => ['title' => 'Brotli compression', 'fail' => 'Brotli is not used ({observed}); it compresses better than gzip.'],
        'B3'  => ['title' => 'HTTP/2 supported', 'fail' => 'The site is served over HTTP/{observed}. Enabling HTTP/2 speeds up loading.', 'pass' => 'The site is served over HTTP/{observed}.'],
        'B6'  => ['title' => 'Cache headers on static assets', 'fail' => 'Static assets have a cache of {observed}s (expected at least {threshold}s).'],
        'B7a' => ['title' => 'X-Content-Type-Options: nosniff', 'fail' => 'The X-Content-Type-Options header is missing.'],
        'B7b' => ['title' => 'Clickjacking protection', 'fail' => 'Neither X-Frame-Options nor Content-Security-Policy is present.'],
        'B7c' => ['title' => 'Content-Security-Policy present', 'fail' => 'No Content-Security-Policy is defined.'],
        'B7d' => ['title' => 'Referrer-Policy set', 'fail' => 'The Referrer-Policy header is missing.'],
        'B8'  => ['title' => 'Secure cookies', 'fail' => 'Cookies are missing security attributes: {observed}.'],
        'B9'  => ['title' => 'No server version leak', 'fail' => 'The server leaks its version: {observed}.'],
        // DNS
        'C1'  => ['title' => 'IPv6 (AAAA record)', 'fail' => 'No AAAA record: the site is not reachable over IPv6.'],
        'C2'  => ['title' => 'CAA record present', 'fail' => 'No CAA record: any certificate authority can issue a certificate.'],
        'C5'  => ['title' => 'Short redirect chain', 'fail' => 'The redirect chain is too long or loops ({observed}).'],
        'C6'  => ['title' => 'Response time (TTFB)', 'fail' => 'The TTFB is {observed} ms (threshold {threshold} ms).', 'pass' => 'Fast response time ({observed} ms).'],
        'C7'  => ['title' => 'Site available', 'fail' => 'The site answers HTTP {observed}.', 'pass' => 'The site is available (HTTP {observed}).'],
        'C8'  => ['title' => 'Correct 404 page', 'fail' => 'A non-existent URL answers 200 instead of 404 (soft 404).'],
        'C9'  => ['title' => 'robots.txt present and not blocking', 'fail' => 'robots.txt is {observed}.', 'pass' => 'robots.txt is present.'],
        'C10' => ['title' => 'sitemap referenced in robots.txt', 'fail' => 'No sitemap is referenced in robots.txt.', 'pass' => '{observed} sitemap(s) declared.'],
        // Email
        'D1'  => ['title' => 'SPF record present', 'fail' => 'No SPF record: the site\'s emails may be rejected.', 'pass' => 'An SPF record is configured.'],
        'D3'  => ['title' => 'DMARC with active policy', 'fail' => 'DMARC is {observed}. Publish a policy (p=quarantine or p=reject).', 'pass' => 'DMARC is active (p={observed}).'],
        'D4'  => ['title' => 'MX records resolvable', 'fail' => 'No MX record: the domain cannot receive email.'],
        // Domain
        'W1'  => ['title' => 'Domain expiry not imminent', 'fail' => 'The domain expires in {observed} days. Renew it soon.', 'pass' => 'The domain is valid for {observed} more days.'],
        // Performance
        'PS1' => ['title' => 'Lighthouse performance (mobile)', 'fail' => 'Mobile performance score is {observed}/100 (threshold {threshold}).', 'pass' => 'Good mobile performance score ({observed}/100).'],
        'PS2' => ['title' => 'Lighthouse accessibility (mobile)', 'fail' => 'Accessibility score is {observed}/100 (threshold {threshold}).'],
        'PS3' => ['title' => 'Lighthouse SEO (mobile)', 'fail' => 'SEO score is {observed}/100 (threshold {threshold}).'],
        'PS4' => ['title' => 'LCP under threshold', 'fail' => 'Mobile LCP is {observed} ms (threshold {threshold} ms).'],
        // Versions & updates
        'F1'  => ['title' => 'WordPress core up to date', 'fail' => 'WordPress {observed} is not up to date (available: {available}).', 'pass' => 'WordPress {observed} is up to date.'],
        'F2'  => ['title' => 'WordPress branch supported', 'fail' => 'WordPress {observed} has reached end of life ({eol_date}). Move to a supported branch.', 'pass' => 'The WordPress branch is still supported.'],
        'F3'  => ['title' => 'PHP version supported', 'fail' => 'PHP {observed} is no longer supported (end of life {eol_date}). Plan an upgrade.', 'pass' => 'PHP {observed} is supported (until {eol_date}).'],
        'F4'  => ['title' => 'Plugins up to date', 'fail' => '{observed} plugin(s) have an update available: {names}.', 'pass' => 'All plugins are up to date.'],
        'F5'  => ['title' => 'Themes up to date', 'fail' => '{observed} theme(s) have an update available.', 'pass' => 'All themes are up to date.'],
        'F7'  => ['title' => 'Plugin requirements met', 'fail' => '{observed} plugin(s) require a higher PHP/WP version than the environment: {names}.'],
        // PHP
        'G1'  => ['title' => 'Sufficient memory_limit', 'fail' => 'memory_limit is {observed}, below the recommended 256M.', 'pass' => 'memory_limit is {observed}.'],
        'G4'  => ['title' => 'Sufficient max_input_vars', 'fail' => 'max_input_vars is {observed} (recommended at least {threshold}).'],
        'G5'  => ['title' => 'Recommended PHP extensions', 'fail' => 'Missing PHP extensions: {observed}.', 'pass' => 'All recommended PHP extensions are present.'],
        'G6'  => ['title' => 'OPcache enabled', 'fail' => 'The OPcache extension is not loaded: PHP performance suffers.', 'pass' => 'OPcache is enabled.'],
        // Database
        'H1'  => ['title' => 'Database version supported', 'fail' => '{observed} is no longer supported (end of life {eol_date}). Plan an upgrade.', 'pass' => 'The database version is supported (until {eol_date}).'],
        'H4'  => ['title' => 'Table fragmentation under control', 'fail' => 'Tables carry {observed} bytes of overhead (threshold {threshold}).'],
        'H5'  => ['title' => 'Expired transients not piling up', 'fail' => '{observed} expired transients remain in the database (threshold {threshold}).'],
        'H9'  => ['title' => 'Non-default table prefix', 'fail' => 'The table prefix is the default "wp_"; changing it hinders automated attacks.'],
        // Cache
        'I1'  => ['title' => 'Autoloaded options weight', 'fail' => 'Autoloaded options weigh {observed} bytes (threshold {threshold}).', 'pass' => 'Autoloaded options are within budget.'],
        'I4'  => ['title' => 'Persistent object cache', 'fail' => 'No persistent object cache (Redis/Memcached) is configured.', 'pass' => 'A persistent object cache is configured.'],
        // Cron
        'J2'  => ['title' => 'No overdue cron events', 'fail' => '{observed} cron events are overdue: WP-Cron probably is not running.', 'pass' => 'No overdue cron events.'],
        'J3'  => ['title' => 'Reasonable cron event count', 'fail' => '{observed} scheduled events (threshold {threshold}).'],
        // Security
        'K1'  => ['title' => 'WP_DEBUG off in production', 'fail' => 'WP_DEBUG is enabled in production.', 'pass' => 'WP_DEBUG is disabled.'],
        'K2'  => ['title' => 'WP_DEBUG_DISPLAY off', 'fail' => 'WP_DEBUG_DISPLAY is on: PHP errors show to visitors.'],
        'K4'  => ['title' => 'File editing disabled', 'fail' => 'The admin file editor is active: set DISALLOW_FILE_EDIT to true.', 'pass' => 'The admin file editor is disabled.'],
        'K6'  => ['title' => 'SSL forced on admin', 'fail' => 'FORCE_SSL_ADMIN is not enabled.', 'pass' => 'SSL is forced on the admin area.'],
        // Hosting
        'L1'  => ['title' => 'Sufficient free disk space', 'fail' => 'Only {observed}% of disk space is free (threshold {threshold}%).', 'pass' => '{observed}% of disk space is free.'],
        'L4'  => ['title' => 'Uploads directory writable', 'fail' => 'The uploads directory is not writable: uploads and updates will fail.'],
        'L5'  => ['title' => 'Core not writable in production', 'fail' => 'Core files are writable by the web server: harden the permissions.'],
        // Users
        'M1'  => ['title' => 'Administrator count under control', 'fail' => 'The site has {observed} administrators (threshold {threshold}).', 'pass' => 'The site has {observed} administrator(s).'],
        'M2'  => ['title' => 'No default "admin" account', 'fail' => 'An administrator account uses the default "admin" login.', 'pass' => 'No default "admin" account.'],
    ],
];
