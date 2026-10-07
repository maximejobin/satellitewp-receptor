# Catalogue des règles — SatelliteWP Manager

**Généré, pas écrit à la main** — ne pas éditer ce fichier directement, les
modifications seraient perdues au prochain export. La vérité vit dans
`config/rules.php` (et `config/lang/{fr,en}.php` pour les textes) ; pour
republier cette page après un changement de règle :

```
php bin/swpmgr rules:doc > docs/rules-catalog.md
```

79 règles, 17 groupes.

## A. TLS / SSL

### A1 — Certificat SSL valide

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le certificat SSL de votre site est valide.
- **Échec (FR) :** Le certificat SSL de votre site est expiré : les navigateurs affichent un avertissement de sécurité à vos visiteurs. Renouvelez-le sans délai.

```php
        'check' => static function (Context $c) {
            $days = $c->number('probe.tls.days_to_expiry');

            return $days === null ? Check::unknown() : ($days > 0 ? Check::pass($days) : Check::fail($days));
        },
```

### A2 — Échéance du certificat SSL

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 30
- **Réussite (FR) :** Le certificat SSL est valide encore {observed} jours.
- **Échec (FR) :** Le certificat SSL expire dans {observed} jours ; ensuite, vos visiteurs verront un avertissement de sécurité. Vérifiez que son renouvellement automatique fonctionne.

```php
        'check' => static function (Context $c, Rule $rule) {
            $days = $c->number('probe.tls.days_to_expiry');
            if ($days !== null && $days <= 0) {
                return Check::na(); // A1 reports an expired certificate
            }

            return Check::graded($days, [[15, Severity::High], [(float) $rule->threshold, Severity::Medium]]);
        },
```

### A3 — Chaîne de certification complète

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** La chaîne de certification est complète : tous les navigateurs peuvent valider le certificat.
- **Échec (FR) :** Un certificat intermédiaire manque dans la configuration du serveur : certains navigateurs et appareils mobiles refuseront la connexion. Faites installer la chaîne complète par votre hébergeur.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.chain_valid')),
```

### A4 — Certificat émis pour l'adresse du site

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le certificat SSL couvre bien l'adresse de votre site.
- **Échec (FR) :** Le certificat SSL n'est pas émis pour l'adresse de votre site : les navigateurs affichent une erreur de sécurité. Faites émettre un certificat qui couvre ce nom de domaine.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.tls.hostname_covered')),
```

### A5 — Certificat émis par une autorité reconnue

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Critique · **Seuil configurable :** —
- **Réussite (FR) :** Le certificat est émis par une autorité de certification reconnue.
- **Échec (FR) :** Le certificat est auto-signé : aucun navigateur ne lui fait confiance et vos visiteurs voient un avertissement. Installez un certificat d'une autorité reconnue (Let's Encrypt est gratuit).

```php
        'check' => static fn (Context $c) => Check::isFalse($c->bool('probe.tls.self_signed')),
```

### A6 — Anciennes versions du chiffrement désactivées

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Seules les versions modernes du chiffrement (TLS 1.2 et plus récentes) sont acceptées.
- **Échec (FR) :** Le serveur accepte encore {observed}, des versions du chiffrement obsolètes et vulnérables. Demandez à votre hébergeur de les désactiver.

```php
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
```

### A8 — Connexion sécurisée imposée aux navigateurs (HSTS)

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le site demande aux navigateurs de toujours utiliser une connexion sécurisée (HSTS).
- **Échec (FR) :** Le site ne demande pas aux navigateurs de toujours utiliser une connexion sécurisée : une première visite en HTTP peut être interceptée. Faites ajouter l'en-tête Strict-Transport-Security (HSTS).

```php
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'strict-transport-security') ? Check::pass() : Check::fail())
            : Check::unknown(),
```

### A10 — Redirection HTTP vers HTTPS

- **Catégorie :** SSL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Les visites en HTTP sont redirigées vers HTTPS.
- **Échec (FR) :** Les visites en HTTP ne sont pas redirigées vers HTTPS : une partie de votre trafic circule sans chiffrement. Faites ajouter une redirection permanente (301) vers HTTPS.

```php
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
```

## B. En-têtes HTTP & réseau

### B1 — Compression Gzip

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le serveur compresse les pages avec Gzip.
- **Échec (FR) :** Le serveur ne compresse pas les pages avec Gzip : elles sont plus lourdes et plus lentes à charger. Faites activer la compression Gzip.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.compression.gzip')),
```

### B2 — Compression Brotli

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le serveur compresse les pages avec Brotli.
- **Échec (FR) :** Le serveur n'offre pas la compression Brotli, plus efficace que Gzip : vos pages pourraient se charger plus vite. Faites activer Brotli si votre hébergeur le permet.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.compression.brotli')),
```

### B3 — Protocole HTTP/2

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le serveur utilise HTTP/2, qui accélère le chargement des pages.
- **Échec (FR) :** Le serveur n'utilise pas HTTP/2, qui charge plusieurs fichiers en parallèle : vos pages s'affichent moins vite. Faites activer HTTP/2 par votre hébergeur.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http2')),
```

### B4 — Compatibilité HTTP/1.1

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le serveur répond aussi en HTTP/1.1, pour les navigateurs et outils plus anciens.
- **Échec (FR) :** Le serveur ne répond pas en HTTP/1.1 : d'anciens navigateurs ou outils pourraient ne pas accéder au site.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http1_1')),
```

