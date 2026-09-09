# Catalogue des règles — SatelliteWP Xtractor

**Généré, pas écrit à la main** — ne pas éditer ce fichier directement, les
modifications seraient perdues au prochain export. La vérité vit dans
`config/rules.php` (et `config/lang/{fr,en}.php` pour les textes) ; pour
republier cette page après un changement de règle :

```
php bin/xtractor rules:doc > docs/rules-catalog.md
```

77 règles, 17 groupes.

## A. TLS / SSL

### A1 — Certificat SSL valide

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le certificat SSL est valide.
- **Échec (FR) :** Le certificat SSL est expiré. Le renouveler immédiatement.

```php
        'check' => static function (Context $c) {
            $days = $c->number('probe.tls.days_to_expiry');

            return $days === null ? Check::unknown() : ($days > 0 ? Check::pass($days) : Check::fail($days));
        },
```

### A2 — Expiration du certificat non imminente

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 30
- **Réussite (FR) :** Le certificat est valide encore {observed} jours.
- **Échec (FR) :** Le certificat expire dans {observed} jours. Vérifier le renouvellement automatique.

```php
        'check' => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.tls.days_to_expiry');
            if ($days !== null && $days <= 0) {
                return Check::na(); // covered by A1
            }

            return Check::graded($days, [[15, Severity::High], [(float) $rule->threshold, Severity::Medium]]);
        },
```

### A3 — Chaîne de certification complète

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** La chaîne de certification est incomplète (intermédiaire manquant).

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.chain_valid')),
```

### A4 — Nom d'hôte couvert par le certificat

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Le nom d'hôte du site n'est pas couvert par le certificat (CN/SAN).

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.hostname_covered')),
```

### A5 — Émetteur de confiance (pas auto-signé)

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Critique · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Le certificat est auto-signé : les navigateurs afficheront un avertissement.

```php
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.tls.self_signed')),
```

### A6 — TLS 1.0/1.1 obsolètes désactivés

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Seules les versions TLS modernes sont acceptées.
- **Échec (FR) :** Le serveur accepte encore {observed}. Désactiver TLS 1.0 et 1.1.

```php
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
```

### A8 — En-tête HSTS présent

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** L'en-tête Strict-Transport-Security est absent.

```php
        'check' => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown();
            }

            return $c->get('probe.http.security_headers.strict-transport-security') !== null
                ? Check::pass() : Check::fail();
        },
```

### A10 — Redirection HTTP vers HTTPS

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le HTTP est redirigé vers HTTPS.
- **Échec (FR) :** Le site répond en HTTP sans rediriger vers HTTPS. Ajouter une redirection 301.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.redirects.forces_https')),
```

## B. En-têtes HTTP & réseau

### B1 — Compression Gzip

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le serveur retourne du contenu compressé en gzip quand on le demande.
- **Échec (FR) :** Le serveur ne retourne pas de gzip quand gzip est le seul encodage proposé. Activer la compression gzip.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.compression.gzip')),
```

### B2 — Compression Brotli

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le serveur retourne du contenu compressé en Brotli quand on le demande.
- **Échec (FR) :** Le serveur ne retourne pas de Brotli quand Brotli est le seul encodage proposé ; il compresse mieux que gzip.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.compression.brotli')),
```

### B3 — HTTP/2 supporté

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le site négocie HTTP/2.
- **Échec (FR) :** Le site ne négocie pas HTTP/2. L'activer accélère le chargement.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http2')),
```

### B4 — HTTP/1.1 supporté

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le site répond en HTTP/1.1 quand on le demande.
- **Échec (FR) :** Le site ne répond pas en HTTP/1.1 quand cette version est explicitement demandée.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http1_1')),
```

### B5 — HTTP/3 annoncé

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le site annonce un support HTTP/3 (Alt-Svc : h3).
- **Échec (FR) :** Le site n'annonce pas de support HTTP/3 (aucune entrée « h3 » dans son en-tête Alt-Svc).

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http3_advertised')),
```

### B6 — En-têtes de cache sur les assets

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 86400
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Les assets statiques ont un cache de {observed}s (attendu au moins {threshold}s).

