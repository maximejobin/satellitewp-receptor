<?php
/**
 * Rule catalogue — the executable form of .github/validations-techniques.txt
 * (repo satellitewp-plugin-maintenance).
 *
 * LANGUAGE-NEUTRAL by design: rules carry no prose. Each rule has an id, a
 * short English category (see Rules\Category), a source, a default severity, an
 * optional configurable threshold, and a check closure that returns only raw
 * values (observed + named data). Titles and sentences (EN/FR) live in
 * config/lang/<locale>.php keyed by the rule id, and are rendered at display
 * time by Rules\Translator.
 *
 * Rule ids follow the source document's section letters (A=SSL, B=HTTP, …).
 * Two prefixes are additions kept distinct so they never collide: W* (domain,
 * WHOIS/RDAP) and PS* (Lighthouse/PageSpeed).
 *
 * Thresholds are overridable per id via config: rules.thresholds.<id>.
 */

declare(strict_types=1);

use SatelliteWP\Xtractor\Reference\EndOfLife;
use SatelliteWP\Xtractor\Reference\WordPressVersions;
use SatelliteWP\Xtractor\Rules\Category;
use SatelliteWP\Xtractor\Rules\Check;
use SatelliteWP\Xtractor\Rules\Context;
use SatelliteWP\Xtractor\Rules\Rule;
use SatelliteWP\Xtractor\Rules\Severity;