### B5 — Protocole HTTP/3

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le serveur annonce HTTP/3, la version la plus récente du protocole web.
- **Échec (FR) :** Le serveur n'annonce pas HTTP/3, la version la plus récente du protocole web. C'est une optimisation facultative.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('probe.http.protocols.http3_advertised')),
```

### B6 — Mise en cache des images, styles et scripts

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 86400
- **Réussite (FR) :** Les navigateurs conservent vos images, styles et scripts {cache_days} jours.
- **Échec (FR) :** Les navigateurs ne conservent vos images, styles et scripts que {cache_days} jours (recommandé : au moins {threshold_days} jours) : les visiteurs réguliers les téléchargent à nouveau inutilement. Faites allonger la durée de cache sur le serveur.

```php
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
```

### B7a — Protection contre l'exécution de fichiers déguisés

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** L'en-tête X-Content-Type-Options empêche l'exécution de fichiers déguisés.
- **Échec (FR) :** L'en-tête X-Content-Type-Options est absent : un navigateur pourrait exécuter comme un script un fichier déguisé. Faites ajouter « X-Content-Type-Options: nosniff ».

```php
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'x-content-type-options') ? Check::pass() : Check::fail())
            : Check::unknown(),
```

### B7b — Protection contre le détournement de clics

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Votre site ne peut pas être affiché à l'intérieur d'un autre site.
- **Échec (FR) :** Votre site peut être affiché à l'intérieur d'un autre site à l'insu du visiteur, pour lui faire cliquer sur autre chose (détournement de clics). Faites ajouter l'en-tête X-Frame-Options ou une politique de sécurité du contenu.

```php
        'check' => static function (Context $c) use ($homepageReadable, $headerPresent) {
            if (!$homepageReadable($c)) {
                return Check::unknown();
            }
            $csp = (string) $c->string('probe.http.security_headers.content-security-policy');

            return $headerPresent($c, 'x-frame-options') || preg_match('/(?:^|;)\s*frame-ancestors\s/i', $csp) === 1
                ? Check::pass()
                : Check::fail();
        },
```

### B7c — Politique de sécurité du contenu (CSP)

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Une politique de sécurité du contenu limite les ressources que vos pages peuvent charger.
- **Échec (FR) :** Aucune politique de sécurité du contenu (Content-Security-Policy) ne limite les scripts que vos pages peuvent charger, ce qui aggrave l'impact d'une injection de code. Elle se met en place progressivement, avec votre développeur.

```php
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'content-security-policy') ? Check::pass() : Check::fail())
            : Check::unknown(),
```

### B7d — Politique de transmission de l'adresse (Referrer-Policy)

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Une Referrer-Policy limite les informations transmises aux sites externes.
- **Échec (FR) :** Aucune Referrer-Policy n'est définie : l'adresse complète de vos pages peut être transmise aux sites vers lesquels vous faites des liens.

```php
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'referrer-policy') ? Check::pass() : Check::fail())
            : Check::unknown(),
```

### B7e — Accès aux fonctions sensibles du navigateur (Permissions-Policy)

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Une Permissions-Policy restreint l'accès aux fonctions sensibles du navigateur.
- **Échec (FR) :** Aucune Permissions-Policy ne restreint l'accès des scripts à la caméra, au micro ou à la géolocalisation.

```php
        'check' => static fn (Context $c) => $homepageReadable($c)
            ? ($headerPresent($c, 'permissions-policy') ? Check::pass() : Check::fail())
            : Check::unknown(),
```

### B9 — Version du serveur non divulguée

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le serveur ne divulgue pas sa version logicielle.
- **Échec (FR) :** Le serveur affiche publiquement sa version logicielle ({observed}), ce qui aide un attaquant à cibler des failles connues. Faites masquer cette information dans la configuration du serveur.

```php
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
```

## C. DNS & disponibilité

### C1 — Accessibilité en IPv6

- **Catégorie :** DNS · **Source :** EXT · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** Le site est accessible en IPv6 ({observed} adresses).
- **Échec (FR) :** Le site n'a pas d'adresse IPv6 ; il reste accessible à tous en IPv4. C'est une amélioration facultative.

```php
        'check' => static function (Context $c) use ($dnsKnown) {
            if (!$dnsKnown($c, 'aaaa')) {
                return Check::unknown();
            }
            $aaaa = $c->list('probe.dns.aaaa');

            return $aaaa !== [] ? Check::pass(count($aaaa)) : Check::fail(0);
        },
```

### C2 — Autorités autorisées à émettre un certificat (CAA)

- **Catégorie :** DNS · **Source :** EXT · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** Un enregistrement DNS CAA limite les autorités pouvant émettre un certificat pour votre domaine.
- **Échec (FR) :** Aucun enregistrement DNS CAA ne précise quelles autorités peuvent émettre un certificat pour votre domaine. C'est une protection supplémentaire facultative.

```php
        'check' => static function (Context $c) use ($dnsKnown) {
            if (!$dnsKnown($c, 'caa')) {
                return Check::unknown();
            }
            $caa = $c->list('probe.dns.caa');

            return $caa !== [] ? Check::pass(count($caa)) : Check::fail(0);
        },