```php
        'check' => static function (Context $c, Rule $rule) {
            $asset = $c->get('probe.http.asset');
            if (!is_array($asset) || ($asset['checked'] ?? false) !== true) {
                return Check::na();
            }
            $maxAge = $asset['max_age'] ?? null;

            return $maxAge === null ? Check::fail(0) : Check::atLeast((float) $maxAge, (float) $rule->threshold);
        },
```

### B7a — X-Content-Type-Options: nosniff

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** L'en-tête X-Content-Type-Options est absent.

```php
        'check' => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.x-content-type-options') !== null ? Check::pass() : Check::fail())
            : Check::unknown(),
```

### B7b — Protection contre le clickjacking

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Ni X-Frame-Options ni Content-Security-Policy ne sont présents.

```php
        'check' => static function (Context $c) {
            if (!$c->probeRan('http')) {
                return Check::unknown();
            }
            $xfo = $c->get('probe.http.security_headers.x-frame-options');
            $csp = $c->get('probe.http.security_headers.content-security-policy');

            return ($xfo ?? $csp) !== null ? Check::pass() : Check::fail();
        },
```

### B7c — Content-Security-Policy présent

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Aucune Content-Security-Policy n'est définie.

```php
        'check' => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.content-security-policy') !== null ? Check::pass() : Check::fail())
            : Check::unknown(),
```

### B7d — Referrer-Policy définie

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** L'en-tête Referrer-Policy est absent.

```php
        'check' => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.referrer-policy') !== null ? Check::pass() : Check::fail())
            : Check::unknown(),
```

### B7e — Permissions-Policy définie

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** L'en-tête Permissions-Policy est absent.

```php
        'check' => static fn (Context $c) => $c->probeRan('http')
            ? ($c->get('probe.http.security_headers.permissions-policy') !== null ? Check::pass() : Check::fail())
            : Check::unknown(),
```

### B9 — Aucune divulgation de version serveur

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Le serveur divulgue sa version : {observed}.

```php
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
```

## C. DNS & disponibilité

### C1 — IPv6 (enregistrement AAAA)

- **Catégorie :** DNS · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Aucun enregistrement AAAA : le site n'est pas joignable en IPv6.

```php
        'check' => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown();
            }
            $aaaa = $c->list('probe.dns.aaaa');

            return $aaaa !== [] ? Check::pass(count($aaaa)) : Check::fail(0);
        },
```

### C2 — Enregistrement CAA présent

- **Catégorie :** DNS · **Source :** EXT · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Aucun enregistrement CAA : n'importe quelle autorité peut émettre un certificat.

```php
        'check' => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown();
            }
            $caa = $c->list('probe.dns.caa');

            return $caa !== [] ? Check::pass(count($caa)) : Check::fail(0);
        },
```

### C5 — Chaîne de redirection courte

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 2
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** La chaîne de redirection est trop longue ou boucle ({observed}).

```php
        'check' => static function (Context $c, Rule $rule) {
            if (($c->bool('probe.http.redirects.loop_detected')) === true) {
                return Check::fail('loop', [], Severity::High);
            }

            return Check::atMost($c->number('probe.http.redirects.hops'), (float) $rule->threshold);
        },
```

### C7 — Site disponible

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le site est disponible (HTTP {observed}).
- **Échec (FR) :** Le site répond un code HTTP {observed}.

```php
        'check' => static function (Context $c) {
            $code = $c->number('probe.http.status_code');
            if ($code === null) {
                return Check::unknown();
            }

            return $code >= 200 && $code < 300 ? Check::pass((int) $code) : Check::fail((int) $code);
        },
```

### C8 — Page 404 correcte

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Une URL inexistante répond 200 au lieu de 404 (soft 404).

```php
        'check' => static function (Context $c) {
            $soft = $c->get('probe.http.soft_404');
            if (!is_array($soft) || ($soft['checked'] ?? false) !== true) {
                return Check::unknown();
            }

            return Check::isFalse((bool) ($soft['is_soft_404'] ?? false));
        },
```

### C9 — robots.txt présent

- **Catégorie :** SEO · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** robots.txt est présent.
- **Échec (FR) :** robots.txt est absent.

