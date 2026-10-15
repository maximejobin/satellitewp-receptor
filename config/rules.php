<?php
/**
 * Rule catalogue — the executable form of .github/validations-techniques.txt
 * (repo satellitewp-plugin-maintenance). Ids follow that document's section
 * letters; W* (domain), PS* (PageSpeed) and X* (exposure) are additions.
 *
 * Language-neutral: checks return raw values (observed + named data, plus an
 * optional 'variant' picking a message template); sentences live in
 * config/lang/<locale>.php. Thresholds: rules.thresholds.<id> in config.
 */

declare(strict_types=1);

use SatelliteWP\Manager\Catalog\SoftwareCatalog;
use SatelliteWP\Manager\Reference\EndOfLife;
use SatelliteWP\Manager\Reference\WordPressVersions;
use SatelliteWP\Manager\Rules\Category;
use SatelliteWP\Manager\Rules\Check;
use SatelliteWP\Manager\Rules\CheckResult;
use SatelliteWP\Manager\Rules\Context;
use SatelliteWP\Manager\Rules\Rule;
use SatelliteWP\Manager\Rules\Severity;
use SatelliteWP\Manager\Rules\Status;
use SatelliteWP\Manager\Rules\VulnerabilityMerge;

// Only a 2xx homepage is the site itself: behind Basic Auth, a WAF challenge
// or an error page, anything read from the response is unknown, never a verdict.
$homepageReadable = static function (Context $c): bool {
    $code = $c->number('probe.http.status_code');

    return $c->probeRan('http') && $c->get('probe.http.auth.required') !== true
        && $code !== null && $code >= 200 && $code < 300;
};

// An empty header value enforces nothing: browsers ignore it.
$headerPresent = static function (Context $c, string $name): bool {
    $value = $c->get("probe.http.security_headers.{$name}");

    return is_string($value) && trim($value) !== '';
};

// Exposure checks run from the outside: behind an auth gate every path reads
// clean, so nothing they report is trusted then.
$exposureFlag = static fn (Context $c, string $field): ?bool => $homepageReadable($c)
    ? $c->bool("probe.http.exposure.{$field}")
    : null;

// Sensitive files found, or null when that can't be proven clean: auth gate,
// skipped check (soft-404), or a path whose request failed.
$sensitiveFiles = static function (Context $c) use ($homepageReadable): ?array {
    $found = $c->get('probe.http.exposure.sensitive_files');
    if (!$homepageReadable($c) || !is_array($found)) {
        return null;
    }

    return $found === [] && $c->list('probe.http.exposure.evidence.sensitive_files.unverified') !== [] ? null : array_values($found);
};

// Whether the default-path debug log is publicly downloadable; null = not proven either way.
$debugLogExposed = static function (Context $c) use ($homepageReadable): ?bool {
    $log   = 'wp-content/debug.log';
    $found = $c->get('probe.http.exposure.sensitive_files');
    if (!$homepageReadable($c)) {
        return null;
    }
    if (is_array($found) && in_array($log, $found, true)) {
        return true;
    }
    $evidence   = $c->list('probe.http.exposure.evidence.sensitive_files');
    $unverified = (array) ($evidence['unverified'] ?? []);
    if (in_array($log, $unverified, true)) {
        return null;
    }
    if (is_array($found)) {
        return false;
    }

    // The list is null when another path's request failed; this one still answered.
    return $unverified !== [] && in_array($log, (array) ($evidence['checked'] ?? []), true)
        ? in_array($log, (array) ($evidence['found'] ?? []), true)
        : null;
};

// A DNS field is null when its lookup failed (unknown), [] when it has no record.
$dnsKnown = static fn (Context $c, string $field): bool => $c->probeRan('dns')
    && $c->get("probe.dns.{$field}") !== null;

// "No update available" holds only if WordPress asked wordpress.org shortly
// before the extraction (it checks twice a day while WP-Cron runs).
$updatesCheckedRecently = static function (Context $c, string $field): bool {
    $checked   = $c->number("payload.{$field}");
    $generated = strtotime((string) $c->string('payload.generated_at') . ' UTC');

    return $checked !== null && $generated !== false && $generated - $checked <= 48 * 3600;
};

// SPF/DKIM/DMARC verdicts come from the receiving server's own check of the
// site's test email. Unknown when no message was found or the receiver hit a
// transient error; anything else that is not "pass" is a failure.
$mailAuth = static function (Context $c, string $method): CheckResult {
    $verdict = $c->probeRan('mail') && $c->bool('probe.mail.found') === true
        ? $c->string("probe.mail.{$method}")
        : null;
    if ($verdict === null) {
        return Check::unknown();
    }

    return match ($verdict) {
        'pass'  => Check::pass($verdict),
        'none'  => Check::fail($verdict, ['variant' => 'none']),
        'fail', 'softfail', 'neutral', 'permerror', 'policy' => Check::fail($verdict),
        default => Check::unknown(),
    };
};

/**
 * First $max names plus an "et N autres" count, for truncated lists.
 *
 * @param list<string> $names
 * @return array<string, scalar>
 */
$nameList = static function (array $names, int $max = 10): array {
    $names = array_values(array_unique($names));
    $more  = count($names) - $max;
    $data  = ['names' => implode(', ', array_slice($names, 0, $max))];

    return $more > 0 ? $data + ['more' => $more, 'variant' => 'more'] : $data;
};

// Lighthouse accessibility/SEO audits don't depend on the device: score on the lower of the two.
$lowestScore = static function (Context $c, string $metric): ?float {
    $scores = array_filter(
        [$c->number("probe.pagespeed.desktop.scores.{$metric}"), $c->number("probe.pagespeed.mobile.scores.{$metric}")],
        static fn (?float $v): bool => $v !== null
    );

    return $scores === [] ? null : min($scores);
};