```

### C5 — Nombre de redirections

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 2
- **Réussite (FR) :** Redirections avant d'atteindre votre page d'accueil : {observed}, ce qui est raisonnable.
- **Échec (FR) :** L'accès à votre page d'accueil passe par {observed} redirections (maximum recommandé : {threshold}) : chaque étape ralentit le chargement. Faites simplifier les redirections.

```php
        'check' => static function (Context $c, Rule $rule) {
            if ($c->bool('probe.http.redirects.loop_detected') === true) {
                return Check::fail('loop', ['variant' => 'loop'], Severity::High);
            }
            $hops = $c->number('probe.http.redirects.hops');

            return $hops === null ? Check::unknown() : Check::atMost($hops, (float) $rule->threshold);
        },
```

### C7 — Disponibilité du site

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Votre page d'accueil répond normalement (code HTTP {observed}).
- **Échec (FR) :** Votre page d'accueil répond par une erreur (code HTTP {observed}) : vos visiteurs ne peuvent pas la consulter. Vérifiez le site sans délai.

```php
        'check' => static function (Context $c) use ($homepageReadable) {
            $code = $c->number('probe.http.status_code');
            if ($code === null || !$homepageReadable($c)) {
                return Check::unknown();
            }

            return $code >= 200 && $code < 300 ? Check::pass((int) $code) : Check::fail((int) $code);
        },
```

### C8 — Pages introuvables correctement signalées

- **Catégorie :** HTTP · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Une adresse qui n'existe pas renvoie bien une erreur « 404 ».
- **Échec (FR) :** Une adresse qui n'existe pas affiche une page normale au lieu d'une erreur « 404 » : les moteurs de recherche peuvent indexer des pages vides. Faites corriger la configuration du site.

```php
        'check' => static function (Context $c) use ($homepageReadable) {
            $soft = $c->get('probe.http.soft_404');
            if (!$homepageReadable($c) || !is_array($soft) || ($soft['checked'] ?? false) !== true) {
                return Check::unknown();
            }

            return Check::isFalse((bool) ($soft['is_soft_404'] ?? false));
        },
```

### C9 — Fichier robots.txt

- **Catégorie :** SEO · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le fichier robots.txt, qui guide les moteurs de recherche, est présent.
- **Échec (FR) :** Le fichier robots.txt, qui guide les moteurs de recherche, est absent. Faites-en publier un qui indique l'adresse de votre plan du site.

```php
        'check' => static function (Context $c) use ($homepageReadable) {
            $robots = $c->get('probe.http.robots');
            if (!$homepageReadable($c) || !is_array($robots)) {
                return Check::unknown();
            }

            return ($robots['present'] ?? false) === true ? Check::pass('present') : Check::fail('absent');
        },
```

### C9a — Exploration du site autorisée

- **Catégorie :** SEO · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le fichier robots.txt n'empêche pas l'exploration du site.
- **Échec (FR) :** Le fichier robots.txt interdit aux moteurs de recherche d'explorer tout le site : il n'apparaîtra pas dans Google. Si ce n'est pas voulu (site de test), retirez la règle « Disallow: / ».

```php
        'check' => static function (Context $c) use ($homepageReadable) {
            $robots = $c->get('probe.http.robots');
            if (!$homepageReadable($c)) {
                return Check::unknown();
            }
            if (!is_array($robots) || ($robots['present'] ?? false) !== true) {
                return Check::na();
            }

            return ($robots['disallow_all'] ?? false) === true ? Check::fail('blocked') : Check::pass('not blocked');
        },
```

### C10 — Plan du site

- **Catégorie :** SEO · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** {observed} plans du site déclarés et accessibles.
- **Échec (FR) :** Aucun plan du site accessible n'est déclaré dans robots.txt : les moteurs de recherche découvrent vos pages moins efficacement. Déclarez-y l'adresse de votre plan du site.

```php
        'check' => static function (Context $c) use ($homepageReadable) {
            $robots = $c->get('probe.http.robots');
            if (!$homepageReadable($c) || !is_array($robots) || ($robots['present'] ?? false) !== true) {
                return Check::unknown();
            }
            $sitemaps = $robots['sitemaps'] ?? [];
            if ($sitemaps === [] || ($robots['sitemap_reachable'] ?? null) === false) {
                return Check::fail(count($sitemaps), [], Severity::Info);
            }

            return Check::pass(count($sitemaps));
        },
```

## D. Délivrabilité e-mail (DNS)

### D1 — Autorisation d'envoi des courriels (SPF)

- **Catégorie :** EMAIL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le courriel envoyé par votre site est autorisé par l'enregistrement SPF de votre domaine.
- **Échec (FR) :** Le courriel envoyé par votre site n'est pas autorisé par l'enregistrement SPF de votre domaine : il risque de finir en indésirables ou d'être refusé. Faites ajouter le serveur d'envoi à l'enregistrement SPF, ou passez par un service d'envoi authentifié.

```php
        'check' => static fn (Context $c) => $mailAuth($c, 'spf'),
```

### D2 — Signature des courriels (DKIM)

- **Catégorie :** EMAIL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le courriel envoyé par votre site porte une signature valide au nom de votre domaine.
- **Échec (FR) :** La signature du courriel envoyé par votre site est invalide : les fournisseurs peuvent le classer en indésirables. Faites corriger la configuration DKIM du service d'envoi.

```php
        'check' => static function (Context $c) use ($mailAuth) {
            $result = $mailAuth($c, 'dkim');
            if ($result->status !== Status::Pass) {
                return $result;
            }

            return match ($c->bool('probe.mail.dkim_aligned')) {
                true    => $result,
                false   => Check::fail('unaligned', ['variant' => 'unaligned', 'signer' => implode(', ', array_filter(
                    array_map('strval', $c->list('probe.mail.dkim_domains')),
                    static fn (string $d): bool => $d !== ''
                ))]),
                default => Check::unknown(),
            };
        },
