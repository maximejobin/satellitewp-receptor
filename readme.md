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
  un cron traite les extractions en attente.

## Installation

```bash
composer install
cp config/config.local.php.dist config/config.local.php   # puis ajuster
```

Vhost : pointer le DocumentRoot sur `public/`. Toute requête inexistante doit
être réécrite vers `public/index.php` (le receptor accepte le POST sur
n'importe quel chemin).

Nginx minimal :

```nginx
server {
    server_name receptor.satellitewp.com;
    root /var/www/xtractor/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
}
```

Crontab (traitement des extractions en attente + relance des runs plantés) :

```cron
* * * * * php /var/www/xtractor/bin/xtractor ingest:process --requeue-stale=30 >> /var/www/xtractor/data/xtractor.log 2>&1
```

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
./bin/xtractor keys:add <site_id> --label="Client X"
```

La clé affichée (une seule fois) va dans le `wp-config.php` du site :

```php
define( 'SWP_API_KEY', '…' );
define( 'SWP_EXTRACTION_ENDPOINT_URL', 'https://receptor.satellitewp.com' );
```

## CLI

| Commande | Rôle |
| --- | --- |
| `ingest:process [--limit=N] [--requeue-stale=MIN]` | Traite les extractions `pending` (point d'entrée cron, verrou flock) |
| `pipeline:run <site_id> [extraction] [--probe=a,b]` | Pipeline complet ou partiel (défaut : dernière extraction) |
| `probe:run <probe> <site_id> [extraction]` | Une probe seule, imprime l'enveloppe JSON |
| `probe:list` | Probes enregistrées |
| `rules:evaluate <site_id> [extraction] [--all] [--json]` | Ré-évalue le catalogue (sans réseau) |
| `rules:list [--category=…]` | Catalogue de règles |
| `sites:list [--search=…]` / `extractions:list <site_id>` | Listes |
| `keys:add/list/revoke` | Gestion des clés API par site |
| `index:rebuild` | Régénère SQLite depuis `data/` |

## Layout data/

```
data/sites/<site_id>/
├── site.json                      # infos dénormalisées du site
├── extractions/<AAAAMMJJTHHMMSSZ>/
│   ├── payload.json               # payload brut, tel que reçu
│   ├── meta.json                  # réception (ip, signature, taille)
│   ├── probes/{dns,rdap,tls,http,pagespeed}.json
│   ├── summary.json               # digest plat → rapports et listes
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
(M)oyenne / (I)nfo**. Deux préfixes sont des ajouts hors catalogue, volontairement
distincts pour ne jamais entrer en collision : `W*` (domaine WHOIS/RDAP) et `PS*`
(scores Lighthouse).

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
'rules' => ['thresholds' => ['I1' => 1048576, 'M1' => 3, 'C6' => 800]],
```

```bash
./bin/xtractor rules:list                          # catalogue
./bin/xtractor rules:evaluate <site_id> [--all]    # ré-évalue (sans réseau)
```

Ajouter une règle = un tableau dans `config/rules.php` avec une closure `check`
recevant un `Context` (`$c->number('payload.autoload.total_bytes')`,
`$c->bool('probe.http.redirects.forces_https')`). Une règle qui lève une
exception devient `unknown` et n'interrompt jamais l'évaluation.

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
