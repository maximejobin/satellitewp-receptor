# SatelliteWP Xtractor — working context

Server-side companion to the `satellitewp-plugin-maintenance` WordPress plugin.
It receives signed extraction payloads, runs external validation probes,
evaluates a rule catalogue into **findings**, and shows an analyst dashboard.
PHP 8.4+, Composer, symfony/console, Guzzle. No framework.

## Golden rules (user feedback — also in the persistent memory/)

- **Two repos, one protocol.** `../satellitewp-plugin-maintenance` is a sibling
  working directory and **is editable** — the earlier "read-only reference" rule
  was lifted on 2026-08-27. Wire protocol lives in `src/class-remote-client.php`,
  planning docs in `.github/*.txt` (French). Anything touching the protocol
  (headers, signed string, envelope) must land on **both** sides in the same
  change, and the xtractor fixture `tests/fixtures/extraction-valid.json` must
  keep mirroring the plugin's collectors.
- **Keep it simple.** This is for a ~10-person SMB, not the US army. Do not
  over-architect.
- **`data/` holds raw data + one analysis file only:** `payload.json`,
  `meta.json`, `probes/*.json`, `findings.json`. No derived files (no summary.json).
  Operational output is not data: HTTP 500s go to `logs/` (gitignored), never
  under `data/`.
- **Source files are language-neutral.** `findings.json` and probes carry only
  ids/status/observed/data — never FR/EN prose. Sentences live in
  `config/lang/{en,fr}.php` and are rendered at display time by
  `Rules\Translator`. (Exception: PageSpeed locale is a probe input.)
- Don't propose building what WP-CLI / WP core already does natively.

## Flow

plugin `POST` signed payload → `public/receptor/index.php` **Receptor** (HMAC verify via
`X-SWP-Signature`, anti-replay, store, index as `pending`) → cron
`bin/xtractor ingest:process` (worker: picks up **`queued`** only, never
`pending` — an analyst presses "Lancer l'analyse" in the web UI to queue one, so
an arriving extraction costs a file and no probe quota) → **Pipeline** runs probes (dns, rdap, tls, http,
pagespeed, blogvault, wordfence) writing `probes/*.json`, records plugin/theme
slugs into the **SoftwareCatalog**, then **RuleEngine** evaluates
`config/rules.php` → neutral `findings.json` → web UI renders (localized).

JSON files are the source of truth; `data/index.sqlite` is a rebuildable index
(`index:rebuild`).

The wire protocol was verified field-by-field against the plugin and **matches
exactly** — same signed string (`timestamp . '.' . rawBody`), same lowercase-hex
unprefixed HMAC-SHA256, same four headers, same envelope. Nothing to adapt.
`tests/fixtures/extraction-valid.json` is therefore a **faithful mirror of the
plugin's collectors**, not a convenient hand-written sample: `plugins`/`themes`
are maps keyed by plugin file / stylesheet, the WP count objects are objects, and
`constants` carries all 22 of `SAFE_CONSTANTS` with the `"N/A"` sentinel. It had
drifted into lists and integers, which hid two real bugs — keep it faithful.
Operational pairing (key provisioning, vhost redirects, clock skew) is
`docs/PAIRING.md`.

## Key components

- **Two front controllers, two vhosts, no shared surface**:
  `public/receptor/index.php` (public; signed pushes only, never loads Router,
  no session, no HTML, flat 404 for anything else) and `public/admin/index.php`
  (the UI behind Google sign-in; never accepts a push). They share only the
  `src/` code and `data/`. Assets live under `public/admin/assets/`.
- `src/Http/`: Receptor, SignatureVerifier, PayloadValidator, Router (GET web +
  POST with CSRF; `matchRoute`/`resolveRawFile`/`safeReturn` are pure & tested),
  `GoogleAuth` (OAuth2 code flow; reads the email from Google's userinfo
  endpoint rather than decoding the id_token — no JWT/JWKS handling, no extra
  dependency). `resolveRawFile` has **no name allowlist**: `basename()` already
  confines it to the extraction directory and everything there is on the page
  anyway, so a list guarded nothing while silently 404-ing any probe someone
  forgot to add — which is how blogvault/wordfence became dead links.
- **`@` does not suppress an `Error`.** A function in the host's
  `disable_functions` raises one, and `@` only silences diagnostics — so a
  best-effort `@symlink()` in `DataStore::updateLatestLink()` turned every stored
  extraction into an HTTP 500 on managed hosting. Anything genuinely optional needs
  a `try`/`catch`, not an `@`. The `latest` shortcut is read by nothing; the index
  and the directory listing already know which extraction is newest.