return [

    // ===================================================================
    //  A. TLS / SSL                                                [EXT]
    // ===================================================================
    [
        'id' => 'A1', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            // days_to_expiry is floored: 0 is the last valid day, below 0 is expired.
            $days = $c->number('probe.tls.days_to_expiry');

            return $days === null ? Check::unknown() : ($days >= 0 ? Check::pass($days) : Check::fail($days));
        },
    ],
    [
        'id' => 'A2', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 30,
        'check' => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.tls.days_to_expiry');
            if ($days !== null && $days < 0) {
                return Check::na(); // A1 reports an expired certificate
            }

            return Check::graded($days, [[15, Severity::High], [(float) $rule->threshold, Severity::Medium]]);
        },
    ],
    [
        // Any verify failure reads as an invalid chain: an expired or self-signed
        // certificate is A1's or A5's finding, not a missing intermediate.
        'id' => 'A3', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $days = $c->number('probe.tls.days_to_expiry');
            if (($days !== null && $days < 0) || $c->bool('probe.tls.self_signed') === true) {
                return Check::na();
            }

            return Check::isTrue($c->bool('probe.tls.chain_valid'));
        },
    ],
    [
        'id' => 'A4', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.hostname_covered')),
    ],
    [
        'id' => 'A5', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Critical,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.tls.self_signed')),
    ],
    [
        'id' => 'A6', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $protocols = $c->get('probe.tls.protocols');
            if (!is_array($protocols)) {
                return Check::unknown();
            }
            $legacy = ['TLS 1.0' => $protocols['tls1_0'] ?? null, 'TLS 1.1' => $protocols['tls1_1'] ?? null];

            $accepted = array_keys(array_filter($legacy, static fn ($v): bool => $v === true));
            if ($accepted !== []) {
                return Check::fail(implode(', ', $accepted));
            }

            // null = the probe could not negotiate that version locally: not proof it's disabled.
            return in_array(null, $legacy, true) ? Check::unknown() : Check::pass('none');
        },
    ],
    [
        'id' => 'A7', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'strict-transport-security') ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        // Reads the http:// chain only; a chain that failed or looped is unknown
        // (forces_https null). Ending on an http:// error page is not plain-text traffic.
        'id' => 'A8', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            if (!$c->probeRan('http') || $c->string('probe.http.redirects.error') !== null) {
                return Check::unknown();
            }
            $forces = $c->bool('probe.http.redirects.forces_https');
            if ($forces !== false) {
                return Check::isTrue($forces);
            }
            $chain = $c->list('probe.http.redirects.chain');
            $last  = end($chain);
            $code  = is_array($last) && is_numeric($last['status'] ?? null) ? (int) $last['status'] : null;

            return $code !== null && $code >= 400
                ? Check::fail($code, ['variant' => 'http_error'])
                : Check::fail(false);
        },
    ],

    // ===================================================================
    //  B. HTTP HEADERS & NETWORK                                   [EXT]
    // ===================================================================
    [
        // Offers gzip alone, so it measures capability, not the server's preference.
        'id' => 'B1', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $homepageReadable($c) ? Check::isTrue($c->bool('probe.http.compression.gzip')) : Check::unknown(),
    ],
    [
        'id' => 'B2', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $homepageReadable($c) ? Check::isTrue($c->bool('probe.http.compression.brotli')) : Check::unknown(),
    ],
    [
        'id' => 'B3', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http2')),
    ],
    [
        // Low stakes: green when supported, informational (blue) when not.
        'id' => 'B4', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $http11 = $c->bool('probe.http.protocols.http1_1');
            if ($http11 === null) {
                return Check::unknown();
            }

            return $http11 ? Check::pass(true) : Check::fail(false, [], Severity::Info);
        },
    ],
    [
        // Read from the Alt-Svc header the site advertises, not a live QUIC handshake.
        'id' => 'B5', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http3_advertised')),
    ],
    [
        'id' => 'B6', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 86400,
        'check' => static function (Context $c, Rule $rule) use ($homepageReadable) {
            $asset = $c->get('probe.http.asset');
            if (!$homepageReadable($c) || !is_array($asset)) {
                return Check::unknown();
            }
            if (($asset['checked'] ?? false) !== true) {
                // Not applicable only when the page links no first-party asset; a failed fetch is unknown.
                return isset($asset['url']) || isset($asset['error']) ? Check::unknown() : Check::na();
            }
            // Only a served asset tells its cache policy; an undecoded HTML entity in the URL means another file was asked for.
            $status = $asset['status'] ?? null;
            if (($status !== null && !(($status >= 200 && $status < 300) || $status === 304))
                || preg_match('/&(?:#\d+|#x[0-9a-f]+|amp);/i', (string) ($asset['url'] ?? '')) === 1) {
                return Check::unknown();
            }
            $maxAge = (int) ($asset['max_age'] ?? 0);
            $data   = [
                'cache_days'     => round($maxAge / 86400, 1),
                'cache_hours'    => round($maxAge / 3600, 1),
                'threshold_days' => round((float) $rule->threshold / 86400, 1),
                'variant'        => $maxAge <= 0 ? 'none' : ($maxAge < 86400 ? 'hours' : 'days'),
            ];

            return $maxAge >= (float) $rule->threshold ? Check::pass($maxAge, $data) : Check::fail($maxAge, $data);
        },
    ],
    [
        'id' => 'B7', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'x-content-type-options') ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        // A CSP prevents framing only through its frame-ancestors directive.
        'id' => 'B8', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($homepageReadable, $headerPresent) {
            if (!$homepageReadable($c)) {
                return Check::unknown();
            }
            $csp = (string) $c->string('probe.http.security_headers.content-security-policy');

            // Presence only: which origins may frame the site is the site's call, not ours.
            return $headerPresent($c, 'x-frame-options') || preg_match('/(?:^|;)\s*frame-ancestors\s/i', $csp) === 1
                ? Check::pass()
                : Check::fail();
        },
    ],
    [
        'id' => 'B9', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'content-security-policy') ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        'id' => 'B10', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'referrer-policy') ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        'id' => 'B11', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'permissions-policy') ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        'id' => 'B12', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown();
            }
            $leaks = [];
            foreach (['server', 'x-powered-by'] as $header) {
                $value = $c->string("probe.http.fingerprint.{$header}");
                if ($value !== null && preg_match('/\d+\.\d+/', $value)) {
                    $leaks[] = $value;
                }
            }

            return $leaks === [] ? Check::pass('none') : Check::fail(implode(', ', $leaks));
        },
    ],

    // ===================================================================
    //  C. DNS & AVAILABILITY                                       [EXT]
    // ===================================================================
    [
        // Green when present; missing is an optional improvement, so it stays informational (blue).
        'id' => 'C1', 'category' => Category::DNS, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($dnsKnown) {
            if (!$dnsKnown($c, 'aaaa')) {
                return Check::unknown();
            }
            $aaaa = $c->list('probe.dns.aaaa');

            return $aaaa !== [] ? Check::pass(count($aaaa)) : Check::fail(0, [], Severity::Info);
        },
    ],
    [
        'id' => 'C2', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 2,
        'check' => static function (Context $c, Rule $rule) {
            if ($c->bool('probe.http.redirects.loop_detected') === true) {
                return Check::fail('loop', ['variant' => 'loop'], Severity::High);
            }
            $hops = $c->number('probe.http.redirects.hops');

            return $hops === null ? Check::unknown() : Check::atMost($hops, (float) $rule->threshold);
        },
    ],
    [
        'id' => 'C3', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $code = $c->number('probe.http.status_code');
            // A 401 is the auth gate; a 3xx means the redirect chain could not be followed to its end.
            if (!$c->probeRan('http') || $code === null || $code === 401.0 || $c->get('probe.http.auth.required') === true
                || ($code >= 300 && $code < 400)) {
                return Check::unknown();
            }

            return $code >= 200 && $code < 300 ? Check::pass((int) $code) : Check::fail((int) $code);
        },
    ],
    [
        'id' => 'C4', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($homepageReadable) {
            $soft = $c->get('probe.http.soft_404');
            if (!$homepageReadable($c) || !is_array($soft) || ($soft['checked'] ?? false) !== true) {
                return Check::unknown();
            }

            return Check::isFalse((bool) ($soft['is_soft_404'] ?? false));
        },
    ],
    [
        'id' => 'C5', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($homepageReadable) {
            $robots = $c->get('probe.http.robots');
            if (!$homepageReadable($c) || !is_array($robots)) {
                return Check::unknown();
            }

            if (!is_bool($robots['present'] ?? null)) {
                return Check::unknown();
            }

            return $robots['present'] ? Check::pass('present') : Check::fail('absent');
        },
    ],
    [
        'id' => 'C6', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) use ($homepageReadable) {
            $robots = $c->get('probe.http.robots');
            if (!$homepageReadable($c)) {
                return Check::unknown();
            }
            if (!is_array($robots) || !is_bool($robots['present'] ?? null)) {
                return Check::unknown();
            }
            if (!$robots['present']) {
                return Check::na();
            }

            return ($robots['disallow_all'] ?? false) === true ? Check::fail('blocked') : Check::pass('not blocked');
        },
    ],
    [
        'id' => 'C7', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($homepageReadable) {
            $robots = $c->get('probe.http.robots');
            if (!$homepageReadable($c) || !is_array($robots) || ($robots['present'] ?? false) !== true) {
                return Check::unknown();
            }
            $sitemaps = $robots['sitemaps'] ?? [];
            // On a soft-404 site every conventional URL answers 200: finding one there proves nothing.
            if (($robots['sitemap_source'] ?? null) === 'convention' && $c->get('probe.http.soft_404.is_soft_404') === true) {
                return Check::unknown();
            }
            // A sitemap request that failed proves neither presence nor absence.
            if ($sitemaps === [] ? ($robots['sitemap_unverified'] ?? false) === true : ($robots['sitemap_reachable'] ?? null) === null) {
                return Check::unknown();
            }
            if ($sitemaps === [] || ($robots['sitemap_reachable'] ?? null) === false) {
                return Check::fail(count($sitemaps), [], Severity::Info);
            }

            return Check::pass(count($sitemaps));
        },
    ],

    // ====================================================================
    //  D. EMAIL DELIVERABILITY                                     [EXT]
    // ====================================================================
    [
        'id' => 'D1', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static fn (Context $c) => $mailAuth($c, 'spf'),
    ],
    [
        'id' => 'D2', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::High,
        // A valid signature proves the sender only when it is made for the From
        // domain (DMARC relaxed alignment); a provider's own signature does not.
        'check' => static function (Context $c) use ($mailAuth) {
            $result = $mailAuth($c, 'dkim');
            if ($result->status !== Status::Pass) {
                return $result;
            }

            $signer = implode(', ', array_filter(array_map('strval', $c->list('probe.mail.dkim_domains')), static fn (string $d): bool => $d !== ''));

            return match ($c->bool('probe.mail.dkim_aligned')) {
                true    => $result,
                // Without the signing domain the client text could not name it.
                false   => $signer !== '' ? Check::fail('unaligned', ['variant' => 'unaligned', 'signer' => $signer]) : Check::unknown(),
                default => Check::unknown(),
            };
        },
    ],
    [
        'id' => 'D3', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static fn (Context $c) => $mailAuth($c, 'dmarc'),
    ],
    [
        'id' => 'D4', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($dnsKnown) {
            if (!$dnsKnown($c, 'mx')) {
                return Check::unknown();
            }
            // A null MX ("MX 0 .", RFC 7505) states that the domain receives no email.
            $mx = array_filter($c->list('probe.dns.mx'), static fn ($r): bool => is_array($r) && rtrim((string) ($r['host'] ?? ''), '.') !== '');

            return $mx !== [] ? Check::pass(count($mx)) : Check::fail(0);
        },
    ],

    // ===================================================================
    //  W. DOMAIN (RDAP/WHOIS)                                      [EXT]
    // ===================================================================
    [
        'id' => 'W1', 'category' => Category::DOMAIN, 'source' => 'EXT', 'severity' => Severity::High, 'threshold' => 30,
        'check' => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.rdap.days_to_expiry');
            if ($days === null) {
                return Check::unknown();
            }
            if ($days < 0) {
                return Check::fail($days, ['variant' => 'expired'], Severity::Critical);
            }

            return Check::graded($days, [[15, Severity::Critical], [(float) $rule->threshold, Severity::High]]);
        },
    ],

    // ===================================================================
    //  PS. PERFORMANCE (Lighthouse / PageSpeed)                    [EXT]
    // ===================================================================
    [
        'id' => 'PS1', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.desktop.scores.performance'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS2', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.performance'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS3', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($lowestScore($c, 'accessibility'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS4', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($lowestScore($c, 'seo'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS5', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 2500,
        'check' => static function (Context $c, Rule $rule) {
            $lcp = $c->number('probe.pagespeed.mobile.lab.lcp.value');
            if ($lcp === null) {
                return Check::unknown();
            }
            $data = ['lcp_s' => round($lcp / 1000, 1), 'threshold_s' => round((float) $rule->threshold / 1000, 1)];

            return $lcp <= (float) $rule->threshold ? Check::pass($lcp, $data) : Check::fail($lcp, $data);
        },
    ],

    // ===================================================================
    //  F. VERSIONS, UPDATES & END OF LIFE                         [DATA]
    // ===================================================================
    [
        'id' => 'F1', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $wpVersions = $c->reference('wordpress_versions');
            $current    = $c->string('payload.wp_version');
            if (!$wpVersions instanceof WordPressVersions || $current === null) {
                return Check::unknown();
            }
            $behind = $wpVersions->majorVersionsBehind($current);
            if ($behind === null) {
                return Check::unknown();
            }

            return $behind >= 4
                ? Check::fail($current, ['major_versions_behind' => $behind])
                : Check::pass($current, ['major_versions_behind' => $behind]);
        },
    ],
    [
        // WordPress backports security fixes to older branches, so a branch's
        // endoflife.date "eol" is not the signal: a missing same-branch patch
        // (minor_update_version) or wordpress.org's own "insecure" verdict is.
        'id' => 'F2', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $version = $c->string('payload.wp_version');
            if ($version === null) {
                return Check::unknown();
            }
            $minor = (string) ($c->get('payload.core_update.minor_update_version') ?? '');
            if ($minor !== '') {
                return Check::fail($version, ['available' => $minor]);
            }

            $wpVersions = $c->reference('wordpress_versions');
            $verdict    = $wpVersions instanceof WordPressVersions ? ($wpVersions->all()[$version] ?? null) : null;
            if ($verdict === 'insecure') {
                return Check::fail($version, ['variant' => 'insecure']);
            }

            return $verdict === null && !is_array($c->get('payload.core_update'))
                ? Check::unknown()
                : Check::pass($version);
        },
    ],
    [
        'id' => 'F3', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::High, 'web_runtime' => true,
        'check' => static function (Context $c) {
            $eol     = $c->reference('eol');
            $version = $c->string('payload.php.version');
            if (!$eol instanceof EndOfLife || $version === null) {
                return Check::unknown();
            }
            $status = $eol->eolStatus('php', $version, 'eol');
            if ($status === null) {
                return Check::unknown();
            }
            [$isEol, $date] = $status;
            $data = $date === null ? ['variant' => 'no_date'] : ['eol_date' => $date];

            return $isEol ? Check::fail($version, $data) : Check::pass($version, $data);
        },
    ],
    [
        'id' => 'F4', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($nameList, $updatesCheckedRecently) {
            $plugins = $c->list('payload.plugins');
            if ($plugins === []) {
                return Check::unknown();
            }
            $outdated = array_values(array_filter($plugins, static fn ($p): bool => is_array($p) && !empty($p['new_version'])));
            if ($outdated === []) {
                return $updatesCheckedRecently($c, 'plugin_updates_checked_at') ? Check::pass(0) : Check::unknown();
            }

            return Check::fail(count($outdated), $nameList(array_map(static fn (array $p): string => (string) ($p['name'] ?? '?'), $outdated)));
        },
    ],
    [
        'id' => 'F5', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($nameList, $updatesCheckedRecently) {
            $themes = $c->list('payload.themes');
            if ($themes === []) {
                return Check::unknown();
            }
            $outdated = array_values(array_filter($themes, static fn ($t): bool => is_array($t) && !empty($t['new_version'])));
            if ($outdated === []) {
                return $updatesCheckedRecently($c, 'theme_updates_checked_at') ? Check::pass(0) : Check::unknown();
            }

            return Check::fail(count($outdated), $nameList(array_map(static fn (array $t): string => (string) ($t['name'] ?? '?'), $outdated)));
        },
    ],
    [
        'id' => 'F6', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::High, 'web_runtime' => true,
        'check' => static function (Context $c) use ($nameList) {
            $plugins = $c->list('payload.plugins');
            $php     = $c->string('payload.php.version');
            $wp      = $c->string('payload.wp_version');
            if ($plugins === [] || $php === null || $wp === null) {
                return Check::unknown();
            }
            $incompatible = [];
            foreach ($plugins as $plugin) {
                if (!is_array($plugin)) {
                    continue;
                }
                $needsPhp = $plugin['requires_php'] ?? null;
                $needsWp  = $plugin['requires_wp'] ?? null;
                if (($needsPhp && version_compare($php, (string) $needsPhp, '<'))
                    || ($needsWp && version_compare($wp, (string) $needsWp, '<'))) {
                    $incompatible[] = (string) ($plugin['name'] ?? '?');
                }
            }

            return $incompatible === [] ? Check::pass(0) : Check::fail(count($incompatible), $nameList($incompatible));
        },
    ],
    [
        // Only speaks to software hosted on wp.org: premium/custom items
        // (on_wporg false) or ones this run couldn't fetch are skipped.
        'id' => 'F7', 'category' => Category::UPDATES, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 365,
        'check' => static function (Context $c, Rule $rule) use ($nameList) {
            if (!$c->probeRan('wporg')) {
                return Check::unknown();
            }
            $wporgPlugins = (array) $c->get('probe.wporg.plugins', []);
            $wporgThemes  = (array) $c->get('probe.wporg.themes', []);
            $cutoff       = time() - ((int) $rule->threshold) * 86400;
            $checked      = 0;
            $abandoned    = [];

            $scan = static function (array $items, string $type, array $wporgData) use (&$checked, &$abandoned, $cutoff): void {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $slug  = SoftwareCatalog::normalizeSlug($type, (string) ($item['slug'] ?? ''));
                    $entry = $wporgData[$slug] ?? null;
                    if (!is_array($entry) || empty($entry['on_wporg']) || empty($entry['last_updated'])) {
                        continue;
                    }
                    $checked++;
                    $updatedAt = strtotime((string) $entry['last_updated']);
                    if ($updatedAt !== false && $updatedAt < $cutoff) {
                        $abandoned[] = (string) ($item['name'] ?? $slug);
                    }
                }
            };
            $scan($c->list('payload.plugins'), 'plugin', $wporgPlugins);
            $scan($c->list('payload.themes'), 'theme', $wporgThemes);

            if ($checked === 0) {
                return Check::unknown();
            }

            return $abandoned === [] ? Check::pass(0) : Check::fail(count($abandoned), $nameList($abandoned));
        },
    ],
    [
        // Counted once per vulnerability across both detectors, matched by CVE.
        'id' => 'F8', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) use ($nameList) {
            $bvKnown = $c->number('probe.blogvault.vulnerabilities_total') !== null;
            $wfKnown = $c->number('probe.wordfence.vulnerabilities_total') !== null;
            if (!$bvKnown && !$wfKnown) {
                return Check::unknown();
            }
            $summary = VulnerabilityMerge::siteSummary(
                $bvKnown ? $c->probeData('blogvault') : null,
                $wfKnown ? $c->probeData('wordfence') : null,
                (array) ($c->reference('ignored_vulnerabilities') ?? []),
            );
            if ($summary['total'] === 0) {
                return Check::pass(0);
            }

            return Check::fail($summary['total'], ['components' => count($summary['components'])] + $nameList($summary['components']));
        },
    ],
    [
        // Minor releases carry WordPress's security fixes.
        'id' => 'F9', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $minor = $c->get('payload.core_update.auto_update_minor');
            if (!is_bool($minor)) {
                return Check::unknown();
            }
            if ($c->constant('DISALLOW_FILE_MODS') === true) {
                return Check::fail('file_mods', ['variant' => 'file_mods']);
            }
            if ($c->get('payload.core_update.auto_updates_disabled') === true) {
                return Check::fail('all_disabled', ['variant' => 'all_disabled']);
            }

            return $minor ? Check::pass('minor') : Check::fail('minor_disabled');
        },
    ],
    [
        // A major release is applied after checking the site, never unattended.
        'id' => 'F10', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $major = $c->get('payload.core_update.auto_update_major');
            if (!is_bool($major)) {
                return Check::unknown();
            }

            return $major ? Check::fail('major_enabled') : Check::pass('major_disabled');
        },
    ],
    [
        // A plugin update is applied after checking the site, never unattended.
        'id' => 'F11', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($nameList) {
            $plugins = $c->get('payload.plugins');
            if (!is_array($plugins)) {
                return Check::unknown();
            }
            $auto = [];
            foreach ($plugins as $plugin) {
                // Without the resolved policy (auto-update filters included) nothing is proven.
                if (!is_array($plugin) || !is_bool($plugin['auto_update'] ?? null)) {
                    return Check::unknown();
                }
                // An inactive plugin updating itself cannot break the site.
                if ($plugin['auto_update'] && (($plugin['active'] ?? false) === true || ($plugin['network_activated'] ?? false) === true)) {
                    $auto[] = (string) ($plugin['name'] ?? '?');
                }
            }

            return $auto === [] ? Check::pass(0) : Check::fail(count($auto), $nameList($auto));
        },
    ],
    [
        // Inactive code stays reachable on disk. Exempt: the active theme's
        // parent, and one default theme WordPress falls back to.
        'id' => 'F12', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($nameList) {
            $plugins = $c->list('payload.plugins');
            $themes  = $c->list('payload.themes');
            if ($plugins === [] && $themes === []) {
                return Check::unknown();
            }
            // Per-site activation can't tell whether another network site uses it.
            if ($c->bool('payload.is_multisite') === true) {
                return Check::na();
            }

            $unused = [];
            foreach ($plugins as $plugin) {
                if (is_array($plugin) && empty($plugin['active']) && empty($plugin['network_activated'])) {
                    $unused[] = (string) ($plugin['name'] ?? '?');
                }
            }

            $required = [];
            foreach ($themes as $slug => $theme) {
                if (is_array($theme) && !empty($theme['active'])) {
                    $required[] = (string) ($theme['slug'] ?? $slug);
                    $required[] = (string) ($theme['template'] ?? '');
                    $required[] = (string) ($theme['parent_slug'] ?? '');
                }
            }
            $keepsDefault = (bool) array_filter($required, static fn (string $s): bool => str_starts_with($s, 'twenty'));

            foreach ($themes as $slug => $theme) {
                if (!is_array($theme) || !empty($theme['active'])) {
                    continue;
                }
                $themeSlug = (string) ($theme['slug'] ?? $slug);
                if (in_array($themeSlug, $required, true)) {
                    continue;
                }
                if (!$keepsDefault && str_starts_with($themeSlug, 'twenty')) {
                    $keepsDefault = true; // the one fallback theme worth keeping
                    continue;
                }
                $unused[] = (string) ($theme['name'] ?? $themeSlug);
            }

            return $unused === [] ? Check::pass(0) : Check::fail(count($unused), $nameList($unused));
        },
    ],

    // ===================================================================
    //  G. PHP & SERVER                                            [DATA]
    // ===================================================================
    [
        'id' => 'G1', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Medium, 'web_runtime' => true,
        'check' => static function (Context $c) {
            $bytes = $c->bytes('payload.php.memory_limit');
            if ($bytes === null) {
                return Check::unknown();
            }
            $shown = $c->string('payload.php.memory_limit');
            if ($bytes === INF) {
                return Check::pass($shown, ['variant' => 'unlimited']);
            }
            $data = ['memory_mb' => (int) round($bytes / 1048576)];

            return match (true) {
                $bytes < 64 * 1048576  => Check::fail($shown, $data, Severity::High),
                $bytes < 256 * 1048576 => Check::fail($shown, $data),
                default                => Check::pass($shown, $data),
            };
        },
    ],
    [
        // post_max_size bounds the whole request, so it must be at least
        // upload_max_filesize (larger is PHP's own advice); the smaller of the
        // two must clear a working size. post_max_size 0 means unlimited.
        'id' => 'G2', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 50, 'web_runtime' => true,
        'check' => static function (Context $c, Rule $rule) {
            $postRaw   = $c->string('payload.php.post_max_size');
            $uploadRaw = $c->string('payload.php.upload_max_filesize');
            $post      = $c->bytes('payload.php.post_max_size');
            $upload    = $c->bytes('payload.php.upload_max_filesize');
            if ($postRaw === null || $uploadRaw === null || $post === null || $upload === null) {
                return Check::unknown();
            }
            if ($post == 0) {
                $post = INF;
            }
            $observed  = "{$postRaw} / {$uploadRaw}";
            $effective = min($post, $upload);
            if ($effective === INF) {
                return Check::pass($observed, ['variant' => 'unlimited']);
            }
            $data = ['effective_mb' => (int) round($effective / 1048576)];

            if ($post < $upload) {
                return Check::fail($observed, $data + ['variant' => 'mismatch']);
            }

            return $effective / 1048576 < (float) $rule->threshold
                ? Check::fail($observed, $data)
                : Check::pass($observed, $data);
        },
    ],
    [
        'id' => 'G3', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 1000, 'web_runtime' => true,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('payload.php.max_input_vars'), (float) $rule->threshold),
    ],
    [
        'id' => 'G4', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Medium, 'web_runtime' => true,
        'check' => static function (Context $c) {
            $extensions = $c->list('payload.php.extensions');
            if ($extensions === []) {
                return Check::unknown();
            }
            $present  = array_map('strtolower', array_map('strval', $extensions));
            $missing  = array_values(array_diff(['curl', 'mbstring', 'openssl', 'zip', 'dom', 'xml', 'json'], $present));
            if (!array_intersect(['gd', 'imagick'], $present)) {
                $missing[] = 'gd/imagick';
            }

            return $missing === [] ? Check::pass('all') : Check::fail(implode(', ', $missing));
        },
    ],
    [
        'id' => 'G5', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Medium, 'web_runtime' => true,
        'check' => static function (Context $c) {
            $extensions = $c->list('payload.php.extensions');
            if ($extensions === []) {
                return Check::unknown();
            }
            $present = array_map('strtolower', array_map('strval', $extensions));
            if (!in_array('zend opcache', $present, true) && !in_array('opcache', $present, true)) {
                return Check::fail(false);
            }

            // Loaded is not enabled: opcache.enable=0 leaves the extension idle.
            return Check::isTrue($c->bool('payload.php.opcache_enabled'));
        },
    ],

    // ===================================================================
    //  H. DATABASE                                                [DATA]
    // ===================================================================
    [
        'id' => 'H1', 'category' => Category::DATABASE, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $eol     = $c->reference('eol');
            $type    = strtolower((string) $c->string('payload.database_type'));
            $version = $c->string('payload.database_version');
            if (!$eol instanceof EndOfLife || $version === null || $type === '') {
                return Check::unknown();
            }
            [$product, $label] = match (true) {
                str_contains($type, 'maria') => ['mariadb', 'MariaDB'],
                str_contains($type, 'mysql') => ['mysql', 'MySQL'],
                default                      => [null, null],
            };
            if ($product === null) {
                return Check::unknown();
            }
            $status = $eol->eolStatus($product, $version);
            if ($status === null) {
                return Check::unknown();
            }
            [$isEol, $date] = $status;
            $data = $date === null ? ['variant' => 'no_date'] : ['eol_date' => $date];
            $branch = $label . ' ' . EndOfLife::branch($version);

            return $isEol ? Check::fail($branch, $data) : Check::pass($branch, $data);
        },
    ],
    [
        'id' => 'H2', 'category' => Category::DATABASE, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 250,
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.database.transients.expired'), (float) $rule->threshold),
    ],

    // ===================================================================
    //  I. AUTOLOAD / OBJECT CACHE                                 [DATA]
    // ===================================================================
    [
        'id' => 'I1', 'category' => Category::CACHE, 'source' => 'DATA', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $bytes = $c->number('payload.autoload.total_bytes');
            if ($bytes === null) {
                return Check::unknown();
            }
            $data = ['size_kb' => (int) round($bytes / 1024)];

            return match (true) {
                $bytes < 512000  => Check::pass($bytes, $data),
                $bytes < 2097152 => Check::fail($bytes, $data, Severity::Medium),
                default          => Check::fail($bytes, $data),
            };
        },
    ],
    [
        'id' => 'I2', 'category' => Category::CACHE, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.object_cache.external')),
    ],

    // ===================================================================
    //  J. CRON                                                    [DATA]
    // ===================================================================
    [
        // Graded on how late the oldest overdue task is, not on the count: a task
        // a few minutes late is only waiting for the next visit (WP-Cron runs on traffic).
        'id' => 'J1', 'category' => Category::CRON, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 30,
        'check' => static function (Context $c, Rule $rule) {
            $overdue = $c->number('payload.cron.overdue_events');
            if ($overdue === null) {
                return Check::unknown();
            }
            if ($overdue <= 0) {
                return Check::pass(0);
            }
            $minutes = $c->number('payload.cron.overdue_minutes');
            if ($minutes === null) {
                return Check::unknown();
            }
            $minutes = (int) $minutes;
            if ($minutes <= (float) $rule->threshold) {
                return Check::pass($minutes);
            }
            $data = ['events' => (int) $overdue];

            return match (true) {
                // With DISABLE_WP_CRON the site already relies on a server cron job: that one stopped.
                $minutes >= 1440 => Check::fail($minutes, $data + [
                    'variant' => $c->get('payload.cron.disabled') === true ? 'server_cron_stopped' : 'stopped',
                    'days'    => intdiv($minutes, 1440),
                ], Severity::High),
                $minutes >= 120  => Check::fail($minutes, $data + ['variant' => 'hours', 'hours' => intdiv($minutes, 60)]),
                default          => Check::fail($minutes, $data + ['minutes' => $minutes]),
            };
        },
    ],

    // ===================================================================
    //  K. CONFIGURATION & HARDENING                               [DATA]
    // ===================================================================
    [
        'id' => 'K1', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) use ($debugLogExposed) {
            $debug = $c->constant('WP_DEBUG');
            if ($debug === null) {
                return Check::unknown();
            }
            if ($debug === false) {
                return Check::pass(false);
            }
            // Debug on is acceptable only when it logs to a file confirmed private,
            // with nothing shown to visitors (K2's finding otherwise).
            $log = $c->get('payload.constants.WP_DEBUG_LOG');
            if ($c->constant('WP_DEBUG_DISPLAY') !== false || ($log !== true && !(is_string($log) && $log !== '' && $log !== 'N/A'))) {
                return Check::fail(true);
            }
            // A custom log path cannot be probed from outside: its privacy is not proven.
            if ($log !== true) {
                return Check::unknown();
            }

            return match ($debugLogExposed($c)) {
                false   => Check::pass(true, ['variant' => 'private_log']),
                true    => Check::fail(true),
                default => Check::unknown(),
            };
        },
    ],
    [
        'id' => 'K2', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::High, 'web_runtime' => true,
        'check' => static function (Context $c) {
            // The PHP setting as the site runs it, after WordPress applied WP_DEBUG_DISPLAY.
            $shown = $c->bool('payload.php.display_errors');
            if ($shown !== null) {
                return Check::isFalse($shown);
            }
            // Without it, only debug mode with display on proves a failure; php.ini decides otherwise.
            return $c->constant('WP_DEBUG') === true && $c->constant('WP_DEBUG_DISPLAY') === true
                ? Check::fail(true)
                : Check::unknown();
        },
    ],
    [
        // Informational: when the default-path log is actually reachable, X4 carries the red.
        'id' => 'K3', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Info,
        'check' => static function (Context $c) use ($debugLogExposed) {
            $debug = $c->constant('WP_DEBUG');
            if ($debug === null) {
                return Check::unknown();
            }
            $debugLog = $c->get('payload.constants.WP_DEBUG_LOG');
            if ($debug === false || $debugLog === null || $debugLog === 'N/A' || $debugLog === false || $debugLog === '') {
                return Check::na();
            }
            if ($debugLog !== true) {
                return Check::pass('custom');
            }
            return match ($debugLogExposed($c)) {
                true    => Check::fail('default'),
                false   => Check::pass('default'),
                default => Check::unknown(),
            };
        },
    ],
    [
        // DISALLOW_FILE_MODS makes core disable the file editor as well.
        'id' => 'K4', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $c->constant('DISALLOW_FILE_MODS') === true
            ? Check::pass(true, ['variant' => 'file_mods'])
            : Check::isTrue($c->constant('DISALLOW_FILE_EDIT')),
    ],
    [
        // A site-wide HTTPS redirect already protects the admin login.
        'id' => 'K5', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $forced = $c->constant('FORCE_SSL_ADMIN');
            if ($forced === true) {
                return Check::pass(true);
            }
            // Without a readable http:// chain, whether the site forces HTTPS is not known.
            if ($c->bool('probe.http.redirects.forces_https') === null) {
                return Check::unknown();
            }
            $siteHttps = $c->bool('probe.http.redirects.forces_https') === true
                && str_starts_with((string) $c->string('payload.home_url'), 'https://');
            if ($siteHttps) {
                return Check::pass(false, ['variant' => 'site_https']);
            }

            return $forced === null ? Check::unknown() : Check::fail(false);
        },
    ],

    // ===================================================================
    //  L. FILESYSTEM                                              [DATA]
    // ===================================================================
    [
        'id' => 'L1', 'category' => Category::HOSTING, 'source' => 'DATA', 'severity' => Severity::Info,
        'check' => static function (Context $c) {
            $free  = $c->number('payload.filesystem.disk_free_bytes');
            $total = $c->number('payload.filesystem.disk_total_bytes');
            if ($free === null || $total === null || $total <= 0) {
                return Check::unknown();
            }
            $percent  = round($free / $total * 100, 1);
            $lowSpace = $percent < 20 || $free < 2147483648; // under 20% or 2 GiB
            $data     = ['free_gb' => round($free / 1073741824, 1)];

            return $lowSpace ? Check::fail($percent, $data, Severity::Medium) : Check::pass($percent, $data);
        },
    ],
    [
        'id' => 'L2', 'category' => Category::HOSTING, 'source' => 'DATA', 'severity' => Severity::Medium, 'web_runtime' => true,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.filesystem.uploads_writable')),
    ],

    // ===================================================================
    //  M. USERS & ACCESS                                          [DATA]
    // ===================================================================
    [
        'id' => 'M1', 'category' => Category::USERS, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 3,
        'check' => static function (Context $c, Rule $rule) {
            $count = $c->count('payload.administrators');

            return $count === null ? Check::unknown() : Check::atMost((float) $count, (float) $rule->threshold);
        },
    ],
    [
        'id' => 'M2', 'category' => Category::USERS, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $admins = $c->list('payload.administrators');
            if ($admins === []) {
                return Check::unknown();
            }
            foreach ($admins as $admin) {
                if (is_array($admin) && strtolower((string) ($admin['login'] ?? '')) === 'admin') {
                    return Check::fail('admin');
                }
            }

            return Check::pass('none');
        },
    ],

    [
        // Judged on what the default role grants, so a custom or renamed role counts too.
        'id' => 'M3', 'category' => Category::USERS, 'source' => 'DATA', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $registration = $c->get('payload.registration');
            if (!is_array($registration) || !is_bool($registration['open'] ?? null) || !is_array($registration['default_role_caps'] ?? null)) {
                return Check::unknown();
            }
            if (!$registration['open']) {
                return Check::pass('closed');
            }
            $role = (string) ($registration['default_role_name'] ?? '') ?: (string) ($registration['default_role'] ?? '');
            $caps = $registration['default_role_caps'];
            // A default role that names nothing cannot be judged, nor shown to a client.
            if ($role === '') {
                return Check::unknown();
            }
            // Content rights, and the ones that hand over the site: users, code, raw HTML.
            $privileged = ['manage_options', 'edit_others_posts', 'publish_posts', 'promote_users', 'edit_users', 'create_users',
                'install_plugins', 'activate_plugins', 'unfiltered_html', 'edit_theme_options'];
            if (array_intersect($privileged, $caps) !== []) {
                return Check::fail('privileged', ['role' => $role, 'variant' => 'privileged']);
            }
            if (in_array('edit_posts', $caps, true)) {
                return Check::fail('contributor', ['role' => $role, 'variant' => 'contributor'], Severity::Medium);
            }

            // Open sign-up for subscribers or customers is a normal shop or members' area.
            return Check::fail('open', ['role' => $role], Severity::Info);
        },
    ],

    // ===================================================================
    //  BV. BLOGVAULT — MALWARE SCANNER                             [EXT]
    // ===================================================================
    [
        // Unknown when the site isn't under BlogVault management.
        'id' => 'BV1', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Critical,
        'check' => static function (Context $c) {
            $status = $c->string('probe.blogvault.scanner.status');
            if ($status === null) {
                return Check::unknown();
            }
            $unresolved = (int) ($c->number('probe.blogvault.scanner.unresolved_count') ?? 0);

            if ($status !== 'hacked' && $unresolved === 0) {
                return Check::pass($status);
            }

            return Check::fail($status, $unresolved > 0 ? ['detections' => $unresolved] : ['variant' => 'hacked']);
        },
    ],

    // ===================================================================
    //  X. EXPOSURE — passive attack-surface checks                 [EXT]
    // ===================================================================
    // Every check is a request any anonymous visitor could make.
    [
        'id' => 'X1', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isFalse($exposureFlag($c, 'xmlrpc_enabled')),
    ],
    [
        'id' => 'X2', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isFalse($exposureFlag($c, 'rest_user_enumeration')),
    ],
    [
        'id' => 'X3', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isFalse($exposureFlag($c, 'author_enumeration')),
    ],
    [
        'id' => 'X4', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Critical,
        'check' => static function (Context $c) use ($sensitiveFiles) {
            $found = $sensitiveFiles($c);
            if ($found === null) {
                return Check::unknown();
            }

            if ($found === []) {
                return Check::pass('none');
            }

            // The debug log alone reveals errors and paths, not database credentials.
            return Check::fail(implode(', ', array_map('strval', $found)), $found === ['wp-content/debug.log'] ? ['variant' => 'debug_log'] : []);
        },
    ],
    [
        'id' => 'X5', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isFalse($exposureFlag($c, 'directory_listing')),
    ],
];