```

### D3 — Protection contre l'usurpation du domaine (DMARC)

- **Catégorie :** EMAIL · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Le courriel envoyé par votre site réussit la vérification DMARC de votre domaine.
- **Échec (FR) :** Le courriel envoyé par votre site échoue à la vérification DMARC : les fournisseurs peuvent le refuser ou le classer en indésirables. Faites corriger l'authentification (SPF et DKIM) du serveur d'envoi.

```php
        'check' => static fn (Context $c) => $mailAuth($c, 'dmarc'),
```

### D4 — Réception des courriels (MX)

- **Catégorie :** EMAIL · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Votre domaine peut recevoir des courriels ({observed} serveurs de réception).
- **Échec (FR) :** Aucun enregistrement MX : votre domaine ne peut recevoir aucun courriel. Si des adresses courriel l'utilisent, faites corriger la zone DNS sans délai.

```php
        'check' => static function (Context $c) use ($dnsKnown) {
            if (!$dnsKnown($c, 'mx')) {
                return Check::unknown();
            }
            $mx = $c->list('probe.dns.mx');

            return $mx !== [] ? Check::pass(count($mx)) : Check::fail(0);
        },
```

## W. Domaine (WHOIS/RDAP)

### W1 — Échéance du nom de domaine

- **Catégorie :** DOMAIN · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** 30
- **Réussite (FR) :** Votre nom de domaine est valide encore {observed} jours.
- **Échec (FR) :** Votre nom de domaine expire dans {observed} jours : ensuite, votre site et vos courriels cessent de fonctionner. Renouvelez-le sans tarder.

```php
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
```

### W2 — Vérifier que la carte de crédit au dossier du registraire du domaine n'est pas expirée

- **Catégorie :** DOMAIN · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** Un renouvellement manqué peut interrompre votre site et vos courriels.
- **Échec (FR) :** Le renouvellement du domaine mérite votre attention : la carte de crédit au dossier du registraire pourrait être expirée.

```php
        'check' => static fn () => Check::pass(),
```

### W3 — Vérifier que les informations du propriétaire du nom de domaine sont à jour

- **Catégorie :** DOMAIN · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** Des informations à jour évitent des complications en cas de problème avec le domaine.
- **Échec (FR) :** La propriété du domaine mérite votre attention : les informations pourraient être désuètes.

```php
        'check' => static fn () => Check::pass(),
```

## PS. Performance (Lighthouse/PageSpeed)

### PS1 — Performance sur ordinateur

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** La note de performance Google (Lighthouse) de votre page d'accueil sur ordinateur est de {observed}/100.
- **Échec (FR) :** La note de performance Google (Lighthouse) de votre page d'accueil sur ordinateur est de {observed}/100 (objectif : {threshold}) : un site lent perd des visiteurs et du classement dans Google. Faites optimiser les images, le cache et les extensions.

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.desktop.scores.performance'), (float) $rule->threshold),
```

### PS1a — Performance sur mobile

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** La note de performance Google (Lighthouse) de votre page d'accueil sur mobile est de {observed}/100.
- **Échec (FR) :** La note de performance Google (Lighthouse) de votre page d'accueil sur mobile est de {observed}/100 (objectif : {threshold}) : la majorité des visiteurs naviguent sur mobile et quittent un site lent. Faites optimiser les images, le cache et les extensions.

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('probe.pagespeed.mobile.scores.performance'), (float) $rule->threshold),
```

### PS2 — Accessibilité

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** La note d'accessibilité Google (Lighthouse) de votre page d'accueil est de {observed}/100.
- **Échec (FR) :** La note d'accessibilité Google (Lighthouse) de votre page d'accueil est de {observed}/100 (objectif : {threshold}) : certaines personnes, notamment en situation de handicap, ont du mal à utiliser le site. Faites corriger les contrastes, textes alternatifs et libellés signalés.

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($lowestScore($c, 'accessibility'), (float) $rule->threshold),
```

### PS3 — Référencement technique

- **Catégorie :** SEO · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 90
- **Réussite (FR) :** La note de référencement technique Google (Lighthouse) de votre page d'accueil est de {observed}/100.
- **Échec (FR) :** La note de référencement technique Google (Lighthouse) de votre page d'accueil est de {observed}/100 (objectif : {threshold}) : des éléments de base (titre, description, liens) nuisent à votre visibilité. Faites corriger les points signalés.

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($lowestScore($c, 'seo'), (float) $rule->threshold),
```

### PS4 — Vitesse d'affichage sur mobile

- **Catégorie :** PERFORMANCE · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 2500
- **Réussite (FR) :** Sur mobile, le contenu principal de votre page d'accueil s'affiche en {lcp_s} s (objectif : moins de {threshold_s} s).
- **Échec (FR) :** Sur mobile, le contenu principal de votre page d'accueil met {lcp_s} s à s'afficher (objectif : moins de {threshold_s} s) : les visiteurs impatients quittent le site. Faites optimiser les images et le chargement de la page.

```php
        'check' => static function (Context $c, Rule $rule) {
            $lcp = $c->number('probe.pagespeed.mobile.lab.lcp.value');
            if ($lcp === null) {
                return Check::unknown();
            }
            $data = ['lcp_s' => round($lcp / 1000, 1), 'threshold_s' => round((float) $rule->threshold / 1000, 1)];

            return $lcp <= (float) $rule->threshold ? Check::pass($lcp, $data) : Check::fail($lcp, $data);
        },