return [

    // ===================================================================
    //  A. TLS / SSL                                                [EXT]
    // ===================================================================
    [
        'id' => 'A1', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $days = $c->number('probe.tls.days_to_expiry');

            return $days === null ? Check::unknown() : ($days > 0 ? Check::pass($days) : Check::fail($days));
        },
    ],
    [
        'id' => 'A2', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 30,
        'check' => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.tls.days_to_expiry');
            if ($days !== null && $days <= 0) {
                return Check::na(); // covered by A1
            }

            return Check::graded($days, [[15, Severity::High], [(float) $rule->threshold, Severity::Medium]]);
        },
    ],
    [
        'id' => 'A3', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.chain_valid')),
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
            $legacy = array_keys(array_filter([
                'TLS 1.0' => $protocols['tls1_0'] ?? false,
                'TLS 1.1' => $protocols['tls1_1'] ?? false,
            ]));

            return $legacy === [] ? Check::pass('none') : Check::fail(implode(' & ', $legacy));
        },
    ],
    [
        'id' => 'A8', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown();
            }

            return $c->get('probe.http.security_headers.strict-transport-security') !== null
                ? Check::pass() : Check::fail();
        },
    ],
    [
        'id' => 'A10', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.redirects.forces_https')),
    ],

    // ===================================================================
    //  B. HTTP HEADERS & NETWORK                                   [EXT]
    // ===================================================================
    [
        // Offers ONLY gzip and checks whether the server actually returns it
        // — not whether gzip happened to be the encoding a combined
        // "gzip, br" request came back with (that reflects the server's
        // preference between the two, not its capability; see
        // HttpProbe::compressionSupportCheck()).
        'id' => 'B1', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.compression.gzip')),
    ],
    [
        // Same idea as B1, offering ONLY brotli.
        'id' => 'B2', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.compression.brotli')),
    ],
    [
        // HTTP/2 only — split from the old ">= 2" threshold (config/rules.php
        // history) which counted HTTP/3 as a pass here too. See B4/B5 for the
        // other two versions.
        'id' => 'B3', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http2')),
    ],
    [
        'id' => 'B4', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http1_1')),
    ],
    [
        // Advertised via the Alt-Svc header only, not a live QUIC handshake —
        // see HttpProbe::altSvcAdvertisesHttp3() for why.
        'id' => 'B5', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http3_advertised')),
    ],
    [
        'id' => 'B6', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 86400,
        'check' => static function (Context $c, Rule $rule) {
            $asset = $c->get('probe.http.asset');
            if (!is_array($asset) || ($asset['checked'] ?? false) !== true) {
                return Check::na();
            }
            $maxAge = $asset['max_age'] ?? null;

            return $maxAge === null ? Check::fail(0) : Check::atLeast((float) $maxAge, (float) $rule->threshold);
        },
    ],
    [
        'id' => 'B7a', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.x-content-type-options') !== null ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        'id' => 'B7b', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown();
            }
            $xfo = $c->get('probe.http.security_headers.x-frame-options');
            $csp = $c->get('probe.http.security_headers.content-security-policy');

            return ($xfo ?? $csp) !== null ? Check::pass() : Check::fail();
        },
    ],
    [
        'id' => 'B7c', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.content-security-policy') !== null ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        'id' => 'B7d', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Info,
        'check' => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.referrer-policy') !== null ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        'id' => 'B7e', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Info,
        'check' => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.permissions-policy') !== null ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        'id' => 'B9', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown();
            }
            $leaks = [];
            foreach (['server', 'x-powered-by'] as $header) {
                $value = $c->string("probe.http.fingerprint.{$header}");
                if ($value !== null && preg_match('/\d+\.\d+/', $value)) {
                    $leaks[] = "{$header}: {$value}";
                }
            }

            return $leaks === [] ? Check::pass('none') : Check::fail(implode(' ; ', $leaks));
        },
    ],

    // ===================================================================
    //  C. DNS & AVAILABILITY                                       [EXT]
    // ===================================================================
    [
        'id' => 'C1', 'category' => Category::DNS, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown();
            }
            $aaaa = $c->list('probe.dns.aaaa');

            return $aaaa !== [] ? Check::pass(count($aaaa)) : Check::fail(0);
        },
    ],
    [
        'id' => 'C2', 'category' => Category::DNS, 'source' => 'EXT', 'severity' => Severity::Info,
        'check' => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown();
            }
            $caa = $c->list('probe.dns.caa');

            return $caa !== [] ? Check::pass(count($caa)) : Check::fail(0);
        },
    ],
    [
        'id' => 'C5', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 2,
        'check' => static function (Context $c, Rule $rule) {
            if (($c->bool('probe.http.redirects.loop_detected')) === true) {
                return Check::fail('loop', [], Severity::High);
            }

            return Check::atMost($c->number('probe.http.redirects.hops'), (float) $rule->threshold);
        },
    ],
    [
        'id' => 'C7', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $code = $c->number('probe.http.status_code');
            if ($code === null) {
                return Check::unknown();
            }

            return $code >= 200 && $code < 300 ? Check::pass((int) $code) : Check::fail((int) $code);
        },
    ],
    [
        'id' => 'C8', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $soft = $c->get('probe.http.soft_404');
            if (!is_array($soft) || ($soft['checked'] ?? false) !== true) {
                return Check::unknown();
            }

            return Check::isFalse((bool) ($soft['is_soft_404'] ?? false));
        },
    ],
    [
        'id' => 'C9', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $robots = $c->get('probe.http.robots');
            if (!is_array($robots)) {
                return Check::unknown();
            }

            return ($robots['present'] ?? false) === true ? Check::pass('present') : Check::fail('absent');
        },
    ],
    [
        'id' => 'C9a', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Info,
        'check' => static function (Context $c) {
            $robots = $c->get('probe.http.robots');
            if (!is_array($robots) || ($robots['present'] ?? false) !== true) {
                return Check::na();
            }

            return ($robots['disallow_all'] ?? false) === true ? Check::fail('blocked') : Check::pass('not blocked');
        },
    ],
    [
        'id' => 'C10', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $robots = $c->get('probe.http.robots');
            if (!is_array($robots) || ($robots['present'] ?? false) !== true) {
                return Check::unknown();
            }
            $sitemaps = $robots['sitemaps'] ?? [];
            if ($sitemaps === []) {
                return Check::fail(0, [], Severity::Info);
            }
            if (($robots['sitemap_reachable'] ?? null) === false) {
                return Check::fail(count($sitemaps), [], Severity::Info);
            }

            return Check::pass(count($sitemaps));
        },
    ],

    // ===================================================================
    //  D. EMAIL DELIVERABILITY (DNS side only)                     [EXT]
    // ===================================================================
    [
        'id' => 'D1', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static fn (Context $c) => $c->probeRan('dns')
            ? Check::isTrue($c->bool('probe.dns.spf.present')) : Check::unknown(),
    ],
    [
        'id' => 'D3', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown();
            }
            if ($c->bool('probe.dns.dmarc.present') !== true) {
                return Check::fail('absent');
            }
            $policy = $c->string('probe.dns.dmarc.policy');

            return in_array($policy, ['quarantine', 'reject'], true)
                ? Check::pass($policy)
                : Check::fail($policy ?? 'none', [], Severity::Medium);
        },
    ],
    [
        'id' => 'D4', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown();
            }
            $mx = $c->list('probe.dns.mx');

            return $mx !== [] ? Check::pass(count($mx)) : Check::fail(0);
        },
    ],

    // ===================================================================
    //  W. DOMAIN (WHOIS/RDAP — off-catalogue)                      [EXT]
    // ===================================================================
    [
        'id' => 'W1', 'category' => Category::DOMAIN, 'source' => 'EXT', 'severity' => Severity::High, 'threshold' => 30,
        'check' => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.rdap.days_to_expiry');
            if ($days === null) {
                return Check::unknown();
            }
            if ($days < 0) {
                return Check::fail($days, [], Severity::Critical);
            }

            return Check::graded($days, [[15, Severity::Critical], [(float) $rule->threshold, Severity::High]]);
        },
    ],

    // ===================================================================
    //  PS. PERFORMANCE (Lighthouse — off-catalogue)                [EXT]
    // ===================================================================
    [
        'id' => 'PS1', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.desktop.scores.performance'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS1a', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.performance'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS2', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.desktop.scores.accessibility'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS2a', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.accessibility'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS3', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.desktop.scores.seo'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS3a', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.seo'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS4', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Medium, 'threshold' => 2500,
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('probe.pagespeed.mobile.lab.lcp.value'), (float) $rule->threshold),
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
        'id' => 'F2', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $eol     = $c->reference('eol');
            $version = $c->string('payload.wp_version');
            if (!$eol instanceof EndOfLife || $version === null) {
                return Check::unknown();
            }
            $status = $eol->eolStatus('wordpress', $version);
            if ($status === null) {
                return Check::unknown();
            }
            [$isEol, $date] = $status;
            if ($isEol) {
                return Check::fail($version, ['eol_date' => $date]); // outdated branch, no longer patched — not a confirmed vulnerability
            }

            $available = $c->get('payload.core_update.available_version');
            if ($available !== null && $available !== '') {
                return Check::fail($version, ['eol_date' => $date, 'available' => (string) $available]);
            }

            return Check::pass($version, ['eol_date' => $date]);
        },
    ],
    [
        'id' => 'F3', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::High,
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

            return $isEol
                ? Check::fail($version, ['eol_date' => $date])
                : Check::pass($version, ['eol_date' => $date]);
        },
    ],
    [
        'id' => 'F4', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 0,
        'check' => static function (Context $c) {
            $plugins = $c->list('payload.plugins');
            if ($plugins === []) {
                return Check::unknown();
            }
            $outdated = array_values(array_filter($plugins, static fn ($p): bool => is_array($p) && !empty($p['new_version'])));
            if ($outdated === []) {
                return Check::pass(0);
            }
            $names = array_map(static fn (array $p): string => (string) ($p['name'] ?? '?'), $outdated);

            return Check::fail(count($outdated), ['names' => implode(', ', array_slice($names, 0, 10))]);
        },
    ],
    [
        'id' => 'F5', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $themes = $c->list('payload.themes');
            if ($themes === []) {
                return Check::unknown();
            }
            $outdated = array_filter($themes, static fn ($t): bool => is_array($t) && !empty($t['new_version']));

            return $outdated === [] ? Check::pass(0) : Check::fail(count($outdated));
        },
    ],
    [
        'id' => 'F7', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::High,
        'check' => static function (Context $c) {
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

            return $incompatible === []
                ? Check::pass(0)
                : Check::fail(count($incompatible), ['names' => implode(', ', $incompatible)]);
        },
    ],

    // ===================================================================
    //  G. PHP & SERVER                                            [DATA]
    // ===================================================================
    [
        'id' => 'G1', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $bytes = $c->bytes('payload.php.memory_limit');
            if ($bytes === null) {
                return Check::unknown();
            }
            $shown = $c->string('payload.php.memory_limit');
            $mb    = $bytes / 1048576;

            return match (true) {
                $mb < 64 || $mb > 1024 => Check::fail($shown, [], Severity::High), // far outside the range
                $mb < 256 || $mb > 512 => Check::fail($shown),                    // outside the sweet spot, not extreme
                default                 => Check::pass($shown),                    // 256–512 MB
            };
        },
    ],
    [
        'id' => 'G4', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 3000,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('payload.php.max_input_vars'), (float) $rule->threshold),
    ],
    [
        'id' => 'G5', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $extensions = $c->list('payload.php.extensions');
            if ($extensions === []) {
                return Check::unknown();
            }
            $present  = array_map('strtolower', array_map('strval', $extensions));
            $required = ['curl', 'mbstring', 'openssl', 'zip', 'dom', 'xml', 'json'];
            $missing  = array_values(array_diff($required, $present));
            if (!array_intersect(['gd', 'imagick'], $present)) {
                $missing[] = 'gd|imagick';
            }

            return $missing === [] ? Check::pass('all') : Check::fail(implode(', ', $missing));
        },
    ],
    [
        'id' => 'G6', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $extensions = $c->list('payload.php.extensions');
            if ($extensions === []) {
                return Check::unknown();
            }
            $present = array_map('strtolower', array_map('strval', $extensions));

            return in_array('zend opcache', $present, true) || in_array('opcache', $present, true)
                ? Check::pass(true) : Check::fail(false);
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
            $product = match (true) {
                str_contains($type, 'maria') => 'mariadb',
                str_contains($type, 'mysql') => 'mysql',
                default                      => null,
            };
            if ($product === null) {
                return Check::unknown();
            }
            $status = $eol->eolStatus($product, $version);
            if ($status === null) {
                return Check::unknown();
            }
            [$isEol, $date] = $status;
            $branch = $product . ' ' . EndOfLife::branch($version);

            return $isEol
                ? Check::fail($branch, ['eol_date' => $date])
                : Check::pass($version, ['eol_date' => $date]);
        },
    ],
    [
        'id' => 'H4', 'category' => Category::DATABASE, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 10485760,
        'check' => static function (Context $c, Rule $rule) {
            $tables = $c->list('payload.database.tables');
            if ($tables === []) {
                return Check::unknown();
            }
            $overhead = array_sum(array_map(static fn ($t): float => is_array($t) ? (float) ($t['overhead_bytes'] ?? 0) : 0.0, $tables));

            return Check::atMost($overhead, (float) $rule->threshold);
        },
    ],
    [
        'id' => 'H5', 'category' => Category::DATABASE, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 250,
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.database.transients.expired'), (float) $rule->threshold),
    ],
    [
        'id' => 'H9', 'category' => Category::DATABASE, 'source' => 'DATA', 'severity' => Severity::Info,
        'check' => static function (Context $c) {
            $prefix = $c->string('payload.db_table_prefix');

            return $prefix === null ? Check::unknown() : ($prefix === 'wp_' ? Check::fail($prefix) : Check::pass($prefix));
        },
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

            return match (true) {
                $bytes < 512000   => Check::pass($bytes),                       // < 500 KB
                $bytes < 2097152  => Check::fail($bytes, [], Severity::Medium), // 500 KB – 2 MB
                default           => Check::fail($bytes),                      // ≥ 2 MB — red (rule's own default severity)
            };
        },
    ],
    [
        'id' => 'I4', 'category' => Category::CACHE, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.object_cache.external')),
    ],

    // ===================================================================
    //  J. CRON                                                    [DATA]
    // ===================================================================
    [
        'id' => 'J2', 'category' => Category::CRON, 'source' => 'DATA', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $overdue = $c->number('payload.cron.overdue_events');
            if ($overdue === null) {
                return Check::unknown();
            }
            if ($overdue <= 0) {
                return Check::pass(0);
            }
            $minutes = $c->number('payload.cron.overdue_minutes');
            $mild    = $overdue <= 10 && $minutes !== null && $minutes <= 15;

            return $mild
                ? Check::fail((int) $overdue, ['overdue_minutes' => (int) $minutes], Severity::Medium)
                : Check::fail((int) $overdue, ['overdue_minutes' => $minutes]); // red: rule's own default severity
        },
    ],
    [
        'id' => 'J3', 'category' => Category::CRON, 'source' => 'DATA', 'severity' => Severity::Medium, 'threshold' => 100,
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.cron.scheduled_events'), (float) $rule->threshold),
    ],

    // ===================================================================
    //  K. CONFIGURATION & HARDENING                               [DATA]
    // ===================================================================
    [
        'id' => 'K1', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static function (Context $c) {
            $debug = $c->constant('WP_DEBUG');
            if ($debug === null) {
                return Check::unknown();
            }
            if ($debug === false) {
                return Check::pass(false);
            }

            $sensitiveFiles        = $c->get('probe.http.exposure.sensitive_files');
            $loggingToDefaultPath  = $c->get('payload.constants.WP_DEBUG_LOG') === true;
            $defaultLogNotExposed  = is_array($sensitiveFiles) && !in_array('wp-content/debug.log', $sensitiveFiles, true);

            return ($loggingToDefaultPath && $defaultLogNotExposed)
                ? Check::pass(true, ['debug_log' => 'not public'])
                : Check::fail(true);
        },
    ],
    [
        'id' => 'K2', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $debug = $c->constant('WP_DEBUG');
            if ($debug === null) {
                return Check::unknown();
            }

            return $debug === false ? Check::pass(false) : Check::isFalse($c->constant('WP_DEBUG_DISPLAY'));
        },
    ],
    [
        'id' => 'K3', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::High,
        'check' => static function (Context $c) {
            $debug = $c->constant('WP_DEBUG');
            if ($debug === null) {
                return Check::unknown();
            }
            if ($debug === false) {
                return Check::na();
            }

            $debugLog = $c->get('payload.constants.WP_DEBUG_LOG');
            if ($debugLog === null || $debugLog === 'N/A' || $debugLog === false || $debugLog === '') {
                return Check::na();
            }

            return $debugLog === true ? Check::fail(true) : Check::pass((string) $debugLog);
        },
    ],
    [
        'id' => 'K4', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isTrue($c->constant('DISALLOW_FILE_EDIT')),
    ],
    [
        'id' => 'K6', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isTrue($c->constant('FORCE_SSL_ADMIN')),
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
            $lowSpace = $percent < 20 || $free < 2147483648; // 20% or 2 GiB

            return $lowSpace
                ? Check::fail($percent, ['free_bytes' => $free], Severity::Medium)
                : Check::pass($percent);
        },
    ],
    [
        'id' => 'L4', 'category' => Category::HOSTING, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.filesystem.uploads_writable')),
    ],
    [
        'id' => 'L5', 'category' => Category::HOSTING, 'source' => 'DATA', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('payload.filesystem.core_writable')),
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

    // ===================================================================
    //  BV. BLOGVAULT — HACKED STATUS, VULNERABILITIES                [EXT]
    // ===================================================================
    // BlogVault is the single agreed source for these (SOURCE 12). Every rule
    // returns unknown when the site is not under BlogVault management, so an
    // unmanaged site never looks like a failing one.
    [
        'id' => 'BV1', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Critical,
        'check' => static function (Context $c) {
            $status = $c->string('probe.blogvault.scanner.status');
            if ($status === null) {
                return Check::unknown();
            }
            $unresolved = (int) ($c->number('probe.blogvault.scanner.unresolved_count') ?? 0);

            return ($status === 'hacked' || $unresolved > 0)
                ? Check::fail($status, ['detections' => $unresolved])
                : Check::pass($status);
        },
    ],
    [
        'id' => 'BV2', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Critical,
        'check' => static function (Context $c) {
            $total = $c->number('probe.blogvault.vulnerabilities_total');
            if ($total === null) {
                return Check::unknown();
            }
            if ($total <= 0) {
                return Check::pass(0);
            }

            $components = (int) ($c->number('probe.blogvault.plugins.vulnerable_count') ?? 0)
                + (int) ($c->number('probe.blogvault.themes.vulnerable_count') ?? 0)
                + (($c->bool('probe.blogvault.core.vulnerable') === true) ? 1 : 0);

            return Check::fail((int) $total, ['components' => $components]);
        },
    ],

    // ===================================================================
    //  WF. WORDFENCE INTELLIGENCE — second, independent detector       [EXT]
    // ===================================================================
    // BV2 already covers BlogVault's vulnerability signal. WF1 is not a
    // duplicate: it is sourced from an entirely separate database (Wordfence
    // Intelligence, matched locally against the site's own plugin/theme
    // versions — see WordfenceProbe), so it catches gaps in either single
    // source. A site can fail BV2, WF1, both, or neither.
    [
        'id' => 'WF1', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Critical,
        'check' => static function (Context $c) {
            $total = $c->number('probe.wordfence.vulnerabilities_total');
            if ($total === null) {
                return Check::unknown();
            }
            if ($total <= 0) {
                return Check::pass(0);
            }

            $components = (int) ($c->number('probe.wordfence.plugins.vulnerable_count') ?? 0)
                + (int) ($c->number('probe.wordfence.themes.vulnerable_count') ?? 0)
                + (($c->count('probe.wordfence.core.vulnerabilities') ?? 0) > 0 ? 1 : 0);

            return Check::fail((int) $total, ['components' => $components]);
        },
    ],

    // ===================================================================
    //  X. EXPOSURE — passive attack-surface probes                    [EXT]
    // ===================================================================
    // Not from the source document (no section letter to inherit): a new,
    // distinct prefix, same reasoning as W*/PS* above. Every check here is a
    // request an anonymous visitor could already make — this only automates
    // the well-known targets (HttpProbe::exposureCheck()) so an analyst does
    // not have to run a separate scanner for the basics.
    [
        'id' => 'X1', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.xmlrpc_enabled')),
    ],
    [
        'id' => 'X2', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.rest_user_enumeration')),
    ],
    [
        'id' => 'X3', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.author_enumeration')),
    ],
    [
        'id' => 'X4', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Critical,
        'check' => static function (Context $c) {
            // null (skipped — the site has a soft-404 catch-all, see
            // HttpProbe::exposureCheck()) must read as unknown, never as a
            // clean pass: every path would have answered 200 regardless.
            $found = $c->get('probe.http.exposure.sensitive_files');
            if (!is_array($found)) {
                return Check::unknown();
            }

            return $found === [] ? Check::pass('none') : Check::fail(implode(', ', $found));
        },
    ],
    [
        'id' => 'X5', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Medium,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.directory_listing')),
    ],
    [
        'id' => 'X6', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Info,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.trace_enabled')),
    ],
];
