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

2. **Sur le serveur Xtractor** — créer la clé :

   ```bash
   bin/xtractor keys:add <uuid> --label "nom du site"
   ```

   La clé (64 caractères hexadécimaux) n'est affichée **qu'une fois**. Elle est
   stockée dans `data/keys.json`, en clair, en `0600`.

3. **Retour sur le site**, coller la clé. Deux endroits possibles :

   - **Réglages → SatelliteWP → « Signing key »** — pas d'accès disque requis.
     Le champ est en écriture seule : il s'affiche toujours vide, le soumettre
     vide veut dire « ne pas toucher ».
   - **`wp-config.php`**, avant le `/* That's all */` — plus sûr, à préférer
     quand vous avez l'accès SFTP :

     ```php
     define( 'SWP_API_KEY', '<la clé>' );
     ```

   La constante l'emporte sur l'option : si les deux sont présentes, c'est la
   constante qui sert, et le champ de réglage s'affiche désactivé.

4. **Vérifier** : Réglages → SatelliteWP → « Send Extraction Data ». Une réponse
   `200` signifie que l'extraction est arrivée et attend en `pending` — c'est un
   analyste qui la met en file avec « Lancer l'analyse ».

Sans l'étape 3, le plugin **n'envoie aucun en-tête `X-SWP-Signature`** (il ne
signe que si une clé est configurée) et le receptor répond
`401 Unsigned payloads are not accepted`, puisque `allow_unsigned` vaut `false`
dans `config/config.php`. Ne passez `allow_unsigned` à `true` qu'en développement
local.

### Rotation et révocation

```bash
bin/xtractor keys:list            # aperçu : 6 caractères + statut
bin/xtractor keys:revoke <uuid>   # marque revoked, ne supprime pas l'entrée
bin/xtractor keys:add <uuid>      # remplace l'entrée et remet revoked à false
```

Une clé révoquée se comporte exactement comme une clé absente : un push signé
reçoit `401 No API key registered for this site`. Après une rotation, mettre à
jour `SWP_API_KEY` sur le site **avant** le prochain envoi.

---

## 2. L'endpoint

L'URL du receptor se règle dans **Réglages → SatelliteWP → « Receptor URL »**, ou
par constante quand elle doit être épinglée par l'infrastructure :

```php
define( 'SWP_EXTRACTION_ENDPOINT_URL', 'https://receptor.exemple.com' );
```

La constante l'emporte sur le réglage. Champ vide = valeur par défaut,
`https://receptor.satellitewp.com`. Dans tous les cas, **sans barre oblique
finale ni chemin**. Les trois types de payload (`extraction`, `event`, `integrity`) vont à
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
| 500 | `Storage failure` | écriture impossible sous `data/` — vérifier les droits |

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

Ces trois points ont été appliqués dans `satellitewp-plugin-maintenance` en même
temps que ce document.

1. **Le message d'erreur du receptor remonte jusqu'à l'écran d'administration.**
   `RemoteClient` décode `{"status":"error","message":"…"}` et l'ajoute au
   `WP_Error` : « Remote endpoint returned HTTP 401: Invalid X-SWP-Signature »
   plutôt qu'un 401 nu. Les cinq causes distinctes de 401 du §4 sont désormais
   discernables sans accès au serveur. Une réponse non-JSON (un 502 d'un proxy,
   par exemple) retombe proprement sur le code HTTP seul.

2. **Les redirections ne sont plus suivies** (`'redirection' => 0`). Le piège du
   §2 se manifeste maintenant comme « HTTP 301 » — explicite — au lieu d'un GET
   silencieux répondu par un 404 en texte brut.

3. **L'endpoint et la clé sont réglables depuis Réglages → SatelliteWP**, ce qui
   supprime le besoin d'un accès SFTP pour chaque appairage et chaque rotation.

   Les constantes restent **prioritaires** sur les options : un site qui garde son
   secret dans `wp-config.php` ne peut pas être rétrogradé depuis l'écran de
   réglages, et un endpoint épinglé par l'infrastructure ne peut pas être
   redirigé. Quand une constante est définie, le champ correspondant est affiché
   désactivé avec la mention de son origine.

   Le champ « Signing key » est en écriture seule : il est rendu vide à chaque
   chargement (le secret n'est jamais réémis dans la page), le soumettre vide
   signifie « ne pas toucher », et une case à cocher distincte sert à effacer la
   clé stockée.

   **Compromis à connaître** : `wp_options` est un moins bon endroit qu'un
   `wp-config.php` pour un secret partagé — tout ce qui peut lire la table des
   options peut lire la clé, alors que le fichier demande un accès disque.
   Utilisez la constante `SWP_API_KEY` quand vous avez l'accès aux fichiers, et
   l'option quand vous ne l'avez pas.
