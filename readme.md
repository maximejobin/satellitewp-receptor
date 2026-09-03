# SatelliteWP Xtractor

Contrepartie serveur du plugin **SatelliteWP Maintenance** : reçoit les payloads
signés du plugin (extractions, événements, intégrité), les stocke en JSON dans
`data/`, exécute un pipeline de probes de validation (DNS, WHOIS/RDAP, TLS, HTTP)
et produit des fichiers JSON exploitables pour l'affichage brut et les rapports
(bilan de santé, etc.).

## Principes

- **PHP 8.4+, sans framework.** Composer, symfony/console (CLI), Guzzle (HTTP).
- **Les fichiers JSON sont la source de vérité.** SQLite (`data/index.sqlite`)
  n'est qu'un index reconstructible (`index:rebuild`).
- **Une probe = une classe** derrière `ProbeInterface`, exécutable seule au CLI
  ou dans le pipeline. Une probe qui plante n'arrête jamais le run.
- **La requête HTTP ne fait jamais de probe.** Le receptor stocke et répond ;
  un analyste lance l'analyse depuis l'UI, un cron l'exécute hors requête web.

## Installation

```bash
composer install
cp config/config.local.php.dist config/config.local.php   # puis ajuster
```

**Deux applications, deux vhosts, qui ne se parlent pas.** Elles partagent le
code et `data/`, rien d'autre :

| Répertoire | Exposition | Rôle |
| --- | --- | --- |
| `public/receptor` | **public** | reçoit les push signés du plugin — ne charge jamais le Router, n'ouvre aucune session, ne sert aucun HTML |
| `public/admin` | **protégé** (Google OAuth) | l'interface analyste — n'accepte jamais un push |

Le receptor est la seule surface exposée à Internet, et tout ce qui n'est pas un
POST signé y reçoit un 404 sec. L'admin, lui, ne peut pas servir de porte
d'entrée aux données : le code du receptor n'y est pas.

```nginx
# Réception des extractions — public
server {
    server_name receptor.satellitewp.com;
    root /var/www/xtractor/public/receptor;

    location / { try_files $uri /index.php$is_args$args; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
}

# Interface analyste — derrière Google OAuth
server {
    server_name xtractor.satellitewp.com;
    root /var/www/xtractor/public/admin;

    location / { try_files $uri /index.php$is_args$args; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        # Indispensable derrière un TLS terminé en amont : sans ça l'URI de
        # redirection OAuth sort en http:// et Google la refuse.
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
    }
}
```