```php
        'check' => static function (Context $c) {
            $robots = $c->get('probe.http.robots');
            if (!is_array($robots)) {
                return Check::unknown();
            }

            return ($robots['present'] ?? false) === true ? Check::pass('present') : Check::fail('absent');
        },
```

### C9a — robots.txt ne bloque pas tout

- **Catégorie :** SEO · **Source :** EXT · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** robots.txt n'interdit pas l'ensemble du site.
- **Échec (FR) :** robots.txt contient une règle « Disallow: / » globale : tout crawl est bloqué (peut-être volontaire, ex. site de test).

```php
        'check' => static function (Context $c) {
            $robots = $c->get('probe.http.robots');
            if (!is_array($robots) || ($robots['present'] ?? false) !== true) {
                return Check::na();
            }

            return ($robots['disallow_all'] ?? false) === true ? Check::fail('blocked') : Check::pass('not blocked');
        },
```

### C10 — Sitemap référencé dans robots.txt

- **Catégorie :** SEO · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** {observed} sitemap(s) déclaré(s) et joignable(s).
- **Échec (FR) :** Aucun sitemap n'est référencé dans robots.txt, ou le sitemap déclaré n'est pas joignable.

```php
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
```

## D. Délivrabilité e-mail (DNS)

### D1 — Enregistrement SPF présent

- **Catégorie :** EMAIL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Un enregistrement SPF est configuré.
- **Échec (FR) :** Aucun enregistrement SPF : les courriels du site risquent d'être rejetés.

```php
        'check' => static fn (Context $c) => $c->probeRan('dns')
            ? Check::isTrue($c->bool('probe.dns.spf.present')) : Check::unknown(),
```

### D3 — DMARC avec politique active

- **Catégorie :** EMAIL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** DMARC est actif (p={observed}).
- **Échec (FR) :** DMARC est {observed}. Publier une politique (p=quarantine ou p=reject).

```php
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
```

### D4 — Enregistrements MX résolvables

- **Catégorie :** EMAIL · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Aucun enregistrement MX : le domaine ne peut recevoir de courriel.

```php
        'check' => static function (Context $c) {
            if (!$c->probeRan('dns')) {
                return Check::unknown();
            }
            $mx = $c->list('probe.dns.mx');

            return $mx !== [] ? Check::pass(count($mx)) : Check::fail(0);
        },
```

## W. Domaine (WHOIS/RDAP)

### W1 — Expiration du domaine non imminente

- **Catégorie :** DOMAIN · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** 30
- **Réussite (FR) :** Le domaine est valide encore {observed} jours.
- **Échec (FR) :** Le domaine expire dans {observed} jours. Le renouveler sans tarder.

```php
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
```

## PS. Performance (Lighthouse/PageSpeed)

### PS1 — Performance Lighthouse (desktop)

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** Bon score de performance desktop ({observed}/100).
- **Échec (FR) :** Score de performance desktop de {observed}/100 (seuil {threshold}).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.desktop.scores.performance'), (float) $rule->threshold),
```

### PS1a — Performance Lighthouse (mobile)

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** Bon score de performance mobile ({observed}/100).
- **Échec (FR) :** Score de performance mobile de {observed}/100 (seuil {threshold}).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.performance'), (float) $rule->threshold),
```

### PS2 — Accessibilité Lighthouse (desktop)

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** Bon score d'accessibilité desktop ({observed}/100).
- **Échec (FR) :** Score d'accessibilité desktop de {observed}/100 (seuil {threshold}).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.desktop.scores.accessibility'), (float) $rule->threshold),
```

### PS2a — Accessibilité Lighthouse (mobile)

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** Bon score d'accessibilité mobile ({observed}/100).
- **Échec (FR) :** Score d'accessibilité mobile de {observed}/100 (seuil {threshold}).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.accessibility'), (float) $rule->threshold),
```

### PS3 — SEO Lighthouse (desktop)

- **Catégorie :** SEO · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** Bon score SEO desktop ({observed}/100).
- **Échec (FR) :** Score SEO desktop de {observed}/100 (seuil {threshold}).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.desktop.scores.seo'), (float) $rule->threshold),
```

### PS3a — SEO Lighthouse (mobile)

- **Catégorie :** SEO · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** Bon score SEO mobile ({observed}/100).
- **Échec (FR) :** Score SEO mobile de {observed}/100 (seuil {threshold}).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.seo'), (float) $rule->threshold),
```

