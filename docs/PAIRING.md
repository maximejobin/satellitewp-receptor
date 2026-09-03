# Appairer un site WordPress au receptor

Le plugin **SatelliteWP Maintenance** pousse ses payloads signés vers
`public/receptor/`. Le protocole des deux côtés est identique — méthode, en-têtes,
chaîne signée, enveloppe JSON — et il n'y a rien à adapter dans le code. Ce qui
fait échouer une première mise en service est toujours la configuration. Ce
document est la marche à suivre, plus les pièges connus.

---

## 1. Provisionner la clé

Il n'y a **pas** d'auto-appairage : ni le plugin ni le receptor ne crée de compte
tout seul. Le receptor est joignable depuis Internet, donc un site inconnu qui se
présente est refusé, pas enregistré.

L'identifiant de site est un UUID v4 généré côté WordPress au premier chargement
du plugin (option `swp_site_id`). Il faut donc partir du site, pas du serveur.

1. **Sur le site WordPress** — Réglages → SatelliteWP, relever l'« Identifiant de
   site ». C'est l'UUID que le plugin enverra dans `X-SWP-Site`.

2. **Sur le serveur Xtractor** — créer la clé, en CLI :

   ```bash
   bin/xtractor keys:add <uuid>
   ```

   ou depuis l'interface web : sur la page **Sites** (`/`), déplier « Pair a
   new site » et coller le même UUID (et, en option, l'origine). Les deux font
   exactement la même chose sur le même `data/keys.json` — l'UI est pratique
   pour un appairage ponctuel, la CLI pour un script. La clé nouvellement créée
   s'affiche ensuite sur la page du site (`/site/<uuid>`), qui existe déjà à ce
   stade même si aucune extraction n'est encore arrivée.

   La clé (64 caractères hexadécimaux) n'est affichée **qu'une fois**, CLI comme
   UI. Elle est stockée dans `data/keys.json`, en clair, en `0600`.

3. **Retour sur le site**, coller la clé dans **Réglages → SatelliteWP → Appairage**.
   Elle est écrite dans `.satellitewp-maintenance.php`, à la racine WordPress, à côté
   de `wp-config.php` — jamais en base, donc elle ne voyage pas dans un export SQL.

   Si la racine n'est pas inscriptible, l'écran affiche le contenu exact du fichier à
   déposer par SFTP. Alternative équivalente, à préférer si tu as l'accès disque :

   ```php
   define( 'SWP_API_KEY', '<la clé>' );
   ```

   La constante l'emporte sur le fichier ; quand elle est définie, le champ s'affiche
   désactivé avec la mention de son origine.

4. **Vérifier** : Réglages → SatelliteWP → « Send Extraction Data ». Une réponse `200`
   signifie que l'extraction est arrivée et attend en `pending` — c'est un analyste qui
   la met en file avec « Lancer l'analyse ».

Sans clé, le plugin **n'envoie aucun en-tête `X-SWP-Signature`** (il ne signe que si une
clé est configurée) et le receptor répond `401 Unsigned payloads are not accepted`,
puisque `allow_unsigned` vaut `false` dans `config/config.php`. Ne le passe à `true`
qu'en développement local.

### L'appairage lie le site à son adresse

Enregistrer une clé **note l'adresse sur laquelle le site répond à ce moment-là**, dans
le fichier local. C'est ce qui empêche une copie restaurée depuis un backup de rapporter
par-dessus l'historique de la production : elle porte le fichier de la PROD mais répond
sur `staging.example.com`, donc elle refuse d'émettre et l'affiche sur son écran de
réglages.

Le receptor applique la même liaison de son côté, parce qu'une vérification côté client
peut être retirée par quelqu'un qui modifie le plugin. La première extraction reçue lie
la clé à l'adresse annoncée ; ensuite, toute extraction venant d'une autre adresse est
refusée avec un `409`. Pour lier explicitement dès la création :

```bash
bin/xtractor keys:add <uuid> --origin https://example.com
```

Les adresses sont comparées **sans le protocole, sans le `www.` de tête et sans la barre
oblique finale** : un passage `http` → `https` ou une redirection `www` n'est donc pas lu
comme un déménagement. Un sous-domaine, un sous-répertoire ou un port différent, si.

### Quand un site déménage vraiment

```bash
bin/xtractor keys:rebind <uuid> https://nouveau-domaine.com
```

Le `site_id` ne change pas, donc **tout l'historique du site est conservé**. C'est la
raison pour laquelle l'identifiant reste un UUID stable plutôt qu'une valeur dérivée de
l'URL : autrement, chaque changement de domaine créerait un nouveau site et repartirait
de zéro.

### Rotation et révocation