- **Site binding**: a key record in `data/keys.json` carries an `origin` (the site's
  normalized `home_url`). The first extraction received binds it; afterwards an
  extraction from any other address is refused with **409** — that is what stops a
  site restored from a backup from reporting over the original's history. The plugin
  refuses to send in that case too, but that check runs on the client and a client can
  be modified. `PayloadValidator::normalizeOrigin()` mirrors `ConfigFile::normalize_url()`
  plugin-side (scheme, leading `www.` and trailing slash dropped, so http→https and a
  www redirect are not a move) — **keep the two in step**. A real move is
  `keys:rebind <uuid> <url>`, which preserves `site_id` and therefore the history; this
  is why site_id stays a UUID rather than something derived from the URL.
- **Web auth**: Google sign-in when `auth.google.client_id`+`client_secret` are
  set, Basic auth (`web.*`) as a dev fallback, open otherwise. Identity is
  session-backed and the allowlist is re-checked on *every* request, so removing
  someone logs them out on their next click. `src/Storage/UserStore.php` is
  `data/users.json`: a flat email list whose **first entry is the admin** (the
  only one who may edit the list, and the one entry that cannot be removed).
  There is deliberately **no "first sign-in becomes admin" bootstrap** — this UI
  is internet-reachable, so that would let a stranger claim the account; seed it
  from the server with `users:add`.
- `src/Probe/`: `ProbeInterface` + Dns/Rdap/Tls/Http/PageSpeed/BlogVault/Wordfence.
  Parsing is a pure static method per probe, unit-tested without network.
  `WordfenceProbe` is the odd one out: it makes **no** network call — see below.
- `src/Rules/`: `Category` (16 short English codes), `Severity` (C/E/M/I),
  `Status`, `Pastille` (green/orange/red/blue/grey, derived from status+severity),
  `Check`/`CheckResult`, `Finding` (neutral), `Rule`, `RuleCatalog`, `RuleEngine`,
  `Context` (dot paths `payload.*` / `probe.*` / reference), `Translator`.
- `config/rules.php`: 69 rules. Ids follow the plugin's
  `.github/validations-techniques.txt` section letters; `W*` = domain (WHOIS),
  `PS*` = Lighthouse, `BV*` = BlogVault, `WF*` = Wordfence Intelligence.
  Thresholds overridable via `rules.thresholds.<id>`. `BV2` and `WF1` are two
  **independent** vulnerability detectors (deliberately not deduplicated at
  rule level — see `merge_vulnerabilities()` below for the display-side
  cross-source attribution); a site can fail one, both, or neither.
- `config/lang/{en,fr}.php`: per-rule `title`/`fail`/optional `pass`, plus UI /
  status / severity / pastille / category labels.
- `src/Domain/SiteContext.php`: `fromExtractionPayload()` also carries
  `plugins`/`themes`/`wpVersion` (raw payload slices) — needed by
  `WordfenceProbe`, which is the only probe with no HTTP call of its own.
- `src/Reference/EndOfLife.php`: endoflife.date cache (php, wordpress, mysql,
  mariadb) refreshed by `reference:refresh`; read offline by rules F2/F3/H1.