### PS4 — LCP sous le seuil

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 2500
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Le LCP mobile est de {observed} ms (seuil {threshold} ms).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('probe.pagespeed.mobile.lab.lcp.value'), (float) $rule->threshold),
```

## F. Versions, mises à jour & fin de vie

### F1 — WordPress pas trop en retard sur les versions majeures

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** WordPress {observed} n'est pas loin derrière la version majeure actuelle.
- **Échec (FR) :** WordPress {observed} a {major_versions_behind} versions majeures de retard — les mises à jour ont peut-être cessé complètement.

```php
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
```

### F2 — Version WordPress sécurisée et à jour

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** WordPress {observed} est à jour (branche supportée jusqu'au {eol_date}).
- **Échec (FR) :** WordPress {observed} n'a pas la dernière mise à jour de sécurité de sa branche (fin de vie de la branche : {eol_date}).

```php
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
```

### F3 — Version PHP supportée

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** PHP {observed} est supporté (jusqu'au {eol_date}).
- **Échec (FR) :** PHP {observed} n'est plus supporté (fin de vie {eol_date}). Planifier une montée de version.

```php
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
```

### F4 — Extensions à jour

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 0
- **Réussite (FR) :** Toutes les extensions sont à jour.
- **Échec (FR) :** {observed} extension(s) ont une mise à jour disponible : {names}.

```php
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
```

### F5 — Thèmes à jour

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Tous les thèmes sont à jour.
- **Échec (FR) :** {observed} thème(s) ont une mise à jour disponible.

```php
        'check' => static function (Context $c) {
            $themes = $c->list('payload.themes');
            if ($themes === []) {
                return Check::unknown();
            }
            $outdated = array_filter($themes, static fn ($t): bool => is_array($t) && !empty($t['new_version']));

            return $outdated === [] ? Check::pass(0) : Check::fail(count($outdated));
        },
```

### F7 — Prérequis des extensions respectés

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** {observed} extension(s) exigent une version PHP/WP supérieure à l'environnement : {names}.

```php
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
```

## G. PHP & serveur

### G1 — memory_limit dans la plage recommandée

- **Catégorie :** PHP · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** memory_limit vaut {observed}, dans la plage recommandée (256M–512M).
- **Échec (FR) :** memory_limit vaut {observed}, hors de la plage recommandée (256M–512M).

```php
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
```

### G4 — max_input_vars suffisant

- **Catégorie :** PHP · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 3000
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** max_input_vars vaut {observed} (recommandé au moins {threshold}).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('payload.php.max_input_vars'), (float) $rule->threshold),
```

### G5 — Extensions PHP recommandées

- **Catégorie :** PHP · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Toutes les extensions PHP recommandées sont présentes.
- **Échec (FR) :** Extensions PHP manquantes : {observed}.

```php
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
```

### G6 — OPcache actif

- **Catégorie :** PHP · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** OPcache est actif.
- **Échec (FR) :** L'extension OPcache n'est pas chargée : les performances PHP en pâtissent.

```php
        'check' => static function (Context $c) {
            $extensions = $c->list('payload.php.extensions');
            if ($extensions === []) {
                return Check::unknown();
            }
            $present = array_map('strtolower', array_map('strval', $extensions));

            return in_array('zend opcache', $present, true) || in_array('opcache', $present, true)
                ? Check::pass(true) : Check::fail(false);
        },
```

## H. Base de données

### H1 — Version de base de données supportée