```

## F. Versions, mises à jour & fin de vie

### F1 — Version majeure de WordPress

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** WordPress {observed} est sur une version majeure récente.
- **Échec (FR) :** WordPress {observed} a {major_versions_behind} versions majeures de retard : les mises à jour semblent arrêtées depuis longtemps. Planifiez une mise à jour majeure.

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

### F2 — Correctifs de sécurité de WordPress

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** WordPress {observed} a tous les correctifs de sécurité de sa version.
- **Échec (FR) :** La mise à jour de sécurité {available} de WordPress est disponible mais n'est pas installée. Appliquez-la sans tarder.

```php
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
```

### F3 — Version de PHP supportée

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** PHP {observed} reçoit des correctifs de sécurité jusqu'au {eol_date}.
- **Échec (FR) :** PHP {observed}, le langage qui fait fonctionner votre site, ne reçoit plus de correctifs de sécurité depuis le {eol_date}. Planifiez la mise à niveau avec votre hébergeur.

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
            $data = $date === null ? ['variant' => 'no_date'] : ['eol_date' => $date];

            return $isEol ? Check::fail($version, $data) : Check::pass($version, $data);
        },
```

### F4 — Mises à jour des extensions

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Toutes les extensions sont à jour.
- **Échec (FR) :** {observed} extensions ont une mise à jour en attente : {names}. Les mises à jour corrigent souvent des failles de sécurité ; appliquez-les après une sauvegarde.

```php
        'check' => static function (Context $c) use ($nameList) {
            $plugins = $c->list('payload.plugins');
            if ($plugins === []) {
                return Check::unknown();
            }
            $outdated = array_values(array_filter($plugins, static fn ($p): bool => is_array($p) && !empty($p['new_version'])));
            if ($outdated === []) {
                return Check::pass(0);
            }

            return Check::fail(count($outdated), $nameList(array_map(static fn (array $p): string => (string) ($p['name'] ?? '?'), $outdated)));
        },
```

### F5 — Mises à jour des thèmes

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Tous les thèmes sont à jour.
- **Échec (FR) :** {observed} thèmes ont une mise à jour en attente : {names}. Appliquez-les après une sauvegarde.

```php
        'check' => static function (Context $c) use ($nameList) {
            $themes = $c->list('payload.themes');
            if ($themes === []) {
                return Check::unknown();
            }
            $outdated = array_values(array_filter($themes, static fn ($t): bool => is_array($t) && !empty($t['new_version'])));
            if ($outdated === []) {
                return Check::pass(0);
            }

            return Check::fail(count($outdated), $nameList(array_map(static fn (array $t): string => (string) ($t['name'] ?? '?'), $outdated)));
        },
```

### F7 — Compatibilité des extensions

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Toutes les extensions sont compatibles avec les versions de PHP et de WordPress du site.
- **Échec (FR) :** {observed} extensions exigent une version de PHP ou de WordPress plus récente que celle du site : {names}. Elles risquent de mal fonctionner ; mettez l'environnement à jour ou remplacez-les.

```php
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
```

### F8 — Extensions et thèmes maintenus

- **Catégorie :** UPDATES · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** 365
- **Réussite (FR) :** Toutes les extensions et tous les thèmes vérifiés ont été mis à jour par leur auteur au cours des {threshold} derniers jours.
- **Échec (FR) :** {observed} extensions ou thèmes n'ont reçu aucune mise à jour de leur auteur depuis plus de {threshold} jours : {names}. Un logiciel abandonné ne reçoit plus de correctifs de sécurité ; envisagez de le remplacer.

```php
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
```

### F10 — Vulnérabilités connues

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Aucune vulnérabilité connue ne touche WordPress, vos extensions ni vos thèmes.
- **Échec (FR) :** {observed} vulnérabilités connues touchent {components} composants de votre site : {names}. Ces failles sont publiques et exploitées par des robots ; appliquez les mises à jour correctives sans tarder.

```php
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
```

### F12 — Mises à jour automatiques

- **Catégorie :** UPDATES · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** Les correctifs de sécurité de WordPress s'installent automatiquement. Extensions mises à jour automatiquement : {plugins_auto} sur {plugins_total}.
- **Échec (FR) :** Les mises à jour automatiques de WordPress sont désactivées, y compris les correctifs de sécurité : ils doivent être appliqués manuellement. Extensions mises à jour automatiquement : {plugins_auto} sur {plugins_total}.

```php
        'check' => static function (Context $c) {
            $constants = $c->get('payload.constants');
            if (!is_array($constants)) {
                return Check::unknown();
            }
            $data = [
                'plugins_auto'  => count($c->list('payload.auto_update_plugins')),
                'plugins_total' => count($c->list('payload.plugins')),
            ];
            if ($c->constant('DISALLOW_FILE_MODS') === true) {
                return Check::fail('file_mods', $data + ['variant' => 'file_mods']);
            }
            if ($c->constant('AUTOMATIC_UPDATER_DISABLED') === true) {
                return Check::fail('all_disabled', $data + ['variant' => 'all_disabled']);
            }
            $core = $constants['WP_AUTO_UPDATE_CORE'] ?? 'N/A';
            if ($core === false || $core === 'false') {
                return Check::fail('core_disabled', $data);
            }

            return $core === true || $core === 'true'
                ? Check::pass('all', $data + ['variant' => 'all'])
                : Check::pass('minor', $data);
        },
```