```bash
bin/xtractor keys:list            # aperçu : 6 caractères + statut
bin/xtractor keys:revoke <uuid>   # marque revoked, ne supprime pas l'entrée
bin/xtractor keys:add <uuid>      # remplace l'entrée et remet revoked à false
```

Même chose depuis la page du site (`/site/<uuid>`) : elle affiche le statut de
la clé, un bouton « Revoke » et un formulaire « Rebind », et le même
formulaire de création sert à rotationner une clé (recréer sur un `site_id`
déjà présent la remplace et remet `revoked` à `false`). Il n'y a plus de
tableau listant toutes les clés à part — la gestion se fait site par site, là
où le reste de son historique vit déjà.

Une clé révoquée se comporte exactement comme une clé absente : un push signé
reçoit `401 No API key registered for this site`. Après une rotation, mettre à jour la
clé sur le site **avant** le prochain envoi — écran d'appairage, ou `SWP_API_KEY`.

Enregistrer une nouvelle clé depuis l'écran ré-inscrit aussi l'adresse courante : c'est
la manière de ré-appairer délibérément une copie pour qu'elle rapporte sous sa propre
identité.

---

## 2. L'endpoint

L'URL du receptor se règle dans **Réglages → SatelliteWP → « Receptor URL »** (écrite
dans `.satellitewp-maintenance.php`), ou par constante quand elle doit être épinglée par
l'infrastructure :

```php
define( 'SWP_EXTRACTION_ENDPOINT_URL', 'https://receptor.exemple.com' );
```

La constante l'emporte sur le fichier. Champ vide = valeur par défaut,
`https://receptor.satellitewp.com`. Dans tous les cas, **sans barre oblique finale ni
chemin**.

Dès que l'endpoint effectif n'est pas celui par défaut, l'écran de réglages du site
affiche un **encadré orange** nommant l'URL utilisée, l'URL par défaut, et d'où vient la
surcharge (constante ou fichier). C'est le repère visuel pour ne pas confondre un site
pointé sur un receptor de test avec un site en production. Les trois types de payload (`extraction`, `event`, `integrity`) vont à
cette même URL ; c'est l'en-tête `X-SWP-Type` qui les distingue.

Côté serveur, le vhost doit servir `public/receptor/` comme racine :

```
receptor.exemple.com  ->  /var/www/xtractor/public/receptor
```

**Le vhost ne doit pas rediriger.** Le plugin envoie désormais ses requêtes avec
`'redirection' => 0`, donc une redirection remonte franchement comme
« HTTP 301 ». Ce n'était pas toujours le cas : `wp_remote_post()` suit les
redirections par défaut, et sur un 301/302 WordPress repasse la requête en `GET`
en abandonnant le corps et les en-têtes personnalisés — le receptor répondait
alors `404 Not found` en texte brut, un symptôme qui ressemble à « mauvaise URL »
alors que l'URL est bonne. Les causes classiques restent les mêmes : ajout
automatique d'une barre oblique finale, redirection `http` → `https`, redirection
apex → `www`. Pointez le réglage directement sur l'URL canonique finale.

Autres réglages serveur à aligner :

| Réglage | Valeur | Pourquoi |
|---|---|---|
| `client_max_body_size` (nginx) | ≥ 10 Mo | le receptor refuse au-delà de `max_body_bytes` (10 Mio) |
| NTP | actif des deux côtés | fenêtre anti-rejeu de ±300 s (`replay_window_seconds`) |

Le timeout du plugin est de 20 s. Le receptor ne lance aucune probe à la
réception — il vérifie, stocke, indexe — donc la réponse est rapide même sur un
gros payload.

---

## 3. Pas de nonce : les retries ne sont pas idempotents

L'anti-rejeu repose uniquement sur `X-SWP-Timestamp`. Il n'y a pas de registre de
nonce. Une requête signée rejouée à l'identique dans les 300 s est acceptée une
seconde fois et crée une extraction en double (répertoire suffixé `-2`). À garder
en tête avant d'ajouter une logique de réessai côté plugin ou côté supervision.

---

## 4. Diagnostiquer un push refusé

Le receptor répond toujours en JSON, `{"status":"error","message":"…"}`, et le
plugin remonte ce message tel quel dans l'écran d'administration — par exemple
« Remote endpoint returned HTTP 401: Invalid X-SWP-Signature ». Le tableau
ci-dessous relie chaque message à sa cause.