- **Catégorie :** DATABASE · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** La version de base de données est supportée (jusqu'au {eol_date}).
- **Échec (FR) :** {observed} n'est plus supporté (fin de vie {eol_date}). Planifier une montée de version.

```php
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
```

### H4 — Fragmentation des tables maîtrisée

- **Catégorie :** DATABASE · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 10485760
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Les tables cumulent {observed} octets d'overhead (seuil {threshold}).

```php
        'check' => static function (Context $c, Rule $rule) {
            $tables = $c->list('payload.database.tables');
            if ($tables === []) {
                return Check::unknown();
            }
            $overhead = array_sum(array_map(static fn ($t): float => is_array($t) ? (float) ($t['overhead_bytes'] ?? 0) : 0.0, $tables));

            return Check::atMost($overhead, (float) $rule->threshold);
        },
```

### H5 — Transients expirés non accumulés

- **Catégorie :** DATABASE · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 250
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** {observed} transients expirés traînent en base (seuil {threshold}).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.database.transients.expired'), (float) $rule->threshold),
```

### H9 — Préfixe de tables non standard

- **Catégorie :** DATABASE · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Le préfixe de tables est le défaut « wp_ » ; le changer complique les attaques automatisées.

```php
        'check' => static function (Context $c) {
            $prefix = $c->string('payload.db_table_prefix');

            return $prefix === null ? Check::unknown() : ($prefix === 'wp_' ? Check::fail($prefix) : Check::pass($prefix));
        },
```

## I. Autoload / cache objet

### I1 — Poids des options autoloadées

- **Catégorie :** CACHE · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Les options autoloadées pèsent {observed} octets — dans le budget (moins de 500 Ko).
- **Échec (FR) :** Les options autoloadées pèsent {observed} octets — hors de la plage recommandée (moins de 500 Ko idéalement, à corriger sans tarder au-delà de 2 Mo).

```php
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
```

### I4 — Cache objet persistant

- **Catégorie :** CACHE · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Un cache objet persistant est configuré.
- **Échec (FR) :** Aucun cache objet persistant (Redis/Memcached) n'est configuré.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.object_cache.external')),
```

## J. Cron

### J2 — Aucun événement cron en retard

- **Catégorie :** CRON · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Aucun événement cron en retard.
- **Échec (FR) :** {observed} événements cron sont en retard : WP-Cron ne s'exécute probablement pas.

```php
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
```

### J3 — Nombre d'événements cron raisonnable

- **Catégorie :** CRON · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 100
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** {observed} événements planifiés (seuil {threshold}).

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.cron.scheduled_events'), (float) $rule->threshold),
```

## K. Configuration & durcissement

### K1 — WP_DEBUG maîtrisé

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** WP_DEBUG est désactivé, ou activé avec un journal de débogage non exposé publiquement.
- **Échec (FR) :** WP_DEBUG est activé, et la confidentialité du journal de débogage n'est pas confirmée.

```php
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
```

### K2 — WP_DEBUG_DISPLAY désactivé

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** WP_DEBUG_DISPLAY est activé : les erreurs PHP s'affichent aux visiteurs.

```php
        'check' => static function (Context $c) {
            $debug = $c->constant('WP_DEBUG');
            if ($debug === null) {
                return Check::unknown();
            }

            return $debug === false ? Check::pass(false) : Check::isFalse($c->constant('WP_DEBUG_DISPLAY'));
        },
```

### K3 — Emplacement du journal de débogage

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** WP_DEBUG_LOG utilise un chemin personnalisé ({observed}), plus difficile à deviner.
- **Échec (FR) :** WP_DEBUG_LOG utilise l'emplacement par défaut (wp-content/debug.log), une cible facile à deviner.

```php
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
```

### K4 — Édition de fichiers désactivée

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** L'éditeur de fichiers de l'admin est désactivé.
- **Échec (FR) :** L'éditeur de fichiers de l'admin est actif : définir DISALLOW_FILE_EDIT à true.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->constant('DISALLOW_FILE_EDIT')),
```

### K6 — SSL forcé sur l'admin

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** SSL est forcé sur l'administration.
- **Échec (FR) :** FORCE_SSL_ADMIN n'est pas activé.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->constant('FORCE_SSL_ADMIN')),
```

## L. Système de fichiers

### L1 — Espace disque libre

- **Catégorie :** HOSTING · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** {observed}% d'espace disque libre.
- **Échec (FR) :** Il reste {observed}% d'espace disque libre — sous 20% ou moins de 2 Go.

```php
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
```

### L4 — Dossier uploads inscriptible

- **Catégorie :** HOSTING · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Le dossier uploads n'est pas inscriptible : les téléversements et mises à jour échoueront.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.filesystem.uploads_writable')),
```

### L5 — Cœur non inscriptible en production

- **Catégorie :** HOSTING · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** _(pas de texte dédié — voir le code)_
- **Échec (FR) :** Les fichiers du cœur sont inscriptibles par le serveur web : durcir les permissions.

```php
        'check' => static fn (Context $c) => Check::isFalse($c->bool('payload.filesystem.core_writable')),
```

## M. Utilisateurs & accès

### M1 — Nombre d'administrateurs maîtrisé

- **Catégorie :** USERS · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 3
- **Réussite (FR) :** Le site compte {observed} administrateur(s).
- **Échec (FR) :** Le site compte {observed} administrateurs (seuil {threshold}).

```php
        'check' => static function (Context $c, Rule $rule) {
            $count = $c->count('payload.administrators');

            return $count === null ? Check::unknown() : Check::atMost((float) $count, (float) $rule->threshold);
        },
```

### M2 — Aucun compte « admin » par défaut

- **Catégorie :** USERS · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Aucun compte « admin » par défaut.
- **Échec (FR) :** Un compte administrateur utilise l'identifiant par défaut « admin ».

```php
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
```

## BV. BlogVault

### BV1 — Site non signalé comme piraté

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Critique · **Seuil configurable :** —
- **Réussite (FR) :** L'analyse antimaliciel de BlogVault ne signale rien.
- **Échec (FR) :** BlogVault signale ce site comme piraté : {detections} détection(s) non résolue(s).

```php
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
```

### BV2 — Aucune vulnérabilité connue

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Critique · **Seuil configurable :** —
- **Réussite (FR) :** BlogVault ne recense aucune vulnérabilité connue pour le cœur, les extensions ni les thèmes.
- **Échec (FR) :** BlogVault recense {observed} vulnérabilités connues sur {components} composant(s).

```php
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
```

### BV3 — Authentification à deux facteurs des administrateurs

- **Catégorie :** USERS · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Tous les administrateurs ont l'authentification à deux facteurs activée.
- **Échec (FR) :** {observed} administrateur(s) sur {administrators} n'ont pas d'authentification à deux facteurs.

```php
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
```

## WF. Wordfence Intelligence

### WF1 — Aucune vulnérabilité connue (Wordfence)

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Critique · **Seuil configurable :** —
- **Réussite (FR) :** Wordfence Intelligence ne recense aucune vulnérabilité connue pour le cœur, les extensions ni les thèmes.
- **Échec (FR) :** Wordfence Intelligence recense {observed} vulnérabilités connues sur {components} composant(s).

```php
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
```

## X. Exposition (sondes passives)

### X1 — xmlrpc.php non exposé

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** xmlrpc.php est bloqué ou désactivé.
- **Échec (FR) :** xmlrpc.php répond — amplification de brute-force et abus de pingback possibles.

```php
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.xmlrpc_enabled')),
```

### X2 — Aucune divulgation d'identifiant via l'API REST

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** L'API REST ne divulgue pas les comptes utilisateurs.
- **Échec (FR) :** L'API REST liste les comptes utilisateurs sur /wp-json/wp/v2/users, divulguant chaque identifiant.

```php
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.rest_user_enumeration')),
```

### X3 — Aucune divulgation d'identifiant via les archives auteur

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** La redirection d'archive auteur ne divulgue pas d'identifiant.
- **Échec (FR) :** ?author=1 redirige vers l'archive de l'auteur, divulguant l'identifiant dans l'URL.

```php
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.author_enumeration')),
```

### X4 — Aucun fichier de sauvegarde/config exposé

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Critique · **Seuil configurable :** —
- **Réussite (FR) :** Aucun fichier de sauvegarde/config courant n'est accessible publiquement.
- **Échec (FR) :** Accessible publiquement : {observed}. Ces fichiers peuvent divulguer les identifiants de la base de données directement.

```php
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
```

### X5 — Dossier uploads non navigable

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** wp-content/uploads/ n'est pas navigable.
- **Échec (FR) :** wp-content/uploads/ retourne une liste de répertoire.

```php
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.directory_listing')),
```

### X6 — Méthode HTTP TRACE désactivée

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** Le serveur refuse HTTP TRACE.
- **Échec (FR) :** Le serveur répond à une requête HTTP TRACE (risque de cross-site tracing).

```php
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.http.exposure.trace_enabled')),
```