### F13 — Extensions et thèmes inutilisés

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Aucune extension ni aucun thème inutilisé n'est installé.
- **Échec (FR) :** {observed} extensions ou thèmes sont installés mais désactivés : {names}. Même inactifs, leurs fichiers restent sur le serveur et peuvent contenir des failles ; supprimez ceux dont vous n'avez pas besoin.

```php
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
```

## G. PHP & serveur

### G1 — Mémoire disponible pour PHP

- **Catégorie :** PHP · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** PHP dispose de {memory_mb} Mo de mémoire, ce qui est suffisant.
- **Échec (FR) :** PHP dispose de {memory_mb} Mo de mémoire (recommandé : au moins 256 Mo) : les pages lourdes et l'administration peuvent tomber en erreur. Faites augmenter la limite par votre hébergeur.

```php
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
```

### G3 — Taille maximale des envois de fichiers

- **Catégorie :** PHP · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 50
- **Réussite (FR) :** Les envois de fichiers peuvent atteindre {effective_mb} Mo.
- **Échec (FR) :** Un envoi de fichier est limité à {effective_mb} Mo (recommandé : au moins {threshold} Mo) : l'ajout de vidéos ou de gros documents peut échouer. Faites augmenter la limite par votre hébergeur.

```php
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
```

### G4 — Nombre de champs par formulaire

- **Catégorie :** PHP · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 3000
- **Réussite (FR) :** PHP accepte {observed} champs par formulaire, ce qui est suffisant.
- **Échec (FR) :** PHP accepte au plus {observed} champs par formulaire (recommandé : au moins {threshold}) : l'enregistrement de menus ou de pages complexes peut perdre des données sans avertissement. Faites augmenter le réglage max_input_vars.

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atLeast($c->number('payload.php.max_input_vars'), (float) $rule->threshold),
```

### G5 — Modules PHP nécessaires

- **Catégorie :** PHP · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Tous les modules PHP dont WordPress a besoin sont présents.
- **Échec (FR) :** Des modules PHP dont WordPress a besoin sont absents ({observed}) : certaines fonctions (images, mises à jour, sécurité) peuvent échouer. Faites-les installer par votre hébergeur.

```php
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
```

### G6 — Accélérateur PHP (OPcache)

- **Catégorie :** PHP · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** L'accélérateur OPcache est actif.
- **Échec (FR) :** L'accélérateur OPcache n'est pas actif : PHP recompile le code du site à chaque visite, ce qui le ralentit. Faites-le activer par votre hébergeur.

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

### H1 — Version de la base de données

- **Catégorie :** DATABASE · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Votre base de données ({observed}) reçoit des correctifs de sécurité jusqu'au {eol_date}.
- **Échec (FR) :** Votre base de données ({observed}) ne reçoit plus de correctifs de sécurité depuis le {eol_date}. Planifiez la mise à niveau avec votre hébergeur.

```php
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
```

### H4 — Espace récupérable dans la base de données

- **Catégorie :** DATABASE · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 10485760
- **Réussite (FR) :** L'espace récupérable en optimisant les tables ({overhead_mb} Mo) reste faible par rapport à la taille de la base.
- **Échec (FR) :** Environ {overhead_mb} Mo ({percent} % de la base) pourraient être récupérés en optimisant les tables. C'est un entretien facultatif.

```php
        'check' => static function (Context $c, Rule $rule) {
            $tables = $c->list('payload.database.tables');
            if ($tables === []) {
                return Check::unknown();
            }
            $overhead = array_sum(array_map(static fn ($t): float => is_array($t) ? (float) ($t['overhead_bytes'] ?? 0) : 0.0, $tables));
            $total    = $c->number('payload.database.total_bytes');
            $data     = ['overhead_mb' => round($overhead / 1048576, 1)];
            if ($total !== null && $total > 0) {
                $data['percent'] = (int) round($overhead / $total * 100);
            } else {
                $data['variant'] = 'no_total';
            }

            $significant = $overhead >= (float) $rule->threshold && ($data['percent'] ?? 100) >= 20;

            return $significant ? Check::fail($overhead, $data) : Check::pass($overhead, $data);
        },
```

### H5 — Données temporaires expirées

- **Catégorie :** DATABASE · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 250
- **Réussite (FR) :** La base de données contient {observed} données temporaires expirées, ce qui est normal.
- **Échec (FR) :** {observed} données temporaires expirées (« transients ») encombrent la base de données (seuil : {threshold}). Un nettoyage régulier les supprime.

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.database.transients.expired'), (float) $rule->threshold),
```

## I. Autoload / cache objet

### I1 — Poids des réglages chargés à chaque page

- **Catégorie :** CACHE · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Les réglages que WordPress charge à chaque page pèsent {size_kb} Ko, dans la norme.
- **Échec (FR) :** Les réglages que WordPress charge à chaque page (options « autoload ») pèsent {size_kb} Ko (idéalement moins de 500 Ko) : chaque visite en est ralentie. Faites nettoyer les réglages laissés par d'anciennes extensions.

```php
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
```

### I4 — Cache objet persistant