| Code | `message` | Cause |
|---|---|---|
| 404 | `Not found` (texte brut) | requête non-POST, ou `X-SWP-Type` absent — souvent une redirection du vhost |
| 413 | `Payload too large` | corps > 10 Mio |
| 400 | `Empty body` | corps vide |
| 400 | `Missing or malformed X-SWP-Site` | l'en-tête n'est pas un UUID |
| 400 | `Missing or unknown X-SWP-Type` | autre que extraction / event / integrity |
| 400 | `Missing or malformed X-SWP-Timestamp` | vide ou non numérique |
| 401 | `X-SWP-Timestamp outside the accepted window` | horloge décalée de plus de 300 s |
| 401 | `Unsigned payloads are not accepted` | `SWP_API_KEY` non défini sur le site |
| 401 | `No API key registered for this site` | site inconnu du `keys:add`, ou clé révoquée |
| 401 | `Missing X-SWP-Signature` | clé connue du serveur mais en-tête absent |
| 401 | `Invalid X-SWP-Signature` | clés différentes de part et d'autre, ou corps modifié en transit |
| 422 | `Body is not a JSON object` | JSON invalide |
| 422 | `Missing schema_version` | absent, vide, ou envoyé autrement qu'en chaîne |
| 422 | `Body site_id does not match X-SWP-Site header` | comparaison stricte, sensible à la casse |
| 422 | `Event payload requires an "events" array` | payload `event` mal formé |
| 422 | `Integrity payload requires an "integrity" object` | payload `integrity` mal formé |
| 409 | `This site is registered as … but reported from …` | l'extraction vient d'une autre adresse que celle liée à la clé — copie restaurée, ou déménagement réel (voir `keys:rebind`) |
| 500 | `Storage failure (ref …)` | écriture impossible sous `data/` — le détail est dans `logs/`, voir ci-dessous |

### Retrouver un 500 par sa référence

Le receptor ne renvoie jamais le détail d'une erreur interne au plugin — un site
client n'a pas à connaître l'arborescence du serveur. Il renvoie un message générique
et une **référence** : `Storage failure (ref 9f3a1c02)`. Cette référence pointe sur
une ligne unique côté serveur :

```bash
grep 9f3a1c02 logs/error-$(date -u +%F).log | python3 -m json.tool
```

L'entrée contient le type d'exception, son message, le fichier et la ligne, les
premières frames de la pile, le `site_id` et le type de payload concernés. Ni corps de
requête, ni signature, ni cookie n'y sont écrits.

Les trois manières d'obtenir un 500 sont couvertes — exception non attrapée, erreur
fatale PHP, et statut 5xx posé à la main — parce que le gestionnaire est installé par
les deux front controllers **avant** le chargement de la configuration : un
`config.local.php` cassé se journalise comme le reste.

**Si `logs/` est vide alors qu'un 500 est survenu**, c'est que le répertoire n'est pas
inscriptible par l'utilisateur PHP-FPM. Le logger ne lève jamais d'exception : il
retombe sur `error_log()` et la ligne JSON part dans le log PHP-FPM. Créer le
répertoire avec le bon propriétaire résout ça :

```bash
ps -o user= -p "$(pgrep -n php-fpm)"
mkdir -p logs && chown <user-php-fpm> logs && chmod 750 logs
```

### Fonctions PHP désactivées

Un hébergement managé met souvent des fonctions dans `disable_functions`, et **une
fonction désactivée lève une `Error`** — que l'opérateur `@` ne supprime pas : il
silencie les diagnostics, pas les throwables. Un appel réputé « best effort » peut
donc faire tomber toute la requête.

Le message dans `logs/` est reconnaissable, avec le namespace de l'appelant :

```
Call to undefined function SatelliteWP\Xtractor\Storage\symlink()
```

C'est arrivé pour de vrai avec `symlink()`, utilisé pour le raccourci
`data/sites/<id>/extractions/latest` que rien ne lit. Corrigé : l'échec est maintenant
sans conséquence, le raccourci est simplement absent. Les probes sont déjà isolées une
par une par le worker, donc une fonction réseau désactivée y devient un probe en erreur,
pas une panne.

Pour vérifier ce que l'hébergeur interdit :

```bash
php -r 'echo ini_get("disable_functions"), "\n";'
```

### Droits sur `data/`

