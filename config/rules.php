<?php
/**
 * Rule catalogue — the executable form of .github/validations-techniques.txt
 * (repo satellitewp-plugin-maintenance).
 *
 * Identifiers, categories, sources and severities follow that document exactly.
 * Two prefixes are additions NOT present in it, kept distinct so they can never
 * collide with future catalogue ids:
 *   W*  — domain registration (WHOIS/RDAP)
 *   PS* — Lighthouse / PageSpeed scores
 *
 * Only rules evaluable with today's data are listed: the plugin payload [DATA]
 * and the dns / rdap / tls / http / pagespeed probes [EXT]. Rules needing data
 * we do not collect yet ([DATA+], BlogVault, mail inbox, exposure probes) are
 * deliberately absent rather than silently reported as "unknown".
 *
 * Each rule: id, category, source, severity, title, message, threshold, check.
 * Thresholds are overridable per id via config: rules.thresholds.<id>
 */

declare(strict_types=1);

use SatelliteWP\Xtractor\Rules\Check;
use SatelliteWP\Xtractor\Rules\Context;
use SatelliteWP\Xtractor\Rules\Rule;
use SatelliteWP\Xtractor\Rules\Severity;

return [

    // ===================================================================
    //  A. TLS / CERTIFICAT SSL                                     [EXT]
    // ===================================================================
    [
        'id' => 'A1', 'category' => 'TLS', 'source' => 'EXT', 'severity' => Severity::Elevee,
        'title'   => 'Certificat SSL valide (non expiré)',
        'message' => 'Le certificat SSL est expiré. Le renouveler immédiatement.',
        'check'   => static function (Context $c) {
            $days = $c->number('probe.tls.days_to_expiry');

            return $days === null
                ? Check::unknown('Sonde TLS indisponible')
                : ($days > 0 ? Check::pass($days) : Check::fail($days));
        },
    ],
    [
        'id' => 'A2', 'category' => 'TLS', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'     => 'Expiration du certificat non imminente',
        'message'   => 'Le certificat expire dans {observed} jours. Vérifier le renouvellement automatique.',
        'threshold' => 30,
        'check'     => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.tls.days_to_expiry');
            if ($days !== null && $days <= 0) {
                return Check::na('Déjà couvert par A1 (certificat expiré)');
            }

            // Graded, per the document: <15 j (É) / <30 j (M).
            return Check::graded($days, [[15, Severity::Elevee], [(float) $rule->threshold, Severity::Moyenne]]);
        },
    ],
    [
        'id' => 'A3', 'category' => 'TLS', 'source' => 'EXT', 'severity' => Severity::Elevee,
        'title'   => 'Chaîne de certification complète',
        'message' => 'La chaîne de certification est incomplète ou non vérifiable (intermédiaire manquant).',
        'check'   => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.chain_valid')),
    ],
    [
        'id' => 'A4', 'category' => 'TLS', 'source' => 'EXT', 'severity' => Severity::Elevee,
        'title'   => 'Nom d\'hôte couvert par le certificat',
        'message' => 'Le nom d\'hôte du site n\'est pas couvert par le certificat (CN/SAN).',
        'check'   => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.hostname_covered')),
    ],
    [
        'id' => 'A5', 'category' => 'TLS', 'source' => 'EXT', 'severity' => Severity::Critique,
        'title'   => 'Émetteur de confiance (pas auto-signé)',
        'message' => 'Le certificat est auto-signé : les navigateurs afficheront un avertissement de sécurité.',
        'check'   => static fn (Context $c) => Check::isFalse($c->bool('probe.tls.self_signed')),
    ],
    [
        'id' => 'A6', 'category' => 'TLS', 'source' => 'EXT', 'severity' => Severity::Elevee,
        'title'   => 'Protocoles obsolètes TLS 1.0 / 1.1 désactivés',
        'message' => 'Le serveur accepte encore {observed}. Désactiver TLS 1.0 et 1.1.',
        'check'   => static function (Context $c) {
            $protocols = $c->get('probe.tls.protocols');
            if (!is_array($protocols)) {
                return Check::unknown('Sonde TLS indisponible');
            }

            $legacy = array_keys(array_filter([
                'TLS 1.0' => $protocols['tls1_0'] ?? false,
                'TLS 1.1' => $protocols['tls1_1'] ?? false,
            ]));

            return $legacy === [] ? Check::pass('aucun') : Check::fail(implode(' et ', $legacy));
        },
    ],
    [
        'id' => 'A8', 'category' => 'TLS', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'En-tête HSTS présent',
        'message' => 'L\'en-tête Strict-Transport-Security est absent. L\'ajouter pour forcer HTTPS côté navigateur.',
        'check'   => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown('Sonde HTTP indisponible');
            }

            return $c->get('probe.http.security_headers.strict-transport-security') !== null
                ? Check::pass($c->string('probe.http.security_headers.strict-transport-security'))
                : Check::fail(null);
        },
    ],
    [
        'id' => 'A10', 'category' => 'TLS', 'source' => 'EXT', 'severity' => Severity::Elevee,
        'title'   => 'Redirection HTTP vers HTTPS forcée',
        'message' => 'Le site répond en HTTP sans rediriger vers HTTPS. Mettre en place une redirection 301.',
        'check'   => static fn (Context $c) => Check::isTrue($c->bool('probe.http.redirects.forces_https')),
    ],

    // ===================================================================
    //  B. EN-TÊTES HTTP & RÉSEAU                                   [EXT]
    // ===================================================================
    [
        'id' => 'B1', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'Compression du document HTML active',
        'message' => 'Le HTML est servi sans compression. Activer gzip ou Brotli.',
        'check'   => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown('Sonde HTTP indisponible');
            }
            $encoding = $c->string('probe.http.content_encoding');

            return $encoding !== null ? Check::pass($encoding) : Check::fail('aucune');
        },
    ],
    [
        'id' => 'B1b', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'Compression des assets statiques (CSS/JS)',
        'message' => 'Les assets statiques sont servis sans compression, contrairement au HTML.',
        'check'   => static function (Context $c) {
            $asset = $c->get('probe.http.asset');
            if (!is_array($asset)) {
                return Check::unknown('Sonde HTTP indisponible');
            }
            if (($asset['checked'] ?? false) !== true) {
                // No first-party CSS/JS on the page is not a failure.
                return Check::na((string) ($asset['reason'] ?? $asset['error'] ?? 'Aucun asset testé'));
            }

            return $asset['content_encoding'] !== null
                ? Check::pass($asset['content_encoding'])
                : Check::fail('aucune', 'Asset testé : ' . ($asset['url'] ?? '?'));
        },
    ],
    [
        'id' => 'B2', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'Compression Brotli disponible',
        'message' => 'Brotli n\'est pas utilisé ({observed}). Il compresse mieux que gzip.',
        'check'   => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown('Sonde HTTP indisponible');
            }
            $encoding = $c->string('probe.http.content_encoding');

            return $encoding === 'br' ? Check::pass('br') : Check::fail($encoding ?? 'aucune');
        },
    ],
    [
        'id' => 'B3', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'HTTP/2 supporté',
        'message' => 'Le site est servi en HTTP/{observed}. Activer HTTP/2 améliore le chargement.',
        'check'   => static function (Context $c) {
            $version = $c->string('probe.http.http_version');
            if ($version === null) {
                return Check::unknown('Version HTTP non déterminée');
            }

            return (float) $version >= 2 ? Check::pass($version) : Check::fail($version);
        },
    ],
    [
        'id' => 'B6', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'     => 'En-têtes de cache sur les assets statiques',
        'message'   => 'Les assets statiques ont un cache de {observed} s (attendu : au moins {threshold} s).',
        'threshold' => 86400,
        'check'     => static function (Context $c, Rule $rule) {
            $asset = $c->get('probe.http.asset');
            if (!is_array($asset) || ($asset['checked'] ?? false) !== true) {
                return Check::na('Aucun asset testé');
            }

            $maxAge = $asset['max_age'] ?? null;

            return $maxAge === null
                ? Check::fail(0, 'Aucun Cache-Control max-age')
                : Check::atLeast((float) $maxAge, (float) $rule->threshold);
        },
    ],
    [
        'id' => 'B7a', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'En-tête X-Content-Type-Options: nosniff',
        'message' => 'L\'en-tête X-Content-Type-Options est absent.',
        'check'   => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.x-content-type-options') !== null
                ? Check::pass('nosniff') : Check::fail(null))
            : Check::unknown('Sonde HTTP indisponible'),
    ],
    [
        'id' => 'B7b', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'Protection contre le clickjacking (X-Frame-Options / CSP)',
        'message' => 'Ni X-Frame-Options ni Content-Security-Policy ne sont présents.',
        'check'   => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown('Sonde HTTP indisponible');
            }
            $xfo = $c->get('probe.http.security_headers.x-frame-options');
            $csp = $c->get('probe.http.security_headers.content-security-policy');

            return ($xfo ?? $csp) !== null ? Check::pass($xfo ?? 'CSP') : Check::fail(null);
        },
    ],
    [
        'id' => 'B7c', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'Content-Security-Policy présent',
        'message' => 'Aucune Content-Security-Policy n\'est définie.',
        'check'   => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.content-security-policy') !== null
                ? Check::pass(true) : Check::fail(null))
            : Check::unknown('Sonde HTTP indisponible'),
    ],
    [
        'id' => 'B7d', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Info,
        'title'   => 'Referrer-Policy définie',
        'message' => 'L\'en-tête Referrer-Policy est absent.',
        'check'   => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.referrer-policy') !== null
                ? Check::pass(true) : Check::fail(null))
            : Check::unknown('Sonde HTTP indisponible'),
    ],
    [
        'id' => 'B8', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'Cookies sécurisés (Secure, HttpOnly, SameSite)',
        'message' => 'Les cookies ne portent pas tous les attributs de sécurité : {observed}.',
        'check'   => static function (Context $c) {
            $cookies = $c->get('probe.http.cookies');
            if ($cookies === null) {
                return Check::na('Aucun cookie posé sur la page d\'accueil');
            }

            $missing = array_keys(array_filter([
                'Secure'   => !($cookies['secure'] ?? false),
                'HttpOnly' => !($cookies['httponly'] ?? false),
                'SameSite' => !($cookies['samesite'] ?? false),
            ]));

            return $missing === [] ? Check::pass('tous présents') : Check::fail('manque ' . implode(', ', $missing));
        },
    ],
    [
        'id' => 'B9', 'category' => 'HTTP', 'source' => 'EXT', 'severity' => Severity::Info,
        'title'   => 'Aucune divulgation de version serveur',
        'message' => 'Le serveur divulgue sa version : {observed}.',
        'check'   => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown('Sonde HTTP indisponible');
            }
            $leaks = [];
            foreach (['server', 'x-powered-by'] as $header) {
                $value = $c->string("probe.http.fingerprint.{$header}");
                if ($value !== null && preg_match('/\d+\.\d+/', $value)) {
                    $leaks[] = "{$header}: {$value}";
                }
            }

            return $leaks === [] ? Check::pass('aucune') : Check::fail(implode(' ; ', $leaks));
        },
    ],

    // ===================================================================
    //  C. DNS & DISPONIBILITÉ                                      [EXT]
    // ===================================================================
    [
        'id' => 'C1', 'category' => 'DNS', 'source' => 'EXT', 'severity' => Severity::Info,
        'title'   => 'IPv6 (enregistrement AAAA) disponible',
        'message' => 'Aucun enregistrement AAAA : le site n\'est pas joignable en IPv6.',
        'check'   => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown('Sonde DNS indisponible');
            }
            $aaaa = $c->list('probe.dns.aaaa');

            return $aaaa !== [] ? Check::pass(count($aaaa)) : Check::fail(0);
        },
    ],
    [
        'id' => 'C2', 'category' => 'DNS', 'source' => 'EXT', 'severity' => Severity::Info,
        'title'   => 'Enregistrement CAA présent',
        'message' => 'Aucun enregistrement CAA : n\'importe quelle autorité peut émettre un certificat.',
        'check'   => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown('Sonde DNS indisponible');
            }
            $caa = $c->list('probe.dns.caa');

            return $caa !== [] ? Check::pass(count($caa)) : Check::fail(0);
        },
    ],
    [
        'id' => 'C5', 'category' => 'DNS', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'     => 'Chaîne de redirection courte',
        'message'   => 'La chaîne de redirection compte {observed} sauts (maximum conseillé : {threshold}).',
        'threshold' => 2,
        'check'     => static function (Context $c, Rule $rule) {
            if (($c->bool('probe.http.redirects.loop_detected')) === true) {
                return Check::fail('boucle', 'Boucle de redirection détectée', Severity::Elevee);
            }

            return Check::atMost($c->number('probe.http.redirects.hops'), (float) $rule->threshold);
        },
    ],
    [
        'id' => 'C6', 'category' => 'Performance', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'     => 'Temps de réponse (TTFB) raisonnable',
        'message'   => 'Le TTFB est de {observed} ms (seuil : {threshold} ms).',
        'threshold' => 600,
        'check'     => static fn (Context $c, Rule $rule) => Check::atMost(
            $c->number('probe.http.ttfb_ms'),
            (float) $rule->threshold
        ),
    ],
    [
        'id' => 'C7', 'category' => 'DNS', 'source' => 'EXT', 'severity' => Severity::Elevee,
        'title'   => 'Site disponible (réponse 200)',
        'message' => 'Le site répond un code HTTP {observed}.',
        'check'   => static function (Context $c) {
            $code = $c->number('probe.http.status_code');
            if ($code === null) {
                return Check::unknown('Sonde HTTP indisponible');
            }

            return $code >= 200 && $code < 300 ? Check::pass((int) $code) : Check::fail((int) $code);
        },
    ],
    [
        'id' => 'C8', 'category' => 'DNS', 'source' => 'EXT', 'severity' => Severity::Info,
        'title'   => 'Page 404 correcte (pas de soft 404)',
        'message' => 'Une URL inexistante répond 200 au lieu de 404 (soft 404).',
        'check'   => static function (Context $c) {
            $soft = $c->get('probe.http.soft_404');
            if (!is_array($soft) || ($soft['checked'] ?? false) !== true) {
                return Check::unknown('Test soft 404 non effectué');
            }

            return Check::isFalse((bool) ($soft['is_soft_404'] ?? false));
        },
    ],

    // ===================================================================
    //  D. DÉLIVRABILITÉ E-MAIL (volet DNS seulement)               [EXT]
    // ===================================================================
    [
        'id' => 'D1', 'category' => 'E-mail', 'source' => 'EXT', 'severity' => Severity::Elevee,
        'title'   => 'Enregistrement SPF présent',
        'message' => 'Aucun enregistrement SPF : les e-mails du site risquent d\'être rejetés.',
        'check'   => static fn (Context $c) => $c->probeRan('dns')
            ? Check::isTrue($c->bool('probe.dns.spf.present'))
            : Check::unknown('Sonde DNS indisponible'),
    ],
    [
        'id' => 'D3', 'category' => 'E-mail', 'source' => 'EXT', 'severity' => Severity::Elevee,
        'title'   => 'DMARC présent avec une politique active',
        'message' => 'DMARC {observed}. Publier une politique (p=quarantine ou p=reject).',
        'check'   => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown('Sonde DNS indisponible');
            }
            if ($c->bool('probe.dns.dmarc.present') !== true) {
                return Check::fail('absent');
            }
            $policy = $c->string('probe.dns.dmarc.policy');

            return in_array($policy, ['quarantine', 'reject'], true)
                ? Check::pass($policy)
                : Check::fail('en p=' . ($policy ?? 'none'), null, Severity::Moyenne);
        },
    ],
    [
        'id' => 'D4', 'category' => 'E-mail', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'   => 'Enregistrements MX résolvables',
        'message' => 'Aucun enregistrement MX : le domaine ne peut pas recevoir d\'e-mail.',
        'check'   => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown('Sonde DNS indisponible');
            }
            $mx = $c->list('probe.dns.mx');

            return $mx !== [] ? Check::pass(count($mx)) : Check::fail(0);
        },
    ],

    // ===================================================================
    //  W. NOM DE DOMAINE (hors catalogue — WHOIS/RDAP)             [EXT]
    // ===================================================================
    [
        'id' => 'W1', 'category' => 'Domaine', 'source' => 'EXT', 'severity' => Severity::Elevee,
        'title'     => 'Expiration du domaine non imminente',
        'message'   => 'Le domaine expire dans {observed} jours. Renouveler sans tarder.',
        'threshold' => 30,
        'check'     => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.rdap.days_to_expiry');
            if ($days === null) {
                return Check::unknown('Date d\'expiration non obtenue');
            }
            if ($days < 0) {
                return Check::fail($days, 'Domaine expiré', Severity::Critique);
            }

            return Check::graded($days, [[15, Severity::Critique], [(float) $rule->threshold, Severity::Elevee]]);
        },
    ],

    // ===================================================================
    //  PS. PERFORMANCE LIGHTHOUSE (hors catalogue)                 [EXT]
    // ===================================================================
    [
        'id' => 'PS1', 'category' => 'Performance', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'     => 'Score de performance Lighthouse (mobile)',
        'message'   => 'Score de performance mobile de {observed}/100 (seuil : {threshold}).',
        'threshold' => 50,
        'check'     => static fn (Context $c, Rule $rule) => Check::atLeast(
            $c->number('probe.pagespeed.mobile.scores.performance'),
            (float) $rule->threshold
        ),
    ],
    [
        'id' => 'PS2', 'category' => 'Performance', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'     => 'Score d\'accessibilité Lighthouse (mobile)',
        'message'   => 'Score d\'accessibilité de {observed}/100 (seuil : {threshold}).',
        'threshold' => 90,
        'check'     => static fn (Context $c, Rule $rule) => Check::atLeast(
            $c->number('probe.pagespeed.mobile.scores.accessibility'),
            (float) $rule->threshold
        ),
    ],
    [
        'id' => 'PS3', 'category' => 'Performance', 'source' => 'EXT', 'severity' => Severity::Info,
        'title'     => 'Score SEO Lighthouse (mobile)',
        'message'   => 'Score SEO de {observed}/100 (seuil : {threshold}).',
        'threshold' => 90,
        'check'     => static fn (Context $c, Rule $rule) => Check::atLeast(
            $c->number('probe.pagespeed.mobile.scores.seo'),
            (float) $rule->threshold
        ),
    ],
    [
        'id' => 'PS4', 'category' => 'Performance', 'source' => 'EXT', 'severity' => Severity::Moyenne,
        'title'     => 'LCP (Largest Contentful Paint) sous le seuil',
        'message'   => 'Le LCP mobile est de {observed} ms (seuil : {threshold} ms).',
        'threshold' => 2500,
        'check'     => static fn (Context $c, Rule $rule) => Check::atMost(
            $c->number('probe.pagespeed.mobile.lab.lcp.value'),
            (float) $rule->threshold
        ),
    ],

    // ===================================================================
    //  F. VERSIONS & MISES À JOUR                                 [DATA]
    // ===================================================================
    [
        'id' => 'F1', 'category' => 'Versions', 'source' => 'DATA', 'severity' => Severity::Elevee,
        'title'   => 'Cœur WordPress à jour',
        'message' => 'WordPress {observed} n\'est pas à jour. Appliquer la mise à jour du cœur.',
        'check'   => static function (Context $c) {
            $available = $c->get('payload.core_update.available_version');
            $current   = $c->string('payload.wp_version');
            if ($current === null) {
                return Check::unknown('Version WP absente du payload');
            }

            return $available === null || $available === ''
                ? Check::pass($current)
                : Check::fail($current, "Version disponible : {$available}");
        },
    ],
    [
        'id' => 'F3', 'category' => 'Versions', 'source' => 'DATA', 'severity' => Severity::Elevee,
        'title'   => 'Version PHP encore supportée',
        'message' => 'PHP {observed} n\'est plus supporté (fin de vie {detail}). Planifier une montée de version.',
        'check'   => static function (Context $c) {
            $version = $c->string('payload.php.version');
            if ($version === null) {
                return Check::unknown('Version PHP absente du payload');
            }

            // Security-support end dates (php.net). Maintained server-side, as
            // the catalogue's architecture notes require.
            $eol = [
                '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31',
                '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31',
                '8.5' => '2029-12-31',
            ];

            $branch = implode('.', array_slice(explode('.', $version), 0, 2));
            if (!isset($eol[$branch])) {
                return Check::unknown("Branche PHP {$branch} inconnue de la table de référence");
            }

            return strtotime($eol[$branch]) < time()
                ? Check::fail($version, "Fin de support : {$eol[$branch]}")
                : Check::pass($version, "Supporté jusqu'au {$eol[$branch]}");
        },
    ],
    [
        'id' => 'F4', 'category' => 'Versions', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'     => 'Extensions à jour',
        'message'   => '{observed} extension(s) ont une mise à jour disponible.',
        'threshold' => 0,
        'check'     => static function (Context $c) {
            $plugins = $c->list('payload.plugins');
            if ($plugins === []) {
                return Check::unknown('Liste des extensions absente');
            }

            $outdated = array_values(array_filter(
                $plugins,
                static fn ($p): bool => is_array($p) && !empty($p['new_version'])
            ));

            if ($outdated === []) {
                return Check::pass(0);
            }

            $names = array_map(static fn (array $p): string => (string) ($p['name'] ?? '?'), $outdated);

            return Check::fail(count($outdated), 'À mettre à jour : ' . implode(', ', array_slice($names, 0, 10)));
        },
    ],
    [
        'id' => 'F5', 'category' => 'Versions', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'   => 'Thèmes à jour',
        'message' => '{observed} thème(s) ont une mise à jour disponible.',
        'check'   => static function (Context $c) {
            $themes = $c->list('payload.themes');
            if ($themes === []) {
                return Check::unknown('Liste des thèmes absente');
            }

            $outdated = array_filter(
                $themes,
                static fn ($t): bool => is_array($t) && !empty($t['new_version'])
            );

            return $outdated === [] ? Check::pass(0) : Check::fail(count($outdated));
        },
    ],
    [
        'id' => 'F7', 'category' => 'Versions', 'source' => 'DATA', 'severity' => Severity::Elevee,
        'title'   => 'Aucune extension n\'exige un PHP/WP supérieur à l\'environnement',
        'message' => '{observed} extension(s) exigent une version supérieure à l\'environnement réel.',
        'check'   => static function (Context $c) {
            $plugins = $c->list('payload.plugins');
            $php     = $c->string('payload.php.version');
            $wp      = $c->string('payload.wp_version');
            if ($plugins === [] || $php === null || $wp === null) {
                return Check::unknown('Données insuffisantes');
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
                : Check::fail(count($incompatible), implode(', ', $incompatible));
        },
    ],

    // ===================================================================
    //  G. PHP & SERVEUR                                           [DATA]
    // ===================================================================
    [
        'id' => 'G1', 'category' => 'PHP', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'     => 'memory_limit suffisant',
        'message'   => 'memory_limit vaut {observed}, en dessous des 256M recommandés.',
        'threshold' => 268435456, // 256M
        'check'     => static function (Context $c, Rule $rule) {
            $bytes = $c->bytes('payload.php.memory_limit');
            if ($bytes === null) {
                return Check::unknown('memory_limit absent');
            }

            return $bytes >= (float) $rule->threshold
                ? Check::pass($c->string('payload.php.memory_limit'))
                : Check::fail($c->string('payload.php.memory_limit'));
        },
    ],
    [
        'id' => 'G4', 'category' => 'PHP', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'     => 'max_input_vars suffisant',
        'message'   => 'max_input_vars vaut {observed} (recommandé : au moins {threshold}).',
        'threshold' => 3000,
        'check'     => static fn (Context $c, Rule $rule) => Check::atLeast(
            $c->number('payload.php.max_input_vars'),
            (float) $rule->threshold
        ),
    ],
    [
        'id' => 'G5', 'category' => 'PHP', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'   => 'Extensions PHP recommandées présentes',
        'message' => 'Extensions PHP manquantes : {observed}.',
        'check'   => static function (Context $c) {
            $extensions = $c->list('payload.php.extensions');
            if ($extensions === []) {
                return Check::unknown('Liste des extensions PHP absente');
            }

            $present  = array_map('strtolower', array_map('strval', $extensions));
            $required = ['curl', 'mbstring', 'openssl', 'zip', 'dom', 'xml', 'json'];
            $missing  = array_values(array_diff($required, $present));

            // gd or imagick satisfies image processing.
            if (!array_intersect(['gd', 'imagick'], $present)) {
                $missing[] = 'gd|imagick';
            }

            return $missing === [] ? Check::pass('toutes') : Check::fail(implode(', ', $missing));
        },
    ],
    [
        'id' => 'G6', 'category' => 'PHP', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'   => 'OPcache actif',
        'message' => 'L\'extension OPcache n\'est pas chargée : les performances PHP en pâtissent.',
        'check'   => static function (Context $c) {
            $extensions = $c->list('payload.php.extensions');
            if ($extensions === []) {
                return Check::unknown('Liste des extensions PHP absente');
            }

            $present = array_map('strtolower', array_map('strval', $extensions));

            return in_array('zend opcache', $present, true) || in_array('opcache', $present, true)
                ? Check::pass(true)
                : Check::fail(false);
        },
    ],

    // ===================================================================
    //  H. BASE DE DONNÉES                                         [DATA]
    // ===================================================================
    [
        'id' => 'H4', 'category' => 'Base de données', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'     => 'Fragmentation (overhead) des tables maîtrisée',
        'message'   => 'Les tables cumulent {observed} octets d\'overhead (seuil : {threshold}).',
        'threshold' => 52428800, // 50 Mo
        'check'     => static function (Context $c, Rule $rule) {
            $tables = $c->list('payload.database.tables');
            if ($tables === []) {
                return Check::unknown('Détail des tables absent');
            }

            $overhead = array_sum(array_map(
                static fn ($t): float => is_array($t) ? (float) ($t['overhead_bytes'] ?? 0) : 0.0,
                $tables
            ));

            return Check::atMost($overhead, (float) $rule->threshold);
        },
    ],
    [
        'id' => 'H5', 'category' => 'Base de données', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'     => 'Transients expirés non accumulés',
        'message'   => '{observed} transients expirés traînent en base (seuil : {threshold}).',
        'threshold' => 500,
        'check'     => static fn (Context $c, Rule $rule) => Check::atMost(
            $c->number('payload.database.transients.expired'),
            (float) $rule->threshold
        ),
    ],
    [
        'id' => 'H9', 'category' => 'Base de données', 'source' => 'DATA', 'severity' => Severity::Info,
        'title'   => 'Préfixe de tables non standard',
        'message' => 'Le préfixe de tables est le défaut « wp_ » : le changer complique les attaques automatisées.',
        'check'   => static function (Context $c) {
            $prefix = $c->string('payload.db_table_prefix');

            return $prefix === null
                ? Check::unknown('Préfixe absent')
                : ($prefix === 'wp_' ? Check::fail($prefix) : Check::pass($prefix));
        },
    ],

    // ===================================================================
    //  I. AUTOLOAD / CACHE                                        [DATA]
    // ===================================================================
    [
        'id' => 'I1', 'category' => 'Autoload', 'source' => 'DATA', 'severity' => Severity::Elevee,
        'title'     => 'Poids des options autoloadées',
        'message'   => 'Les options autoloadées pèsent {observed} octets (seuil : {threshold}).',
        'threshold' => 819200, // 800 Ko
        'check'     => static fn (Context $c, Rule $rule) => Check::atMost(
            $c->number('payload.autoload.total_bytes'),
            (float) $rule->threshold
        ),
    ],
    [
        'id' => 'I4', 'category' => 'Autoload', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'   => 'Cache objet persistant présent',
        'message' => 'Aucun cache objet persistant (Redis/Memcached) n\'est configuré.',
        'check'   => static fn (Context $c) => Check::isTrue($c->bool('payload.object_cache.external')),
    ],

    // ===================================================================
    //  J. CRON                                                    [DATA]
    // ===================================================================
    [
        'id' => 'J2', 'category' => 'Cron', 'source' => 'DATA', 'severity' => Severity::Elevee,
        'title'     => 'Aucun événement cron en retard',
        'message'   => '{observed} événements cron sont en retard : WP-Cron ne s\'exécute probablement pas.',
        'threshold' => 0,
        'check'     => static fn (Context $c, Rule $rule) => Check::atMost(
            $c->number('payload.cron.overdue_events'),
            (float) $rule->threshold
        ),
    ],
    [
        'id' => 'J3', 'category' => 'Cron', 'source' => 'DATA', 'severity' => Severity::Info,
        'title'     => 'Nombre d\'événements cron raisonnable',
        'message'   => '{observed} événements planifiés (seuil : {threshold}).',
        'threshold' => 100,
        'check'     => static fn (Context $c, Rule $rule) => Check::atMost(
            $c->number('payload.cron.scheduled_events'),
            (float) $rule->threshold
        ),
    ],

    // ===================================================================
    //  K. CONFIGURATION & DURCISSEMENT                            [DATA]
    // ===================================================================
    [
        'id' => 'K1', 'category' => 'Durcissement', 'source' => 'DATA', 'severity' => Severity::Elevee,
        'title'   => 'WP_DEBUG désactivé en production',
        'message' => 'WP_DEBUG est activé en production.',
        'check'   => static fn (Context $c) => Check::isFalse($c->bool('payload.constants.WP_DEBUG')),
    ],
    [
        'id' => 'K2', 'category' => 'Durcissement', 'source' => 'DATA', 'severity' => Severity::Elevee,
        'title'   => 'WP_DEBUG_DISPLAY désactivé en production',
        'message' => 'WP_DEBUG_DISPLAY est activé : les erreurs PHP s\'affichent aux visiteurs.',
        'check'   => static fn (Context $c) => Check::isFalse($c->bool('payload.constants.WP_DEBUG_DISPLAY')),
    ],
    [
        'id' => 'K4', 'category' => 'Durcissement', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'   => 'Édition de fichiers désactivée (DISALLOW_FILE_EDIT)',
        'message' => 'L\'éditeur de fichiers de l\'admin est actif : définir DISALLOW_FILE_EDIT à true.',
        'check'   => static fn (Context $c) => Check::isTrue($c->bool('payload.constants.DISALLOW_FILE_EDIT')),
    ],
    [
        'id' => 'K6', 'category' => 'Durcissement', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'   => 'SSL forcé sur l\'administration (FORCE_SSL_ADMIN)',
        'message' => 'FORCE_SSL_ADMIN n\'est pas activé.',
        'check'   => static fn (Context $c) => Check::isTrue($c->bool('payload.constants.FORCE_SSL_ADMIN')),
    ],

    // ===================================================================
    //  L. SYSTÈME DE FICHIERS                                     [DATA]
    // ===================================================================
    [
        'id' => 'L1', 'category' => 'Fichiers', 'source' => 'DATA', 'severity' => Severity::Elevee,
        'title'     => 'Espace disque libre suffisant',
        'message'   => 'Il ne reste que {observed} % d\'espace disque libre (seuil : {threshold} %).',
        'threshold' => 10,
        'check'     => static function (Context $c, Rule $rule) {
            $free  = $c->number('payload.filesystem.disk_free_bytes');
            $total = $c->number('payload.filesystem.disk_total_bytes');
            if ($free === null || $total === null || $total <= 0) {
                return Check::unknown('Données disque absentes');
            }

            return Check::atLeast(round($free / $total * 100, 1), (float) $rule->threshold);
        },
    ],
    [
        'id' => 'L4', 'category' => 'Fichiers', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'   => 'Dossier uploads inscriptible',
        'message' => 'Le dossier uploads n\'est pas inscriptible : les téléversements et mises à jour échoueront.',
        'check'   => static fn (Context $c) => Check::isTrue($c->bool('payload.filesystem.uploads_writable')),
    ],
    [
        'id' => 'L5', 'category' => 'Fichiers', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'   => 'Cœur WordPress non inscriptible en production',
        'message' => 'Les fichiers du cœur sont inscriptibles par le serveur web : durcir les permissions.',
        'check'   => static fn (Context $c) => Check::isFalse($c->bool('payload.filesystem.core_writable')),
    ],

    // ===================================================================
    //  M. UTILISATEURS & ACCÈS                                    [DATA]
    // ===================================================================
    [
        'id' => 'M1', 'category' => 'Utilisateurs', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'     => 'Nombre d\'administrateurs maîtrisé',
        'message'   => 'Le site compte {observed} administrateurs (seuil : {threshold}).',
        'threshold' => 5,
        'check'     => static function (Context $c, Rule $rule) {
            $count = $c->count('payload.administrators');

            return $count === null
                ? Check::unknown('Liste des administrateurs absente')
                : Check::atMost((float) $count, (float) $rule->threshold);
        },
    ],
    [
        'id' => 'M2', 'category' => 'Utilisateurs', 'source' => 'DATA', 'severity' => Severity::Moyenne,
        'title'   => 'Aucun compte administrateur nommé « admin »',
        'message' => 'Un compte administrateur utilise l\'identifiant par défaut « admin ».',
        'check'   => static function (Context $c) {
            $admins = $c->list('payload.administrators');
            if ($admins === []) {
                return Check::unknown('Liste des administrateurs absente');
            }

            foreach ($admins as $admin) {
                if (is_array($admin) && strtolower((string) ($admin['login'] ?? '')) === 'admin') {
                    return Check::fail('admin');
                }
            }

            return Check::pass('aucun');
        },
    ],
];