- **Catégorie :** CACHE · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** Un cache objet persistant (Redis ou Memcached) accélère le site.
- **Échec (FR) :** Aucun cache objet persistant (Redis ou Memcached) n'est configuré. C'est une optimisation utile pour un site très fréquenté ou transactionnel, facultative pour un site vitrine.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.object_cache.external')),
```

## J. Cron

### J2 — Tâches planifiées exécutées à temps

- **Catégorie :** CRON · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Les tâches planifiées de WordPress s'exécutent à temps.
- **Échec (FR) :** {observed} tâches planifiées de WordPress (publications programmées, sauvegardes, nettoyages) sont en retard : le système de tâches ne s'exécute probablement plus. Faites configurer une tâche cron sur le serveur.

```php
        'check' => static function (Context $c) {
            $overdue = $c->number('payload.cron.overdue_events');
            if ($overdue === null) {
                return Check::unknown();
            }
            if ($overdue <= 0) {
                return Check::pass(0);
            }
            // overdue_minutes is often absent: grade on the count alone then.
            $minutes = $c->number('payload.cron.overdue_minutes');
            $mild    = $overdue <= 10 && ($minutes === null || $minutes <= 15);

            return $mild
                ? Check::fail((int) $overdue, ['variant' => 'mild'], Severity::Medium)
                : Check::fail((int) $overdue);
        },
```

### J3 — Nombre de tâches planifiées

- **Catégorie :** CRON · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 100
- **Réussite (FR) :** {observed} tâches planifiées sont enregistrées, ce qui est raisonnable.
- **Échec (FR) :** {observed} tâches planifiées sont enregistrées (seuil : {threshold}), souvent le signe d'extensions qui en accumulent. Faites faire le ménage.

```php
        'check' => static fn (Context $c, Rule $rule) => Check::atMost($c->number('payload.cron.scheduled_events'), (float) $rule->threshold),
```

## K. Configuration & durcissement

### K1 — Mode débogage

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le mode débogage de WordPress est désactivé.
- **Échec (FR) :** Le mode débogage de WordPress (WP_DEBUG) est actif sur le site en ligne, sans journal privé confirmé : des informations techniques peuvent fuiter. Désactivez-le en production.

```php
        'check' => static function (Context $c) use ($debugLogExposed) {
            $debug = $c->constant('WP_DEBUG');
            if ($debug === null) {
                return Check::unknown();
            }
            if ($debug === false) {
                return Check::pass(false);
            }
            // Debug on is acceptable only when it logs to a file confirmed private.
            if ($c->get('payload.constants.WP_DEBUG_LOG') !== true) {
                return Check::fail(true);
            }

            return match ($debugLogExposed($c)) {
                false   => Check::pass(true, ['variant' => 'private_log']),
                true    => Check::fail(true),
                default => Check::unknown(),
            };
        },
```

### K2 — Erreurs cachées aux visiteurs

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Élevée · **Seuil configurable :** —
- **Réussite (FR) :** Les erreurs PHP ne s'affichent pas à vos visiteurs.
- **Échec (FR) :** Les erreurs PHP s'affichent directement sur vos pages (WP_DEBUG_DISPLAY) : vos visiteurs voient des messages techniques qui renseignent aussi les attaquants. Désactivez cet affichage.

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

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** Le journal de débogage n'est pas accessible publiquement.
- **Échec (FR) :** Le journal de débogage est enregistré à son emplacement par défaut (wp-content/debug.log), où il est téléchargeable publiquement.

```php
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
```

### K4 — Éditeur de code de l'administration

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** L'éditeur de code intégré à l'administration est désactivé.
- **Échec (FR) :** L'éditeur de code intégré à l'administration est actif : un seul compte administrateur compromis suffit pour injecter du code malveillant. Désactivez-le (DISALLOW_FILE_EDIT).

```php
        'check' => static fn (Context $c) => $c->constant('DISALLOW_FILE_MODS') === true
            ? Check::pass(true, ['variant' => 'file_mods'])
            : Check::isTrue($c->constant('DISALLOW_FILE_EDIT')),
```

### K6 — Administration protégée par HTTPS

- **Catégorie :** SECURITY · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** L'accès à l'administration est forcé en HTTPS.
- **Échec (FR) :** Rien n'oblige l'accès à l'administration en HTTPS : vos identifiants pourraient circuler sans chiffrement. Activez FORCE_SSL_ADMIN ou une redirection HTTPS pour tout le site.

```php
        'check' => static function (Context $c) {
            $forced = $c->constant('FORCE_SSL_ADMIN');
            if ($forced === true) {
                return Check::pass(true);
            }
            $siteHttps = $c->bool('probe.http.redirects.forces_https') === true
                && str_starts_with((string) $c->string('payload.home_url'), 'https://');
            if ($siteHttps) {
                return Check::pass(false, ['variant' => 'site_https']);
            }

            return $forced === null ? Check::unknown() : Check::fail(false);
        },
```

## L. Système de fichiers

### L1 — Espace disque disponible

- **Catégorie :** HOSTING · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** —
- **Réussite (FR) :** Il reste {observed} % d'espace disque ({free_gb} Go).
- **Échec (FR) :** Il ne reste que {observed} % d'espace disque ({free_gb} Go) : les sauvegardes, mises à jour et téléversements peuvent échouer. Libérez de l'espace ou augmentez votre forfait.

```php
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
```

### L4 — Enregistrement des médias

- **Catégorie :** HOSTING · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** WordPress peut enregistrer vos médias.
- **Échec (FR) :** WordPress ne peut pas écrire dans le dossier des médias : l'ajout d'images et certaines mises à jour échouent. Faites corriger les permissions du dossier.

```php
        'check' => static fn (Context $c) => Check::isTrue($c->bool('payload.filesystem.uploads_writable')),
