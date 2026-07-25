# Manual test checklist

End-to-end verification of SatelliteWP Xtractor. Automated tests cover the logic
(`composer test`); this checklist confirms the moving parts work together on a
real machine. Run it after a fresh clone, before a release, or after touching
ingestion, the pipeline, or the web surface.

Each step lists what to do and what you should see (✅). A step that does not
match is a failure — note it and stop.

---

## 0. Setup

```bash
composer install
cp config/config.local.php.dist config/config.local.php
```

- ✅ `vendor/` is created, no Composer errors.
- ✅ `composer test` → **all tests green** (currently 120).
- Edit `config/config.local.php`: set `pagespeed.api_key`, and for a first run
  set `allow_unsigned => false` (default).

Reset test data between full runs if needed:

```bash
rm -rf data/sites data/index.sqlite data/keys.json data/reference
```

---

## 1. Reference data (endoflife.date)

```bash
./bin/xtractor reference:refresh
```

- ✅ Reports `php : N cycles` and `wordpress : N cycles`.
- ✅ `data/reference/php.json` and `data/reference/wordpress.json` exist.

---

## 2. Register a site key

```bash
./bin/xtractor keys:add 3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c --label="Test"
./bin/xtractor keys:list
```

- ✅ `keys:add` prints an API key **once**.
- ✅ `keys:list` shows the site with a redacted key and status `active`.
- Copy the full key into a shell variable for the next step:

```bash
SITE=3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c
KEY=<the key printed above>
```

---

## 3. Receive a signed extraction (the receptor)

Start the dev server and POST a fixture with a valid HMAC:

```bash
php -S 127.0.0.1:8080 -t public &
BODY=tests/fixtures/extraction-valid.json
TS=$(date +%s)
SIG=$(php -r 'echo hash_hmac("sha256", $argv[1].".".file_get_contents($argv[2]), $argv[3]);' "$TS" "$BODY" "$KEY")
curl -s -X POST http://127.0.0.1:8080/ \
  -H "X-SWP-Site: $SITE" -H "X-SWP-Type: extraction" \
  -H "X-SWP-Timestamp: $TS" -H "X-SWP-Signature: $SIG" \
  --data-binary @$BODY
```

- ✅ Response is `{"status":"received","id":"..."}` (HTTP 200).
- ✅ `data/sites/$SITE/extractions/<id>/payload.json` exists and equals the body.
- ✅ `data/sites/$SITE/extractions/<id>/meta.json` shows `"signature_valid": true`.
- ✅ `./bin/xtractor extractions:list $SITE` shows the extraction as `pending`.

### Failure cases (all must be rejected, nothing stored)

```bash
# Bad signature -> 401
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8080/ \
  -H "X-SWP-Site: $SITE" -H "X-SWP-Type: extraction" \
  -H "X-SWP-Timestamp: $TS" -H "X-SWP-Signature: wrong" --data-binary @$BODY   # -> 401

# Unknown type -> 400
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8080/ \
  -H "X-SWP-Site: $SITE" -H "X-SWP-Type: bogus" \
  -H "X-SWP-Timestamp: $TS" -H "X-SWP-Signature: $SIG" --data-binary @$BODY     # -> 400

# Stale timestamp -> 401 (replay window)
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8080/ \
  -H "X-SWP-Site: $SITE" -H "X-SWP-Type: extraction" \
  -H "X-SWP-Timestamp: 1000000000" -H "X-SWP-Signature: $SIG" --data-binary @$BODY  # -> 401
```

- ✅ Codes are 401 / 400 / 401 respectively.

Also POST `event-valid.json` (type `event`) and `integrity-valid.json`
(type `integrity`) the same way:

- ✅ event → `{"status":"received","events":2}`, a `data/sites/$SITE/events/*.jsonl` file appears.
- ✅ integrity → a `data/sites/$SITE/integrity/*.json` file appears.

---

## 4. Process the pipeline (probes + rules)

```bash
./bin/xtractor ingest:process
```

- ✅ Prints `Processing $SITE/<id> … done [http:… dns:… tls:… rdap:… pagespeed:…]`.
- ✅ `data/sites/$SITE/extractions/<id>/probes/` has `dns.json rdap.json tls.json http.json pagespeed.json`.
- ✅ `summary.json` and `findings.json` exist in the extraction dir.
- ✅ `extractions:list $SITE` now shows status `done`.

> Note: `pagespeed` needs a valid API key; without one it reports `warn`/`error`
> with a 429 message, which is expected — the rest of the pipeline still completes.

Re-run a single probe and the rules (no re-ingest needed):

```bash
./bin/xtractor probe:run tls $SITE
./bin/xtractor rules:evaluate $SITE
./bin/xtractor rules:evaluate $SITE --all       # includes pass/na/unknown
./bin/xtractor rules:list --category=TLS
```

- ✅ `probe:run tls` prints a JSON envelope with `status`, `data`, `errors`.
- ✅ `rules:evaluate` prints a table of failures and a count line
  (`N règles évaluées — X en échec (C:… É:… M:… I:…)`).
- ✅ `F3` (PHP) and `F2` (WordPress) reflect the endoflife.date data
  (not "unknown" — reference:refresh was run in step 1).

---

## 5. Web UI (read-only)

Open `http://127.0.0.1:8080/` in a browser.

- ✅ **Sites list** shows the test site with its last status.
- ✅ Click the site → **site detail**: extraction history, recent events,
  trends.
- ✅ Click the extraction → **extraction detail**: summary sections, PageSpeed
  table, the **Constats** (findings) table, per-probe cards, raw JSON toggles.
- ✅ Raw JSON links work: `…/raw/payload`, `…/raw/tls`, `…/raw/findings` return
  `application/json`.

### Security checks

```bash
# Allowlist + traversal: must be 404, never serve another file
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8080/site/$SITE/extraction/<id>/raw/keys"        # 404
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8080/site/bad-uuid"                               # 404
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8080/site/$SITE/extraction/not-an-id"             # 404
```

- ✅ All three return 404.

### Basic auth (optional)

Set `web.user` + `web.pass_hash` in `config.local.php`
(`php -r "echo password_hash('secret', PASSWORD_DEFAULT);"`), reload:

- ✅ Browser prompts for credentials; wrong password → 401; correct → pages load.

---

## 6. Index rebuild

```bash
rm data/index.sqlite
./bin/xtractor index:rebuild
./bin/xtractor sites:list
```

- ✅ `index:rebuild` reports the number of extractions indexed.
- ✅ `sites:list` shows the site again (proves SQLite is a disposable index
  rebuilt from the JSON source of truth).

---

## 7. Live probes against a real site (optional)

Register and ingest a real site (point `SWP_EXTRACTION_ENDPOINT_URL` in a test
WordPress `wp-config.php` at your receptor, trigger an extraction), or store a
minimal payload manually and run `pipeline:run`. Confirm:

- ✅ DNS returns real NS/MX/SPF/DMARC; TLS shows the real issuer and expiry;
  RDAP shows the registrar; HTTP shows compression, HTTP version, robots/sitemap;
  PageSpeed returns mobile + desktop scores.

---

## Teardown

```bash
kill %1   # stop the php dev server
```

Record any ✅ that did not hold, with the command and observed output.