Crontab (worker de la file d'analyse + relance des runs plantés) :

```cron
* * * * * php /var/www/xtractor/bin/xtractor ingest:process --requeue-stale=30 >> /var/www/xtractor/data/xtractor.log 2>&1
```

**Une extraction reçue n'est jamais analysée toute seule.** Elle est stockée en
`pending` et attend qu'un analyste presse « Lancer l'analyse » dans l'interface,
ce qui la passe en `queued` — le seul statut que ce worker ramasse. Un site qui
pousse de lui-même ne coûte donc qu'un fichier sur disque : aucune sonde, aucun
quota PageSpeed ou BlogVault. Le cron existe uniquement pour que les ~20 s
d'analyse tournent hors de la requête web, sans risque de timeout.

Cycle de vie : `pending` → *(clic analyste)* → `queued` → `running` → `done`.
Un run planté depuis plus de N minutes retourne en `queued` (pas en `pending`,
sinon plus personne ne le reprendrait).

### PageSpeed Insights

La probe `pagespeed` interroge l'API PSI v5 (pas de Chrome headless à installer).
**Une clé API est nécessaire en pratique** — sans elle, le quota anonyme renvoie
un HTTP 429 immédiatement. Créez-en une (gratuite, ~25 000 requêtes/jour) dans la
console Google Cloud en activant « PageSpeed Insights API », puis :

```php
// config/config.local.php
'pagespeed' => ['api_key' => '…'],
```

Par défaut : **un appel mobile + un appel desktop**, les catégories
`performance`, `accessibility`, `best-practices`, `seo`, en **français**
(`locale`, BCP-47 — localise les titres et les valeurs affichées). Chaque appel
prend 10-30 s et PSI renvoie régulièrement des 500 transitoires : la probe
réessaie 3 fois avec backoff, et un run partiel est signalé `warn` (jamais `ok`).

## Enregistrer un site

```bash
./bin/xtractor keys:add <site_id> --origin="https://client-x.example.com"
```

La clé affichée (une seule fois) va dans le `wp-config.php` du site :

```php
define( 'SWP_API_KEY', '…' );
define( 'SWP_EXTRACTION_ENDPOINT_URL', 'https://receptor.satellitewp.com' );
```

Le plugin ne connaît que l'hôte du receptor. L'interface analyste vit ailleurs
et n'est jamais joignable depuis un site client.

## CLI

| Commande | Rôle |
| --- | --- |
| `ingest:process [--limit=N] [--requeue-stale=MIN]` | Worker cron : traite les extractions `queued` (celles lancées depuis l'UI), verrou flock |
| `pipeline:run <site_id> [extraction] [--probe=a,b]` | Pipeline complet ou partiel (défaut : dernière extraction) |
| `probe:run <probe> <site_id> [extraction]` | Une probe seule, imprime l'enveloppe JSON |
| `probe:list` | Probes enregistrées |
| `rules:evaluate <site_id> [extraction] [--all] [--json]` | Ré-évalue le catalogue (sans réseau) |
| `rules:list [--category=…]` | Catalogue de règles |
| `sites:list [--search=…]` / `extractions:list <site_id>` | Listes |
| `keys:add/list/revoke` | Gestion des clés API par site |
| `index:rebuild` | Régénère SQLite depuis `data/` |
| `reference:refresh [--product=php,wordpress]` | Rafraîchit les tables EOL depuis endoflife.date |
| `wordfence:refresh` | Rafraîchit l'index Wordfence Intelligence — **quotidien**, jamais plus (API à débit strict) |

Crontab pour les tables de référence WordPress/PHP/MySQL/MariaDB affichées sous
« Données » (`/data/wp-versions`, `/data/php-versions`, `/data/databases`) :
rafraîchi **toutes les heures**, pour que la sortie d'une nouvelle version
n'attende pas une semaine :

```cron
0 * * * * php /var/www/xtractor/bin/xtractor reference:refresh --product=wordpress,php,mysql,mariadb >> /var/www/xtractor/logs/xtractor-cron.log 2>&1
```

Wordfence est différent — la base change en continu, mais son API est sous un
débit strict, donc quotidien (détail et cron complet dans la section dédiée
plus bas).

## Layout data/

```
data/sites/<site_id>/
├── site.json                      # infos dénormalisées du site
├── extractions/<AAAAMMJJTHHMMSSZ>/
│   ├── payload.json               # payload brut, tel que reçu
│   ├── meta.json                  # réception (ip, signature, taille)
│   ├── probes/{dns,rdap,tls,http,pagespeed,blogvault,wordfence}.json
│   └── findings.json              # constats du moteur de règles
├── extractions/latest             # symlink
├── events/AAAA-MM.jsonl           # événements, append-only
└── integrity/<horodatage>.json
```

Enveloppe commune de chaque probe :
`{probe, probe_version, site_id, target, ran_at, duration_ms, status: ok|warn|error, data, errors}`.

## Moteur de règles

Les probes collectent, le moteur de règles **juge**. Après chaque pipeline, le
catalogue est évalué sur `payload.json` + `probes/*.json` (aucun appel réseau) et
produit un `findings.json` : la matière première du bilan de santé.

Le catalogue vit dans [`config/rules.php`](config/rules.php) et suit
`.github/validations-techniques.txt` du repo plugin — mêmes identifiants (`A1`,
`B7a`, `I1`…), mêmes catégories, mêmes sévérités **(C)ritique / (É)levée /
(M)oyenne / (I)nfo**. Quatre préfixes sont des ajouts hors catalogue,
volontairement distincts pour ne jamais entrer en collision : `W*` (domaine
WHOIS/RDAP), `PS*` (scores Lighthouse), `BV*` (BlogVault) et `WF*` (Wordfence
Intelligence). `BV2` et `WF1` sont deux détecteurs de vulnérabilités
**indépendants** — un site peut échouer l'un, l'autre, les deux, ou aucun ; ils
ne sont jamais fusionnés au niveau des règles, seulement à l'affichage (voir
plus bas).

Chaque constat porte : id, catégorie, source, sévérité, statut, valeur observée,
seuil, message d'action et badge rapport (rouge/jaune/bleu).

Quatre statuts, et la distinction compte pour un rapport client :

| Statut | Sens |
| --- | --- |
| `pass` | Conforme |
| `fail` | À corriger — seul cas qui porte un message d'action |
| `na` | Non applicable (ex. aucun asset CSS/JS à compresser) |
| `unknown` | Donnée absente ou sonde en échec — **jamais** présenté comme un problème |

Seuils ajustables **sans toucher au catalogue**, par identifiant :

```php
// config/config.local.php
'rules' => ['thresholds' => ['I1' => 1048576, 'M1' => 3, 'A2' => 45]],
```

```bash
./bin/xtractor rules:list                          # catalogue
./bin/xtractor rules:evaluate <site_id> [--all]    # ré-évalue (sans réseau)
```

Ajouter une règle = un tableau dans `config/rules.php` avec une closure `check`
recevant un `Context` (`$c->number('payload.autoload.total_bytes')`,
`$c->bool('probe.http.redirects.forces_https')`). Une règle qui lève une
exception devient `unknown` et n'interrompt jamais l'évaluation.

## BlogVault (API v6)

`BlogVaultClient` est un client **générique et paramétrable** — pas une méthode
par endpoint. Les paramètres font le travail :

```php
$bv    = $app->blogVault();
$vulns = $bv->get('sites/vulnerabilities', ['site_url' => $url]);
$bv->post('sites/scan', ['site_url' => $url]);
$bv->request('GET', 'n/importe/quel/endpoint', ['query' => [...]]);
```

Tout ce qui est spécifique à v6 vit en config — aucun code à changer :

```php
// config/config.php (base_url) + config/config.local.php (api_key)
'blogvault' => [
    'base_url' => 'https://api.blogvault.net/api/v6',
    'api_key'  => '…',
    'auth'     => ['type' => 'bearer'],   // bearer | header | query | basic | none
    'default_query' => ['account' => '…'], // params envoyés à chaque appel
],
```

Chaque appel renvoie le JSON décodé ; un échec lève une `BlogVaultException`
portant le code HTTP et le message d'erreur de v6 (`{"error":{"message",
"details"}}`, désenveloppé).

Les listes se sérialisent en `site_ids[]=…` et les filtres en
`filters[champ:op]=…` : v6 rejette les deux formes que produisent Guzzle et
`http_build_query`, d'où le constructeur de requête maison
(`BlogVaultClient::buildQuery`).

**Câblé** via la probe `blogvault` : elle apparie le site sur l'hôte, puis
collecte vulnérabilités (CVE + score CVSS), statut piraté, pare-feu, sauvegardes,
2FA des administrateurs et durcissement `wp-config` — 7 appels par extraction.
Alimente les règles `BV1`–`BV6`. Les identifiants HTTP du site renvoyés par
`GET /sites/{id}` sont supprimés avant écriture — jamais dans `data/`.

Quand le scan signale un problème non résolu (`scanner.status === 'hacked'` ou
des détections non marquées sûres), 6 appels supplémentaires ramènent le détail
exploitable pour un redressement — chemins des fichiers/scripts infectés,
extensions/tâches cron/redirections malicieuses, et les instantanés de
sauvegarde **non** signalés piratés (candidats de restauration). Ce détail vit
sous `data.scanner.remediation` dans `probes/blogvault.json` ; jamais déclenché
sur un site sain, donc sans coût pour la majorité des extractions.

## Wordfence Intelligence (API v3) — deuxième source de vulnérabilités

Contrairement à BlogVault, ce n'est **pas** un appel par site : la base
Wordfence Intelligence est une base complète (~33 000 vulnérabilités),
téléchargée en un seul bloc JSON par variante — `production` (CVE, CVSS,
description, `remediation`) et `scanner` (détection seule, souvent sans CVE
encore attribué). Les deux confirmées en direct : ~78-117 Mo, sans pagination,
sous une limite de débit stricte (observée : les deux variantes en 429 après
une poignée d'appels le même jour — prévoir **~1 rafraîchissement/jour**).

```php
// config/config.php (base_url) + config/config.local.php (api_key)
'wordfence' => [
    'base_url' => 'https://www.wordfence.com/api/intelligence/v3',
    'api_key'  => '…',    // Compte Wordfence → Integrations
    'timeout'  => 120,    // le flux fait des dizaines de Mo
],
```

`wordfence:refresh` (cron **quotidien** suggéré) télécharge les deux variantes
et les réduit en un index compact par `"{type}:{slug}"` dans
`data/reference/wordfence.json` — jamais les 100+ Mo bruts. Un échec total dès
le tout premier rafraîchissement (les deux variantes en 429) ne laisse **aucun
fichier** derrière plutôt qu'un cache vide mais « présent » — sinon la sonde
croirait le site propre alors que l'index n'a jamais existé.

```cron
0 5 * * * php /var/www/xtractor/bin/xtractor wordfence:refresh >> /var/www/xtractor/data/xtractor.log 2>&1
```

**Câblée** via la probe `wordfence` — la seule sans appel réseau : elle
recoupe localement les extensions/thèmes/version du cœur du site (payload de
l'extraction, porté par `SiteContext`) contre l'index en cache. Alimente la
règle `WF1`, indépendante de `BV2` (voir plus haut).

`merge_vulnerabilities()` ([src/Web/helpers.php](src/Web/helpers.php))
réconcilie les deux sources **à l'affichage seulement** — jamais au niveau des
règles — en associant par égalité stricte de `cve_id` (jamais par
recoupement approximatif de plage de version, qui produirait de faux
« confirmé par les deux »). Chaque vulnérabilité affichée porte son badge de
source : BlogVault, Wordfence, ou les deux.

> ⚠️ **Le flux `scanner` ne porte aucun `cve_id`** — mesuré sur l'index réel :
> 0 sur 41 733 entrées scanner, contre 39 189 sur 42 522 (92 %) côté
> `production`, qui fournit en plus un score CVSS sur 100 % de ses entrées.
> Comme la fusion associe strictement par `cve_id`, **l'attribution croisée
> exige que `production` soit dans le cache** : avec le seul flux scanner, le
> badge « BlogVault + Wordfence » ne peut jamais apparaître.

Le cache est écrit en **JSON Lines** (une ligne par composant) et lu **en
streaming** : un scan ne décode que les quelques dizaines de composants du site,
jamais les ~18 000. Décoder le fichier entier coûtait 243 Mo pour en lire 35 —
soit un *fatal* OOM à la limite PHP par défaut de 128 Mo, qui tuait tout le
processus `ingest:process`. En streaming : ~8 Mo, constant quelle que soit la
croissance de la base.

## Catalogue plugins/thèmes (licences)

Référentiel transversal (tous sites) de chaque plugin/thème vu, pour repérer
ceux qui risquent une **licence payante**. Chaque extraction enregistre les
slugs vus ; un analyste classe ensuite chacun : `free` / `premium` / `mixed`
(gratuit mais connectable à une licence, ex. MailPoet) / `unknown`.

```bash
./bin/xtractor catalog:suggest              # présence wp.org -> suggère free/premium
./bin/xtractor catalog:list --needs-license # premium + mixed = licence probable
./bin/xtractor catalog:set plugin mailpoet mixed
```

Stocké dans `data/catalog/software.json`. Vu aussi dans l'UI web (`/catalog`,
+ colonne Licence sur la page d'extraction). La suggestion (`catalog:suggest`)
interroge api.wordpress.org : présent → `free` (l'analyste affine en `mixed` au
besoin), absent → `premium`.

## Ajouter une probe

1. Créer `src/Probe/MaProbe.php` étendant `AbstractProbe` (implémenter
   `name()`, `version()`, `collect()` — le parsing dans une méthode statique
   pure pour les tests).
2. L'enregistrer dans `App::probeRegistry()`.
3. L'ajouter à `probes.enabled` dans la config.

## Tests

```bash
composer test              # sans réseau
vendor/bin/phpunit --group network   # probes live (optionnel)
```

## Protocole d'entrée (fixé par le plugin)

POST JSON, headers `X-SWP-Site` (UUID), `X-SWP-Type`
(`extraction|event|integrity`), `X-SWP-Timestamp` (unix),
`X-SWP-Signature` = `hash_hmac('sha256', timestamp . '.' . body, api_key)`.
Fenêtre anti-rejeu : ±300 s (configurable). Corps : toujours `site_id` +
`schema_version`. Le plugin considère tout 2xx comme un succès.
