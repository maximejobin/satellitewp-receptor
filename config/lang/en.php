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
        'sites'          => 'Extractions',
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
        'B1'  => ['title' => 'Gzip compression', 'fail' => 'The server does not return gzip when gzip is the only encoding offered. Enable gzip compression.', 'pass' => 'The server returns gzip-compressed content when asked.'],
        'B2'  => ['title' => 'Brotli compression', 'fail' => 'The server does not return Brotli when Brotli is the only encoding offered; it compresses better than gzip.', 'pass' => 'The server returns Brotli-compressed content when asked.'],
        'B3'  => ['title' => 'HTTP/2 supported', 'fail' => 'The site does not negotiate HTTP/2. Enabling it speeds up loading.', 'pass' => 'The site negotiates HTTP/2.'],
        'B4'  => ['title' => 'HTTP/1.1 supported', 'fail' => 'The site does not respond over HTTP/1.1 when it is explicitly requested.', 'pass' => 'The site responds over HTTP/1.1 when requested.'],
        'B5'  => ['title' => 'HTTP/3 advertised', 'fail' => 'The site does not advertise HTTP/3 support (no "h3" entry in its Alt-Svc header).', 'pass' => 'The site advertises HTTP/3 support (Alt-Svc: h3).'],
        'B6'  => ['title' => 'Cache headers on static assets', 'fail' => 'Static assets have a cache of {observed}s (expected at least {threshold}s).'],
        'B7a' => ['title' => 'X-Content-Type-Options: nosniff', 'fail' => 'The X-Content-Type-Options header is missing.'],
        'B7b' => ['title' => 'Clickjacking protection', 'fail' => 'Neither X-Frame-Options nor Content-Security-Policy is present.'],
        'B7c' => ['title' => 'Content-Security-Policy present', 'fail' => 'No Content-Security-Policy is defined.'],
        'B7d' => ['title' => 'Referrer-Policy set', 'fail' => 'The Referrer-Policy header is missing.'],
        'B7e' => ['title' => 'Permissions-Policy set', 'fail' => 'The Permissions-Policy header is missing.'],
        'B9'  => ['title' => 'No server version leak', 'fail' => 'The server leaks its version: {observed}.'],
        // DNS
        'C1'  => ['title' => 'IPv6 (AAAA record)', 'fail' => 'No AAAA record: the site is not reachable over IPv6.'],
        'C2'  => ['title' => 'CAA record present', 'fail' => 'No CAA record: any certificate authority can issue a certificate.'],
        'C5'  => ['title' => 'Short redirect chain', 'fail' => 'The redirect chain is too long or loops ({observed}).'],
        'C7'  => ['title' => 'Site available', 'fail' => 'The site answers HTTP {observed}.', 'pass' => 'The site is available (HTTP {observed}).'],
        'C8'  => ['title' => 'Correct 404 page', 'fail' => 'A non-existent URL answers 200 instead of 404 (soft 404).'],
        'C9'  => ['title' => 'robots.txt present', 'fail' => 'robots.txt is missing.', 'pass' => 'robots.txt is present.'],
        'C9a' => ['title' => 'robots.txt does not block everything', 'fail' => 'robots.txt contains a blanket "Disallow: /": all crawling is blocked (may be intentional, e.g. a staging site).', 'pass' => 'robots.txt does not block the whole site.'],
        'C10' => ['title' => 'Sitemap referenced in robots.txt', 'fail' => 'No sitemap is referenced in robots.txt, or the declared sitemap is not reachable.', 'pass' => '{observed} sitemap(s) declared and reachable.'],
        // Email
        'D1'  => ['title' => 'SPF record present', 'fail' => 'No SPF record: the site\'s emails may be rejected.', 'pass' => 'An SPF record is configured.'],
        'D3'  => ['title' => 'DMARC with active policy', 'fail' => 'DMARC is {observed}. Publish a policy (p=quarantine or p=reject).', 'pass' => 'DMARC is active (p={observed}).'],
        'D4'  => ['title' => 'MX records resolvable', 'fail' => 'No MX record: the domain cannot receive email.'],
        // Domain
        'W1'  => ['title' => 'Domain expiry not imminent', 'fail' => 'The domain expires in {observed} days. Renew it soon.', 'pass' => 'The domain is valid for {observed} more days.'],
        // Performance
        'PS1'  => ['title' => 'Lighthouse performance (desktop)', 'fail' => 'Desktop performance score is {observed}/100 (threshold {threshold}).', 'pass' => 'Good desktop performance score ({observed}/100).'],
        'PS1a' => ['title' => 'Lighthouse performance (mobile)', 'fail' => 'Mobile performance score is {observed}/100 (threshold {threshold}).', 'pass' => 'Good mobile performance score ({observed}/100).'],
        'PS2'  => ['title' => 'Lighthouse accessibility (desktop)', 'fail' => 'Desktop accessibility score is {observed}/100 (threshold {threshold}).', 'pass' => 'Good desktop accessibility score ({observed}/100).'],
        'PS2a' => ['title' => 'Lighthouse accessibility (mobile)', 'fail' => 'Mobile accessibility score is {observed}/100 (threshold {threshold}).', 'pass' => 'Good mobile accessibility score ({observed}/100).'],
        'PS3'  => ['title' => 'Lighthouse SEO (desktop)', 'fail' => 'Desktop SEO score is {observed}/100 (threshold {threshold}).', 'pass' => 'Good desktop SEO score ({observed}/100).'],
        'PS3a' => ['title' => 'Lighthouse SEO (mobile)', 'fail' => 'Mobile SEO score is {observed}/100 (threshold {threshold}).', 'pass' => 'Good mobile SEO score ({observed}/100).'],
        'PS4'  => ['title' => 'LCP under threshold', 'fail' => 'Mobile LCP is {observed} ms (threshold {threshold} ms).'],
        // Versions & updates
        'F1'  => ['title' => 'WordPress not several major releases behind', 'fail' => 'WordPress {observed} is {major_versions_behind} major releases behind current — updates may have stopped entirely.', 'pass' => 'WordPress {observed} is not far behind the current major release.'],
        'F2'  => ['title' => 'WordPress version secure and up to date', 'fail' => 'WordPress {observed} is not on the latest security update for its branch (branch end of life: {eol_date}).', 'pass' => 'WordPress {observed} is up to date (branch supported until {eol_date}).'],
        'F3'  => ['title' => 'PHP version supported', 'fail' => 'PHP {observed} is no longer supported (end of life {eol_date}). Plan an upgrade.', 'pass' => 'PHP {observed} is supported (until {eol_date}).'],
        'F4'  => ['title' => 'Plugins up to date', 'fail' => '{observed} plugin(s) have an update available: {names}.', 'pass' => 'All plugins are up to date.'],
        'F5'  => ['title' => 'Themes up to date', 'fail' => '{observed} theme(s) have an update available.', 'pass' => 'All themes are up to date.'],
        'F7'  => ['title' => 'Plugin requirements met', 'fail' => '{observed} plugin(s) require a higher PHP/WP version than the environment: {names}.'],
        // PHP
        'G1'  => ['title' => 'memory_limit within the recommended range', 'fail' => 'memory_limit is {observed}, outside the recommended range (256M–512M).', 'pass' => 'memory_limit is {observed}, within the recommended range (256M–512M).'],
        'G4'  => ['title' => 'Sufficient max_input_vars', 'fail' => 'max_input_vars is {observed} (recommended at least {threshold}).'],
        'G5'  => ['title' => 'Recommended PHP extensions', 'fail' => 'Missing PHP extensions: {observed}.', 'pass' => 'All recommended PHP extensions are present.'],
        'G6'  => ['title' => 'OPcache enabled', 'fail' => 'The OPcache extension is not loaded: PHP performance suffers.', 'pass' => 'OPcache is enabled.'],
        // Database
        'H1'  => ['title' => 'Database version supported', 'fail' => '{observed} is no longer supported (end of life {eol_date}). Plan an upgrade.', 'pass' => 'The database version is supported (until {eol_date}).'],
        'H4'  => ['title' => 'Table fragmentation under control', 'fail' => 'Tables carry {observed} bytes of overhead (threshold {threshold}).'],
        'H5'  => ['title' => 'Expired transients not piling up', 'fail' => '{observed} expired transients remain in the database (threshold {threshold}).'],
        'H9'  => ['title' => 'Non-default table prefix', 'fail' => 'The table prefix is the default "wp_"; changing it hinders automated attacks.'],
        // Cache
        'I1'  => ['title' => 'Autoloaded options weight', 'fail' => 'Autoloaded options weigh {observed} bytes — outside the recommended range (ideally under 500 KB, needs fixing past 2 MB).', 'pass' => 'Autoloaded options weigh {observed} bytes — within budget (under 500 KB).'],
        'I4'  => ['title' => 'Persistent object cache', 'fail' => 'No persistent object cache (Redis/Memcached) is configured.', 'pass' => 'A persistent object cache is configured.'],
        // Cron
        'J2'  => ['title' => 'No overdue cron events', 'fail' => '{observed} cron events are overdue: WP-Cron probably is not running.', 'pass' => 'No overdue cron events.'],
        'J3'  => ['title' => 'Reasonable cron event count', 'fail' => '{observed} scheduled events (threshold {threshold}).'],
        // Security
        'K1'  => ['title' => 'WP_DEBUG under control', 'fail' => 'WP_DEBUG is enabled, and the debug log\'s privacy could not be confirmed.', 'pass' => 'WP_DEBUG is disabled, or enabled with a debug log that is not publicly exposed.'],
        'K2'  => ['title' => 'WP_DEBUG_DISPLAY off', 'fail' => 'WP_DEBUG_DISPLAY is on: PHP errors show to visitors.'],
        'K3'  => ['title' => 'Debug log location', 'fail' => 'WP_DEBUG_LOG uses the default location (wp-content/debug.log), an easy target to guess.', 'pass' => 'WP_DEBUG_LOG uses a custom path ({observed}), harder to guess.'],
        'K4'  => ['title' => 'File editing disabled', 'fail' => 'The admin file editor is active: set DISALLOW_FILE_EDIT to true.', 'pass' => 'The admin file editor is disabled.'],
        'K6'  => ['title' => 'SSL forced on admin', 'fail' => 'FORCE_SSL_ADMIN is not enabled.', 'pass' => 'SSL is forced on the admin area.'],
        // Hosting
        'L1'  => ['title' => 'Free disk space', 'fail' => '{observed}% of disk space is free — under 20% or under 2 GB.', 'pass' => '{observed}% of disk space is free.'],
        'L4'  => ['title' => 'Uploads directory writable', 'fail' => 'The uploads directory is not writable: uploads and updates will fail.'],
        'L5'  => ['title' => 'Core not writable in production', 'fail' => 'Core files are writable by the web server: harden the permissions.'],
        // Users
        'M1'  => ['title' => 'Administrator count under control', 'fail' => 'The site has {observed} administrators (threshold {threshold}).', 'pass' => 'The site has {observed} administrator(s).'],
        'M2'  => ['title' => 'No default "admin" account', 'fail' => 'An administrator account uses the default "admin" login.', 'pass' => 'No default "admin" account.'],
        // BlogVault
        'BV1' => ['title' => 'Site not flagged as hacked', 'fail' => 'BlogVault has flagged this site: {detections} unresolved detection(s).', 'pass' => 'BlogVault\'s malware scan reports nothing.'],
        'BV2' => ['title' => 'No known vulnerability', 'fail' => 'BlogVault lists {observed} known vulnerabilities across {components} component(s).', 'pass' => 'BlogVault lists no known vulnerability for core, plugins or themes.'],
        // Wordfence
        'WF1' => ['title' => 'No known vulnerability (Wordfence)', 'fail' => 'Wordfence Intelligence lists {observed} known vulnerabilities across {components} component(s).', 'pass' => 'Wordfence Intelligence lists no known vulnerability for core, plugins or themes.'],
        // Exposure
        'X1'  => ['title' => 'xmlrpc.php not exposed', 'fail' => 'xmlrpc.php answers — brute-force amplification and pingback abuse are possible.', 'pass' => 'xmlrpc.php is blocked or disabled.'],
        'X2'  => ['title' => 'No username disclosure via the REST API', 'fail' => 'The REST API lists user accounts at /wp-json/wp/v2/users, disclosing every login.', 'pass' => 'The REST API does not disclose user accounts.'],
        'X3'  => ['title' => 'No username disclosure via author archives', 'fail' => '?author=1 redirects to the author archive, disclosing the login in the URL.', 'pass' => 'The author-archive redirect does not disclose a login.'],
        'X4'  => ['title' => 'No exposed backup/config file', 'fail' => 'Publicly reachable: {observed}. These can disclose database credentials directly.', 'pass' => 'No common backup/config file is publicly reachable.'],
        'X5'  => ['title' => 'Uploads directory not browsable', 'fail' => 'wp-content/uploads/ returns a directory listing.', 'pass' => 'wp-content/uploads/ is not browsable.'],
        'X6'  => ['title' => 'HTTP TRACE method disabled', 'fail' => 'The server answers an HTTP TRACE request (cross-site tracing risk).', 'pass' => 'The server rejects HTTP TRACE.'],
    ],
];
