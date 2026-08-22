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
        'id' => 'A1', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Elevee,
        'check' => static function (Context $c) {
            $days = $c->number('probe.tls.days_to_expiry');

            return $days === null ? Check::unknown() : ($days > 0 ? Check::pass($days) : Check::fail($days));
        },
    ],
    [
        'id' => 'A2', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Moyenne, 'threshold' => 30,
        'check' => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.tls.days_to_expiry');
            if ($days !== null && $days <= 0) {
                return Check::na(); // covered by A1
            }

            return Check::graded($days, [[15, Severity::Elevee], [(float) $rule->threshold, Severity::Moyenne]]);
        },
    ],
    [
        'id' => 'A3', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Elevee,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.chain_valid')),
    ],
    [
        'id' => 'A4', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Elevee,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.hostname_covered')),
    ],
    [
        'id' => 'A5', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Critique,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.tls.self_signed')),
    ],
    [
        'id' => 'A6', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Elevee,
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
        'id' => 'A8', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown();
            }

            return $c->get('probe.http.security_headers.strict-transport-security') !== null
                ? Check::pass() : Check::fail();
        },
    ],
    [
        'id' => 'A10', 'category' => Category::SSL, 'source' => 'EXT', 'severity' => Severity::Elevee,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.redirects.forces_https')),
    ],

    // ===================================================================
    //  B. HTTP HEADERS & NETWORK                                   [EXT]
    // ===================================================================
    [
        'id' => 'B1', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown();
            }
            $encoding = $c->string('probe.http.content_encoding');

            return $encoding !== null ? Check::pass($encoding) : Check::fail('none');
        },
    ],
    [
        'id' => 'B1b', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static function (Context $c) {
            $asset = $c->get('probe.http.asset');
            if (!is_array($asset)) {
                return Check::unknown();
            }
            if (($asset['checked'] ?? false) !== true) {
                return Check::na();
            }

            return $asset['content_encoding'] !== null
                ? Check::pass($asset['content_encoding'])
                : Check::fail('none', ['url' => (string) ($asset['url'] ?? '')]);
        },
    ],
    [
        'id' => 'B2', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown();
            }
            $encoding = $c->string('probe.http.content_encoding');

            return $encoding === 'br' ? Check::pass('br') : Check::fail($encoding ?? 'none');
        },
    ],
    [
        'id' => 'B3', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static function (Context $c) {
            $version = $c->string('probe.http.http_version');
            if ($version === null) {
                return Check::unknown();
            }

            return (float) $version >= 2 ? Check::pass($version) : Check::fail($version);
        },
    ],
    [
        'id' => 'B6', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Moyenne, 'threshold' => 86400,
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
        'id' => 'B7a', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.x-content-type-options') !== null ? Check::pass() : Check::fail())
            : Check::unknown(),
    ],
    [
        'id' => 'B7b', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Moyenne,
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
        'id' => 'B7c', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Moyenne,
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
        'id' => 'B8', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static function (Context $c) {
            $cookies = $c->get('probe.http.cookies');
            if ($cookies === null) {
                return Check::na();
            }
            $missing = array_keys(array_filter([
                'Secure'   => !($cookies['secure'] ?? false),
                'HttpOnly' => !($cookies['httponly'] ?? false),
                'SameSite' => !($cookies['samesite'] ?? false),
            ]));

            return $missing === [] ? Check::pass('all') : Check::fail(implode(', ', $missing));
        },
    ],
    [
        'id' => 'B9', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Info,
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
        'id' => 'C1', 'category' => Category::DNS, 'source' => 'EXT', 'severity' => Severity::Info,
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
        'id' => 'C5', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Moyenne, 'threshold' => 2,
        'check' => static function (Context $c, Rule $rule) {
            if (($c->bool('probe.http.redirects.loop_detected')) === true) {
                return Check::fail('loop', [], Severity::Elevee);
            }

            return Check::atMost($c->number('probe.http.redirects.hops'), (float) $rule->threshold);
        },
    ],
    [
        'id' => 'C6', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Moyenne, 'threshold' => 600,
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('probe.http.ttfb_ms'), (float) $rule->threshold),
    ],
    [
        'id' => 'C7', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Elevee,
        'check' => static function (Context $c) {
            $code = $c->number('probe.http.status_code');
            if ($code === null) {
                return Check::unknown();
            }

            return $code >= 200 && $code < 300 ? Check::pass((int) $code) : Check::fail((int) $code);
        },
    ],
    [
        'id' => 'C8', 'category' => Category::HTTP, 'source' => 'EXT', 'severity' => Severity::Info,
        'check' => static function (Context $c) {
            $soft = $c->get('probe.http.soft_404');
            if (!is_array($soft) || ($soft['checked'] ?? false) !== true) {
                return Check::unknown();
            }

            return Check::isFalse((bool) ($soft['is_soft_404'] ?? false));
        },
    ],
    [
        'id' => 'C9', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static function (Context $c) {
            $robots = $c->get('probe.http.robots');
            if (!is_array($robots)) {
                return Check::unknown();
            }
            if (($robots['present'] ?? false) !== true) {
                return Check::fail('absent');
            }

            return ($robots['disallow_all'] ?? false) === true
                ? Check::fail('blocked', [], Severity::Elevee)
                : Check::pass('present');
        },
    ],
    [
        'id' => 'C10', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Info,
        'check' => static function (Context $c) {
            $robots = $c->get('probe.http.robots');
            if (!is_array($robots) || ($robots['present'] ?? false) !== true) {
                return Check::unknown();
            }
            $sitemaps = $robots['sitemaps'] ?? [];
            if ($sitemaps === []) {
                return Check::fail(0);
            }
            if (($robots['sitemap_reachable'] ?? null) === false) {
                return Check::fail(count($sitemaps), [], Severity::Moyenne);
            }

            return Check::pass(count($sitemaps));
        },
    ],

    // ===================================================================
    //  D. EMAIL DELIVERABILITY (DNS side only)                     [EXT]
    // ===================================================================
    [
        'id' => 'D1', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::Elevee,
        'check' => static fn (Context $c) => $c->probeRan('dns')
            ? Check::isTrue($c->bool('probe.dns.spf.present')) : Check::unknown(),
    ],
    [
        'id' => 'D3', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::Elevee,
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
                : Check::fail($policy ?? 'none', [], Severity::Moyenne);
        },
    ],
    [
        'id' => 'D4', 'category' => Category::EMAIL, 'source' => 'EXT', 'severity' => Severity::Moyenne,
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
        'id' => 'W1', 'category' => Category::DOMAIN, 'source' => 'EXT', 'severity' => Severity::Elevee, 'threshold' => 30,
        'check' => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.rdap.days_to_expiry');
            if ($days === null) {
                return Check::unknown();
            }
            if ($days < 0) {
                return Check::fail($days, [], Severity::Critique);
            }

            return Check::graded($days, [[15, Severity::Critique], [(float) $rule->threshold, Severity::Elevee]]);
        },
    ],

    // ===================================================================
    //  PS. PERFORMANCE (Lighthouse — off-catalogue)                [EXT]
    // ===================================================================
    [
        'id' => 'PS1', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Moyenne, 'threshold' => 50,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.performance'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS2', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Moyenne, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.accessibility'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS3', 'category' => Category::SEO, 'source' => 'EXT', 'severity' => Severity::Info, 'threshold' => 90,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.seo'), (float) $rule->threshold),
    ],
    [
        'id' => 'PS4', 'category' => Category::PERFORMANCE, 'source' => 'EXT', 'severity' => Severity::Moyenne, 'threshold' => 2500,
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('probe.pagespeed.mobile.lab.lcp.value'), (float) $rule->threshold),
    ],

    // ===================================================================
    //  F. VERSIONS, UPDATES & END OF LIFE                         [DATA]
    // ===================================================================
    [
        'id' => 'F1', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Elevee,
        'check' => static function (Context $c) {
            $available = $c->get('payload.core_update.available_version');
            $current   = $c->string('payload.wp_version');
            if ($current === null) {
                return Check::unknown();
            }

            return ($available === null || $available === '')
                ? Check::pass($current)
                : Check::fail($current, ['available' => (string) $available]);
        },
    ],
    [
        'id' => 'F2', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Elevee,
        'check' => static function (Context $c) {
            $eol     = $c->reference('eol');
            $version = $c->string('payload.wp_version');
            if (!$eol instanceof EndOfLife) {
                return Check::unknown();
            }
            if ($version === null) {
                return Check::unknown();
            }
            $status = $eol->eolStatus('wordpress', $version);
            if ($status === null) {
                return Check::unknown();
            }
            [$isEol, $date] = $status;

            return $isEol ? Check::fail($version, ['eol_date' => $date]) : Check::pass($version);
        },
    ],
    [
        'id' => 'F3', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Elevee,
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
        'id' => 'F4', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Moyenne, 'threshold' => 0,
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
        'id' => 'F5', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Moyenne,
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
        'id' => 'F7', 'category' => Category::UPDATES, 'source' => 'DATA', 'severity' => Severity::Elevee,
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
        'id' => 'G1', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Moyenne, 'threshold' => 268435456,
        'check' => static function (Context $c, Rule $rule) {
            $bytes = $c->bytes('payload.php.memory_limit');
            if ($bytes === null) {
                return Check::unknown();
            }
            $shown = $c->string('payload.php.memory_limit');

            return $bytes >= (float) $rule->threshold ? Check::pass($shown) : Check::fail($shown);
        },
    ],
    [
        'id' => 'G4', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Moyenne, 'threshold' => 3000,
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('payload.php.max_input_vars'), (float) $rule->threshold),
    ],
    [
        'id' => 'G5', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Moyenne,
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
        'id' => 'G6', 'category' => Category::PHP, 'source' => 'DATA', 'severity' => Severity::Moyenne,
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
        'id' => 'H1', 'category' => Category::DATABASE, 'source' => 'DATA', 'severity' => Severity::Moyenne,
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
        'id' => 'H4', 'category' => Category::DATABASE, 'source' => 'DATA', 'severity' => Severity::Moyenne, 'threshold' => 52428800,
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
        'id' => 'H5', 'category' => Category::DATABASE, 'source' => 'DATA', 'severity' => Severity::Moyenne, 'threshold' => 500,
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
        'id' => 'I1', 'category' => Category::CACHE, 'source' => 'DATA', 'severity' => Severity::Elevee, 'threshold' => 819200,
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.autoload.total_bytes'), (float) $rule->threshold),
    ],
    [
        'id' => 'I4', 'category' => Category::CACHE, 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.object_cache.external')),
    ],

    // ===================================================================
    //  J. CRON                                                    [DATA]
    // ===================================================================
    [
        'id' => 'J2', 'category' => Category::CRON, 'source' => 'DATA', 'severity' => Severity::Elevee, 'threshold' => 0,
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.cron.overdue_events'), (float) $rule->threshold),
    ],
    [
        'id' => 'J3', 'category' => Category::CRON, 'source' => 'DATA', 'severity' => Severity::Info, 'threshold' => 100,
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.cron.scheduled_events'), (float) $rule->threshold),
    ],

    // ===================================================================
    //  K. CONFIGURATION & HARDENING                               [DATA]
    // ===================================================================
    [
        'id' => 'K1', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Elevee,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('payload.constants.WP_DEBUG')),
    ],
    [
        'id' => 'K2', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Elevee,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('payload.constants.WP_DEBUG_DISPLAY')),
    ],
    [
        'id' => 'K4', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.constants.DISALLOW_FILE_EDIT')),
    ],
    [
        'id' => 'K6', 'category' => Category::SECURITY, 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.constants.FORCE_SSL_ADMIN')),
    ],

    // ===================================================================
    //  L. FILESYSTEM                                              [DATA]
    // ===================================================================
    [
        'id' => 'L1', 'category' => Category::HOSTING, 'source' => 'DATA', 'severity' => Severity::Elevee, 'threshold' => 10,
        'check' => static function (Context $c, Rule $rule) {
            $free  = $c->number('payload.filesystem.disk_free_bytes');
            $total = $c->number('payload.filesystem.disk_total_bytes');
            if ($free === null || $total === null || $total <= 0) {
                return Check::unknown();
            }

            return Check::atLeast(round($free / $total * 100, 1), (float) $rule->threshold);
        },
    ],
    [
        'id' => 'L4', 'category' => Category::HOSTING, 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.filesystem.uploads_writable')),
    ],
    [
        'id' => 'L5', 'category' => Category::HOSTING, 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'check' => static fn (Context $c) => Check::isFalse($c->bool('payload.filesystem.core_writable')),
    ],

    // ===================================================================
    //  M. USERS & ACCESS                                          [DATA]
    // ===================================================================
    [
        'id' => 'M1', 'category' => Category::USERS, 'source' => 'DATA', 'severity' => Severity::Moyenne, 'threshold' => 5,
        'check' => static function (Context $c, Rule $rule) {
            $count = $c->count('payload.administrators');

            return $count === null ? Check::unknown() : Check::atMost((float) $count, (float) $rule->threshold);
        },
    ],
    [
        'id' => 'M2', 'category' => Category::USERS, 'source' => 'DATA', 'severity' => Severity::Moyenne,
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
    //  BV. BLOGVAULT — VULNERABILITIES, MALWARE, BACKUP            [EXT]
    // ===================================================================
    // BlogVault is the single agreed source for these (SOURCE 12). Every rule
    // returns unknown when the site is not under BlogVault management, so an
    // unmanaged site never looks like a failing one.
    [
        'id' => 'BV1', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Critique,
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
        'id' => 'BV2', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Critique,
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
    [
        'id' => 'BV3', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Elevee, 'threshold' => 7,
        'check' => static function (Context $c, Rule $rule) {
            if ($c->probeData('blogvault') === null || $c->bool('probe.blogvault.linked') !== true) {
                return Check::unknown();
            }
            if ($c->bool('probe.blogvault.backups.enabled') !== true) {
                return Check::fail('disabled');
            }
            $status = $c->string('probe.blogvault.backups.latest_snapshot.status');
            $age    = $c->number('probe.blogvault.backups.latest_snapshot.age_days');
            if ($status !== 'succeeded' || $age === null) {
                return Check::fail($status ?? 'none');
            }

            return $age <= (float) $rule->threshold
                ? Check::pass((int) $age)
                : Check::fail((int) $age, ['threshold' => $rule->threshold]);
        },
    ],
    [
        'id' => 'BV4', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static function (Context $c) {
            if ($c->probeData('blogvault') === null || $c->bool('probe.blogvault.linked') !== true) {
                return Check::unknown();
            }
            if ($c->bool('probe.blogvault.firewall.enabled') !== true) {
                return Check::fail('disabled');
            }
            $mode = $c->string('probe.blogvault.firewall.mode');

            return $mode === 'protect' ? Check::pass($mode) : Check::fail($mode ?? 'unknown');
        },
    ],
    [
        'id' => 'BV5', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Moyenne, 'threshold' => 7,
        'check' => static function (Context $c, Rule $rule) {
            $lastCheck = $c->string('probe.blogvault.scanner.last_check_at');
            if ($lastCheck === null) {
                return $c->bool('probe.blogvault.linked') === true ? Check::fail('never') : Check::unknown();
            }
            $when = strtotime($lastCheck);
            if ($when === false) {
                return Check::unknown();
            }
            $days = (int) floor((time() - $when) / 86400);

            return $days <= (int) $rule->threshold
                ? Check::pass($days)
                : Check::fail($days, ['threshold' => $rule->threshold]);
        },
    ],
    [
        'id' => 'BV6', 'category' => Category::USERS, 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'check' => static function (Context $c) {
            $admins = $c->number('probe.blogvault.users.administrators');
            if ($admins === null) {
                return Check::unknown();
            }
            if ($admins <= 0) {
                return Check::na();
            }
            $without = (int) ($c->number('probe.blogvault.users.administrators_without_2fa') ?? 0);

            return $without === 0
                ? Check::pass(0)
                : Check::fail($without, ['administrators' => (int) $admins]);
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
        'id' => 'WF1', 'category' => Category::SECURITY, 'source' => 'EXT', 'severity' => Severity::Critique,
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
];