L'autre source classique de `500 Storage failure`. `data/` est écrit par **deux
utilisateurs différents** : PHP-FPM pour le receptor (`data/sites/…`,
`data/index.sqlite`, `data/keys.json` lors de la liaison d'origine), et le compte SSH
ou cron pour `bin/xtractor`. Un `data/` créé par l'un n'est pas forcément inscriptible
par l'autre.

```bash
ls -la data/ data/sites/
chgrp -R <groupe-commun> data logs && chmod -R g+w data logs
find data logs -type d -exec chmod g+s {} +   # les nouveaux fichiers héritent du groupe
```

SQLite en mode WAL crée `index.sqlite-wal` et `index.sqlite-shm` à côté de la base :
c'est le **répertoire** qui doit être inscriptible, pas seulement le fichier.

### Commencer par l'écran du site

`Réglages → SatelliteWP → Appairage` se termine par un tableau **Status**. Il donne, sans
avoir à ouvrir un log : le receptor réellement contacté et d'où vient ce réglage, si une
clé est configurée et d'où elle vient, l'état du fichier de configuration, l'adresse du
site comparée à celle pour laquelle la clé a été émise, et surtout **le dernier envoi** —
quand, vers quelle URL, et le code HTTP ou l'erreur exacte, message du receptor compris.

Un push refusé localement (copie restaurée) y apparaît aussi : le plugin enregistre la
tentative même quand il choisit de ne rien envoyer.

### Rejouer un push à la main

```bash
SITE=<uuid>
KEY=<clé>
BODY=tests/fixtures/extraction-valid.json
TS=$(date +%s)
SIG=$(php -r 'echo hash_hmac("sha256", $argv[1].".".file_get_contents($argv[2]), $argv[3]);' "$TS" "$BODY" "$KEY")

curl -sS -D- -X POST https://receptor.exemple.com/ \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "X-SWP-Site: $SITE" -H "X-SWP-Type: extraction" \
  -H "X-SWP-Timestamp: $TS" -H "X-SWP-Signature: $SIG" \
  --data-binary @$BODY
```

Attendu : `200 {"status":"received","id":"…"}`.

Le `Content-Type` n'est pas vérifié par le receptor ; il est envoyé pour la forme.

---

## 5. Le contrat, pour référence

Ce que le plugin envoie, et que le receptor attend — identique des deux côtés.

| | Valeur |
|---|---|
| Méthode | `POST`, sur l'endpoint nu (aucun suffixe de chemin) |
| `X-SWP-Site` | UUID v4 minuscule, doit être égal au `site_id` du corps |
| `X-SWP-Type` | `extraction`, `event` ou `integrity` |
| `X-SWP-Timestamp` | secondes Unix, chiffres uniquement |
| `X-SWP-Signature` | `hash_hmac('sha256', "<timestamp>.<corps brut>", clé)` — hexadécimal minuscule, **sans préfixe** (`sha256=` n'est pas attendu), omis si aucune clé n'est configurée |
| Corps | le payload JSON directement, sans enveloppe ; stocké tel quel dans `payload.json` |
| Enveloppe | `schema_version` (chaîne) + `site_id` obligatoires ; `events[]` pour `event`, `integrity{}` pour `integrity` |
| Succès | tout 2xx ; le plugin ignore le corps de la réponse |

La signature ne couvre que `timestamp` + corps : `X-SWP-Site` et `X-SWP-Type`
sont hors signature.

---

## 6. Côté plugin

Appliqué dans `satellitewp-plugin-maintenance` en même temps que ce document.

1. **Le message d'erreur du receptor remonte jusqu'à l'écran d'administration.**
   `RemoteClient` décode `{"status":"error","message":"…"}` et l'ajoute au `WP_Error` :
   « Remote endpoint returned HTTP 401: Invalid X-SWP-Signature » plutôt qu'un 401 nu.
   Une réponse non-JSON (un 502 d'un proxy) retombe proprement sur le code seul.

2. **Les redirections ne sont plus suivies** (`'redirection' => 0`). Le piège du §2 se
   signale maintenant comme « HTTP 301 », explicite, au lieu d'un GET silencieux répondu
   par un 404 en texte brut.

3. **L'endpoint et la clé sont dans `.satellitewp-maintenance.php`**, éditables depuis
   Réglages → SatelliteWP. Rien en base : la clé ne voyage pas dans un export SQL et ne
   se lit pas avec un accès SQL seul. Le fichier est en `0600`, protégé par
   `defined( 'ABSPATH' ) || exit;`, et supprimé à la désinstallation. Quand la racine
   n'est pas inscriptible, l'écran affiche le contenu exact à déposer par SFTP.

4. **Un tableau Status sur l'écran d'appairage** : receptor effectif et sa provenance,
   présence et provenance de la clé, état du fichier, adresse courante contre adresse
   liée, dernier envoi (code HTTP ou erreur complète), planification et prochaine
   exécution, versions. C'est le point de départ de tout diagnostic côté site.

5. **Le menu SatelliteWP est masqué par défaut.** Un administrateur l'affiche avec
   `?satellitewp=on` sur n'importe quelle URL de wp-admin, et le masque avec
   `?satellitewp=off` ; l'état tient dans un cookie de session, et le paramètre est
   retiré par une redirection. C'est de l'obscurité assumée, pas un contrôle d'accès —
   la page exige toujours `manage_options` — dont le but est qu'un client ne tombe pas
   par hasard sur nos outils. Cela remplace la case à cocher de Réglages → Général.
