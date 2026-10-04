# Manual test checklist

End-to-end verification on a real machine. `composer check` (tests + PHPStan)
covers the logic offline; this checklist confirms the moving parts work
together. Run it after a fresh clone, before a release, or after touching
ingestion, the pipeline or the web surface.

Each step lists what to do and what you should see (✅). A step that does not
match is a failure — note it and stop.

> Never use a real client site for steps that write data, and never set a real
> extraction to `queued` by hand: the production crontab runs
> `ingest:process` every minute and will execute it.

---

## 0. Setup

```bash
composer install
cp config/config.local.php.dist config/config.local.php
composer check
```

- ✅ `vendor/` is created, `composer check` is green.
- In `config/config.local.php`, set `pagespeed.api_key`. `blogvault.api_key`
  and `wordfence.api_key` are optional: without them those probes report
  `error` and the rest of the pipeline still completes.

Reset local test data between full runs if needed:

```bash
rm -rf data/sites data/index.sqlite data/keys.json
```

---

## 1. Reference data

```bash
./bin/swpmgr reference:refresh
./bin/swpmgr wordfence:refresh     # optional, needs wordfence.api_key
```

- ✅ `reference:refresh` reports cycles for php, wordpress, mysql, mariadb and
  writes `data/reference/*.json` (incl. `wordpress-versions.json`).
- ✅ `wordfence:refresh` reports the entries received per feed and writes
  `data/reference/wordfence.json`.
- ⚠️ Wordfence rate-limits hard (~1 refresh/day per feed). A 429 on one feed
  keeps that feed's cached data; if every feed fails nothing is written.

---

## 2. Register a site key

```bash
SITE=3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c   # site_id of tests/fixtures/extraction-valid.json
./bin/swpmgr keys:add $SITE
./bin/swpmgr keys:list
KEY=<the key printed once by keys:add>
```

- ✅ `keys:add` prints the API key once; `keys:list` shows it redacted, `active`.

---

## 3. Receive a signed extraction

```bash
php -S 127.0.0.1:8080 -t public/receptor &   # extractor
php -S 127.0.0.1:8081 -t public/admin &      # admin UI
BODY=tests/fixtures/extraction-valid.json
TS=$(date +%s)
SIG=$(php -r 'echo hash_hmac("sha256", $argv[1].".".file_get_contents($argv[2]), $argv[3]);' "$TS" "$BODY" "$KEY")
curl -s -X POST http://127.0.0.1:8080/ \
  -H "X-SWP-Site: $SITE" -H "X-SWP-Type: extraction" \
  -H "X-SWP-Timestamp: $TS" -H "X-SWP-Signature: $SIG" \
  --data-binary @$BODY
```

- ✅ HTTP 200 `{"status":"received","id":"..."}`.
- ✅ `data/sites/$SITE/extractions/<id>/payload.json` equals the body;
  `meta.json` has `"signature_valid": true`.
- ✅ `./bin/swpmgr extractions:list $SITE` shows it `pending` — nothing runs
  until an analyst asks for it.

Rejections (nothing stored):

| Change to the request            | Expected |
|----------------------------------|----------|
| `X-SWP-Signature: wrong`         | 401      |
| `X-SWP-Type: bogus`              | 400      |
| `X-SWP-Timestamp: 1000000000`    | 401      |
| same body re-sent (replay)       | 401      |
| any `GET` on the extractor        | 404      |

`event-valid.json` (type `event`) and `integrity-valid.json` (type
`integrity`) are accepted the same way and land under `events/` and
`integrity/`.

---

## 4. Run the analysis

Either press **Run analysis** on the extraction page (queues it for the next
`ingest:process`), or run it directly:

```bash
./bin/swpmgr pipeline:run $SITE
./bin/swpmgr extractions:list $SITE
```

- ✅ One line per probe: dns, rdap, tls, http, pagespeed, blogvault, wordfence,
  mail, crm, wporg, seranking.
- ✅ `probes/*.json` and `findings.json` exist; status is `done`.
- `pagespeed` without a key, `blogvault` for a site absent from the account
  (`"linked": false`) and `wordfence` before its first refresh report
  `warn`/`error`; the pipeline still completes. `mail` reports `error` until
  `mail.username`/`mail.password` are set, then `found: false` until a test email
  from the site (plugin → send test email) has reached the validation mailbox.
  `crm` reports `error` when `crm_db` is unset; a site absent from BlogVault or
  from the CRM is `ok` with `"linked": false` and a `reason`. Relink with
  `probe:run crm <site>`.
- `seranking` reports `error` until `seranking.api_key` is set; with a key it
  creates an audit (spends SE Ranking crawl credits) and stays `pending` while
  the extraction is already `done`. Each `ingest:process` run polls it (at most
  every `poll_minutes`); `./bin/swpmgr seranking:poll --force` checks now. Once
  finished, `probes/seranking.json` is `ok` with `score`, `totals` and `checks`,
  and the extraction page shows the "Site audit (SE Ranking)" card.

Re-run one probe (rewrites that probe's file and findings.json) or inspect:

```bash
./bin/swpmgr probe:run tls $SITE          # re-runs and stores one probe, prints its envelope
./bin/swpmgr rules:evaluate $SITE --all   # every finding + pastille counts
./bin/swpmgr rules:list --category=SSL
./bin/swpmgr rules:reevaluate --dry-run   # effect of a rules.php edit on every stored extraction
```

---

## 5. Web UI

Open `http://127.0.0.1:8081/`.

- ✅ Extractions list → site page (history, recent events, ⚙ Site settings).
- ✅ Extraction page: hero with pastille tally, findings table (filters,
  "Needs attention" = red + orange + purple), sections with complete data,
  plugins/themes with a State column (Active/Inactive, Auto-update,
  Vulnerable, Abandoned) and wp.org links, raw JSON links.
- ✅ A pending extraction shows only its status and **Run analysis** — never a
  partial report.
- ✅ With `debugging_tools => true` in `config.local.php`, a re-run panel
  appears on a done extraction; with it off, `POST …/rerun` answers 404.
- ✅ **Report data key** copies a one-hour URL; `curl` on it returns the report
  JSON with every `{{variable}}` of the Google Doc template.

Guards:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8081/site/$SITE/extraction/<id>/raw/keys"   # 404
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8081/site/bad-uuid"                         # 404
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8081/site/$SITE/extraction/not-an-id"       # 404
```

- ✅ Every POST without the CSRF token is refused.

---

## 6. Index rebuild

```bash
rm data/index.sqlite && ./bin/swpmgr index:rebuild && ./bin/swpmgr sites:list
```

- ✅ The site is back: the SQLite index is disposable, JSON is the source of truth.

---

## 7. Error logging

```bash
chmod 000 data/index.sqlite
# re-send the signed POST from step 3 (new timestamp)
chmod 644 data/index.sqlite
```

- ✅ HTTP 500 with `Storage failure (ref XXXXXXXX)`.
- ✅ `logs/error-$(date -u +%F).log` has one JSON line with that `ref`, the
  exception and the site id — no body, signature or cookie.

---

## Teardown

```bash
kill %1 %2
```