- `src/Reference/WordfenceIndex.php`: local cache of the Wordfence Intelligence
  vulnerability database (`data/reference/wordfence.json`), refreshed by
  `wordfence:refresh` (cron: **daily**). Not per-site like BlogVault — the raw
  feeds are full dumps (production ~117 MB / scanner ~78 MB, confirmed live,
  no pagination) under a **strict rate limit** (observed: both variants 429
  after a handful of calls in one day — treat as ~1 refresh/day, shared across
  both variants). `refresh()` reduces the dump to `"{type}:{slug}" →
  vulnerabilities[]` and drops verbose fields (`description`/`researchers`/
  `cwe`/`copyrights`) to keep the cache small. A refresh where every variant
  fails leaves **no file behind** rather than writing a misleadingly "present"
  empty cache — caught live on the very first run (both feeds 429'd because an
  earlier manual API check had already spent the day's quota); regression
  tests: `testFirstRefreshThatFullyFailsLeavesNoCacheFile`,
  `testPartialFailureStillWritesTheSuccessfulVariant`. A partially-failed
  refresh carries forward **only the failing variant's** old entries — feeding
  the whole previous cache back in re-appended the other variant's stale
  entries on top of the fresh ones, compounding a duplicate per run
  (`testPartialFailureDoesNotDuplicateTheSuccessfulVariant`).
  Records flagged `informational` are **kept and counted**: spot-checking real
  ones (e.g. a CSRF in Under Construction ≤ 3.96, patched in 3.97) shows the
  flag marks lower severity, not "not a vulnerability" — filtering them out
  would hide actionable, patchable issues. 158 of 41 733 real entries (0.4%).
- `vulnerabilitiesFor()` **de-duplicates by vulnerability id**: one Wordfence
  record can list the same slug several times (a plugin sold in editions gets
  one `software[]` entry per edition, each with its own range and patched
  versions). When those ranges overlap the same vulnerability matched more than
  once — live, miniorange-oauth-oidc-single-sign-on 18.5.3 matched a single
  vulnerability **7 times**, which would have rendered as "7 CVE" for one issue.
  Regression test: `testSameVulnerabilityListedTwiceIsCountedOnce`.
- **The `scanner` feed carries no CVE ids at all** — measured on the full real
  index: 0 of 41 733 scanner entries have `cve_id`, versus 39 189 of 42 522
  (92%) on `production`, which also carries a CVSS score on 100% of entries.
  `merge_vulnerabilities()` matches the two sources strictly by `cve_id`, so
  **cross-source attribution only works once `production` is in the cache** —
  with a scanner-only cache the "BlogVault + Wordfence" badge can never appear.
  Both feeds are now cached (84 255 entries, 18 158 components, 35 MB).
- The cache is **JSON Lines**, one component per line (`{"k":"plugin:x","v":[…]}`),
  and `WordfenceIndex` **streams** it: `preload()` does a single sequential pass
  for the components a scan needs. This is not premature optimisation — decoding
  the whole file cost **243 MB to read 35 components out of 18 000** and blew up
  as a hard OOM at PHP's 128M default, which is a *fatal*, not a catchable
  probe error, so it killed the entire `ingest:process` run. Streaming holds
  ~8 MB and stays flat as Wordfence's database grows. `refresh()` still builds
  the whole map in memory (peaks ~581 MB — hence the command's 1G floor), but
  that runs once a day, not once per site.
- `src/Catalog/SoftwareCatalog.php`: cross-site plugin/theme licence catalogue
  (`data/catalog/software.json`): free/premium/mixed/unknown; wp.org suggestion
  via `catalog:suggest`. `normalizeSlug()` is reused by `WordfenceProbe` to turn
  payload plugin slugs (`"woocommerce/woocommerce.php"`) into Wordfence's bare
  slugs (`"woocommerce"`).
- `src/Integration/BlogVaultClient.php`: generic parameter-driven v6 client,
  wired through `BlogVaultProbe` → rules `BV1`–`BV6`. v6 needs `site_ids[]=`
  and `filters[field:op]=`, which neither Guzzle's builder nor plain
  `http_build_query` produce — hence `BlogVaultClient::buildQuery()`. Site
  matching is by host. `GET /sites/{id}` returns the site's HTTP basic-auth
  password in clear text; the probe strips it before anything reaches `data/`.
- `src/Integration/WordfenceClient.php`: two fixed endpoints (not a generic
  client like BlogVault's — Wordfence has no per-resource API to parameterize).
  `Authorization: Bearer <key>` — **no** `cli-` prefix; that prefix is specific
  to wordfence-cli's own license-key namespace (their public source uses it),
  confirmed live it is **not** needed for an Integrations-generated account key.
  `WordfenceProbe` cross-references the local index against the site's own
  plugins/themes/core version (from `SiteContext`) — the vulnerable-or-not
  verdict comes from BlogVault and Wordfence independently; `merge_vulnerabilities()`
  in `src/Web/helpers.php` reconciles the two for display, matched **only** by
  exact `cve_id` (never by fuzzy version-range overlap), tagging each finding
  `sources: ['blogvault']` / `['wordfence']` / `['blogvault','wordfence']`.
- **Two plugin-side shapes that bite**: `ConstantsCollector` emits the string
  `"N/A"` for an undefined constant, and `(bool) "N/A"` is `true` — which turned
  "no hardening at all" into a **green** K4/K6. Read constants through
  `Context::constant()`, never `bool('payload.constants.*')`; it maps `"N/A"` to
  false (WordPress semantics) and reserves null for "no constants collected".
  Likewise `posts_count`/`comments_count`/`media_count`/`users_count` are the raw
  `wp_count_*()` / `count_users()` objects, not integers — render them through
  `wp_count()` in `helpers.php`, and `field()` now refuses arrays outright rather
  than printing the literal word "Array".
- **500 logging**: `src/Support/ErrorLog.php` writes one JSON line per HTTP 500
  into `logs/error-<UTC date>.log` (gitignored, created on first write) and
  returns a short `ref` that also goes back to the client — a site owner quoting
  "ref 9f3a1c02" points at one line. `src/Http/ErrorHandler.php` is installed by
  **both** front controllers before the app boots (so a broken
  `config.local.php` is logged too) and covers the three ways a 500 happens:
  uncaught throwable (`set_exception_handler`), fatal error and hand-set 5xx
  (`register_shutdown_function`). The receptor's own `Storage failure` logs
  itself with the site id and payload type, and `ErrorLog::entriesWritten()`
  keeps the shutdown pass from recording it twice. The logger never throws — a
  failed write falls back to `error_log()`. Entries carry no body, no headers
  beyond `X-SWP-Site`/`X-SWP-Type`, no cookies.
- `src/Web/`: `templates/` (sidebar `layout`, `sites`, `site`, `extraction`,
  `catalog`) + `helpers.php` (incl. `merge_vulnerabilities()`,
  `vulnerability_source_badge()`); `public/assets/style.css`.

## UI (approved design)

- Sidebar shell, **orange accent `#f26f2b`**, cool-slate neutrals, **pale default
  + dark toggle**, mono for data/ids. Brand is text only ("**SatelliteWP**
  Xtractor", no icon).
- Extraction page: **overview** (health ring = `100 − red*8 − orange*3`, floored;
  semantic ring colour; pastille tally; KPI tiles) → **findings** full list
  filterable by type → **10 sections** with COMPLETE data always shown (e.g. TLS
  1.0/1.1/1.2/1.3 independently, full plugin info) → raw (collapsible).
- The 10 sections: Account & plan · Domain & email · Hosting · Performance ·
  SEO & analytics · WordPress · Plugins & themes · Content & languages · Users ·
  Security & backup. **Every datum has a home**; not-yet-collected ones show a
  "coming soon" note.
- **Pastilles replace severity in the display**: green = pass, red = fail (C/E),
  orange = fail (M), blue = info, grey = n/a/unknown.
- `design/dashboard-proposal.html` = the standalone style mockup.

## CLI (`bin/xtractor`)

`ingest:process` · `pipeline:run` · `probe:run`/`probe:list` ·
`rules:evaluate [--lang]`/`rules:list` · `reference:refresh` ·
`wordfence:refresh` (suggested cron: **daily**) ·
`catalog:list [--needs-license]`/`catalog:set`/`catalog:suggest` ·
`keys:add [--origin]`/`list`/`revoke`/`rebind` · `users:add` (seeds the web allowlist; no argument
lists it) · `sites:list` · `extractions:list` · `index:rebuild`.

## Testing

`composer test` — 270 tests, no network. Manual end-to-end: `docs/TESTING.md`.
`phpunit.xml.dist` excludes the `network` group, reserved for any future
live-probe tests; none exist yet, so the suite runs fully offline.

## Conventions

`declare(strict_types=1)` everywhere; typed params; English docblocks; PHPUnit
`#[DataProvider]` attributes. Commit messages end with the `Co-Authored-By` line.
No PHPStan yet (user chose a targeted quality pass).

## Config / secrets

`config/config.php` (committed) + `config/config.local.php` (gitignored):
`pagespeed.api_key`, `blogvault.api_key` and `wordfence.api_key` live there;
base URLs / timeouts / auth schemes are non-secret and stay in `config.php`.

## Pending / next steps

- **Vulnerabilities are rendered** (§WordPress core + §Plugins & themes: a
  "Vulnérabilités" column per component, a merged CVE table tagged BlogVault /
  Wordfence / both). What is **still** "coming soon" in §Security & backup is
  the *non*-vulnerability BlogVault detail — scanner status, firewall mode,
  backup/snapshot state, and (on a hacked site) the `data.scanner.remediation`
  block (infected file/script/plugin/cron/redirection paths, clean-snapshot
  candidates) — `extraction.php` around the "Integrity, malware & backup" card.
- Wordfence's **production** feed shape (`cve`/`cvss`/`description`/
  `remediation` fields) is implemented from Wordfence's own official
  wordfence-cli source, not empirically confirmed against a live response —
  the day's rate limit was already spent validating the client/auth format
  before `wordfence:refresh` could run for real. Re-run it once the quota
  resets and sanity-check `data/reference/wordfence.json` picked up
  `cve_id`/`cvss_score` values (the **scanner** variant's shape — `id`, `title`,
  `software[]`, `informational`, wildcard `"*"` version bounds — *is* confirmed
  live, field-by-field, against a real ~78 MB / ~39k-record response).
- **Plugin data with no home in the UI yet.** The faithful fixture makes these
  visible: `post_types` / `post_type_count`, `mu_plugins`, `dropin_plugins`,
  `auto_update_plugins`, `plugin_updates` / `theme_updates`, `super_admins`,
  `connectors` (WooCommerce order/product counts, HPOS, gateways; WPML
  languages), `_errors` (per-collector failures), `filesystem.permissions`,
  `database.transients`, `is_backend_ssl`, `multisite_type` / `multisite_count` /
  `multisite_sites_status`. "Every datum has a home" is not true of these yet.
- **WooCommerce platform** → §Account & plan (client, care plan, renewal).
- **Analytics tags** (GA / Ads / pixel) → §SEO & analytics — needs a `[DATA+]`
  extractor field (plugin side).
- Exposure probes (source 7), mail validator (external API TBD), ASN/hosting
  lookup, computed SSL grade.