```

## M. Utilisateurs & accès

### M1 — Nombre d'administrateurs

- **Catégorie :** USERS · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** 3
- **Réussite (FR) :** Le site compte {observed} comptes administrateurs.
- **Échec (FR) :** Le site compte {observed} comptes administrateurs (recommandé : {threshold} au plus) : chaque compte aux pleins pouvoirs est une porte d'entrée. Retirez les droits inutiles.

```php
        'check' => static function (Context $c, Rule $rule) {
            $count = $c->count('payload.administrators');

            return $count === null ? Check::unknown() : Check::atMost((float) $count, (float) $rule->threshold);
        },
```

### M2 — Identifiant « admin » évité

- **Catégorie :** USERS · **Source :** DATA · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Aucun compte administrateur n'utilise l'identifiant « admin ».
- **Échec (FR) :** Un compte administrateur utilise l'identifiant « admin », le premier que testent les robots d'attaque. Remplacez-le par un identifiant unique.

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

## N. N

### N2 — Corbeille des articles

- **Catégorie :** CONTENT · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** 20
- **Réussite (FR) :** La corbeille des articles contient {observed} éléments.
- **Échec (FR) :** {observed} articles attendent dans la corbeille (seuil : {threshold}) : la vider allège la base de données.

```php
        'check' => static function (Context $c, Rule $rule) {
            $posts = $c->get('payload.posts_count');
            if (!is_array($posts)) {
                return Check::unknown();
            }

            return Check::atMost((float) ($posts['trash'] ?? 0), (float) $rule->threshold);
        },
```

### N4 — Brouillons en attente

- **Catégorie :** CONTENT · **Source :** DATA · **Sévérité de base :** Info · **Seuil configurable :** 30
- **Réussite (FR) :** Seulement {observed} % des articles sont des brouillons.
- **Échec (FR) :** {observed} % des articles sont des brouillons (seuil : {threshold} %) : publiez ou supprimez ceux qui sont abandonnés.

```php
        'check' => static function (Context $c, Rule $rule) {
            $posts = $c->get('payload.posts_count');
            if (!is_array($posts)) {
                return Check::unknown();
            }
            $published = (int) ($posts['publish'] ?? 0);
            $draft     = (int) ($posts['draft'] ?? 0);
            if ($published + $draft === 0) {
                return Check::unknown();
            }

            return Check::atMost(round($draft / ($published + $draft) * 100, 1), (float) $rule->threshold);
        },
```

## BV. BlogVault

### BV1 — Site exempt de piratage

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Critique · **Seuil configurable :** —
- **Réussite (FR) :** Aucun signe de piratage n'a été détecté sur votre site.
- **Échec (FR) :** Du code malveillant a été détecté sur votre site ({detections} éléments non résolus). Un nettoyage est nécessaire sans délai.

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

## X. Exposition (sondes passives)

### X1 — Ancienne interface xmlrpc.php bloquée

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** L'ancienne interface xmlrpc.php est bloquée ou désactivée.
- **Échec (FR) :** L'ancienne interface xmlrpc.php répond : elle permet aux robots de tester des milliers de mots de passe d'un coup. Bloquez-la si aucune application ne l'utilise (Jetpack, applications mobiles).

```php
        'check' => static fn (Context $c) => Check::isFalse($exposureFlag($c, 'xmlrpc_enabled')),
```

### X2 — Identifiants protégés (API de WordPress)

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** L'API de WordPress ne divulgue pas les identifiants de vos utilisateurs.
- **Échec (FR) :** L'API de WordPress publie la liste des identifiants de connexion de vos utilisateurs : un attaquant n'a plus qu'à deviner les mots de passe. Restreignez l'accès à cette liste.

```php
        'check' => static fn (Context $c) => Check::isFalse($exposureFlag($c, 'rest_user_enumeration')),
```

### X3 — Identifiants protégés (archives d'auteur)

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Les archives d'auteur ne révèlent pas les identifiants de connexion.
- **Échec (FR) :** L'adresse « ?author=1 » révèle l'identifiant de connexion d'un administrateur. Bloquez cette redirection.

```php
        'check' => static fn (Context $c) => Check::isFalse($exposureFlag($c, 'author_enumeration')),
```

### X4 — Fichiers sensibles protégés

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Critique · **Seuil configurable :** —
- **Réussite (FR) :** Aucun fichier sensible courant (sauvegarde, configuration) n'est accessible publiquement.
- **Échec (FR) :** Des fichiers sensibles sont téléchargeables publiquement ({observed}) : ils peuvent révéler les accès à la base de données. Supprimez-les ou bloquez-les sans délai.

```php
        'check' => static function (Context $c) use ($sensitiveFiles) {
            $found = $sensitiveFiles($c);
            if ($found === null) {
                return Check::unknown();
            }

            return $found === [] ? Check::pass('none') : Check::fail(implode(', ', array_map('strval', $found)));
        },
```

### X5 — Liste des médias non consultable

- **Catégorie :** SECURITY · **Source :** EXT · **Sévérité de base :** Moyenne · **Seuil configurable :** —
- **Réussite (FR) :** Le contenu du dossier des médias ne peut pas être parcouru.
- **Échec (FR) :** N'importe qui peut parcourir la liste complète des fichiers de votre dossier de médias (wp-content/uploads/), y compris des documents non publiés. Faites désactiver l'affichage des répertoires.

```php
        'check' => static fn (Context $c) => Check::isFalse($exposureFlag($c, 'directory_listing')),
```

