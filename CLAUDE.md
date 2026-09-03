# SatelliteWP Xtractor — working context

Server-side companion to the `satellitewp-plugin-maintenance` WordPress plugin.
It receives signed extraction payloads, runs external validation probes,
evaluates a rule catalogue into **findings**, and shows an analyst dashboard.
PHP 8.4+, Composer, symfony/console, Guzzle. No framework.

## Golden rules (user feedback — also in the persistent memory/)

- **Two repos, one protocol.** The plugin is **not** a sibling of this working
  directory (an earlier note here claiming `../satellitewp-plugin-maintenance`
  was wrong — no such path exists). It lives inside the WordPress install used
  for manual end-to-end testing, at
  `/home/extractor/webapps/wpsite/public/wp-content/plugins/satellitewp-plugin-maintenance`
  (confirmed present 2026-08-30) — its own git repo, remote
  `github.com/maximejobin/satellitewp-plugin-maintenance`, **and is editable**
  (the earlier "read-only reference" rule was lifted on 2026-08-27). Wire
  protocol lives in `src/class-remote-client.php`, planning docs in
  `.github/*.txt` (French), collectors in `src/collectors/class-*-collector.php`.
  Anything touching the protocol (headers, signed string, envelope) must land
  on **both** sides in the same change, and the xtractor fixture
  `tests/fixtures/extraction-valid.json` must keep mirroring the plugin's
  collectors.
- **Keep it simple.** This is for a ~10-person SMB, not the US army. Do not
  over-architect.
- **An extraction is a snapshot in time of a site's configuration, not a
  tracker of its evolving operational status** (2026-08-31, user: "le but de
  l'extraction est de prendre une 'photo dans le temps' de la configuration
  d'un site web"). This is a scope test, not just a style note: BlogVault's
  scanner status/firewall mode/backup state/remediation detail was dropped
  entirely (not "coming soon" — out of scope) for exactly this reason, while
  a vulnerability finding stays in scope because "is this installed version
  known-vulnerable" is a fact about the snapshot, not an evolving status.
  Apply this test before adding any new data source: does it describe what
  the site *is configured as* right now, or does it describe an ongoing,
  independently-changing operational state that belongs to a different tool
  (BlogVault's own dashboard, a monitoring service)? The latter does not
  belong in Xtractor even as a placeholder.
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
- **Pairing has a web UI now too** (2026-08-29, moved 2026-08-29): lives on
  `/site/{id}`, not a standalone table — key management is a per-site task
  and belongs where the rest of that site's history already lives. It is
  **collapsed behind a `<details>` "⚙ Site settings" toggle, closed by
  default, below Extractions/Recent events** (2026-08-29): rebind/revoke/
  rotate is a rarely-touched admin action, not something that should push the
  actual scan/extraction data down the page every time an analyst opens a
  site. It auto-opens only right after creating/rotating a key (so the
  one-time key display, shown above it via a session flash, isn't paired with
  a collapsed section hiding the controls that relate to it). Create, revoke,
  rebind all POST to `/keys` (a mutation-only target with no GET page, same
  pattern as `/users` and `/catalog`), which always redirects back to
  `/site/{id}`. It does **not** generate the site_id: that UUID is generated by
  the plugin itself on first load (`swp_site_id` option) and the operator
  always pastes it in from the site's own pairing screen. A site with a key but
  no extraction yet has no `site.json` (written only by the first extraction),
  so `Router::sitePage()` renders a placeholder ("paired, awaiting first push")
  instead of 404ing whenever a key exists for that id — this is also where a
  brand-new pairing lands right after creation. **Pairing a new site** (no
  `/site/{id}` to visit yet) is therefore a small form on the sites list (`/`,
  behind a `<details>`) that only asks for the UUID + optional origin. Unlike
  `/users`, none of this is admin-gated: it is an operational task, not an
  access-control boundary. A stray `label` field survived in one real
  `data/keys.json` entry from an earlier iteration of this feature — nothing
  ever reads it (not `KeyStore`, not the UI); removed as dead data rather than
  given a UI home.
- **`Router::recentEvents()` scales by construction, not by any explicit
  cap.** Events are appended to `data/sites/<id>/events/<AAAA-MM>.jsonl`
  (monthly files, never pruned), and the method walks files newest-first
  (`glob()` + `rsort()`) and lines newest-first within a file, returning the
  instant it has collected the requested limit (20, on `/site/{id}`). At
  100 000 accumulated events, that history is spread across ~100k/(events per
  month) monthly files; only the newest one or two are ever read for a normal
  page load, never the full history. The one real cost is `file_get_contents()`
  on whichever file satisfies the limit — bounded by how chatty *one month*
  gets, not by total lifetime volume. A single pathologically event-heavy
  month is the only scenario that would make a page load noticeably slower;
  nothing here paginates or lets an analyst browse further back than the
  newest 20 through the UI (only raw file access does).
- **`/site/{id}` dropped two sections** (2026-08-29): the per-extraction
  **Probes** column (which probe ran, its status) — redundant with opening
  the extraction itself, which shows the same thing in context — and the
  whole **Trends** table, which read up to 12 full extraction payloads off
  disk on every page load (`readExtractionPayload()` in a loop) for a table
  nobody was using. Both gone from `Router::sitePage()` too, not just the
  template — `probeRunsByExtraction()` is deleted rather than left as dead
  code, and the payload-reading loop with it (a real per-request I/O cost,
  not just an unused column).
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
- `HttpProbe::exposureCheck()` (2026-08-29, `data.exposure`, rules `X1`-`X6`):
  passive attack-surface checks — xmlrpc.php answering, REST API
  (`/wp-json/wp/v2/users`) and legacy (`?author=1` redirect) user
  enumeration, a browsable `wp-content/uploads/`, common exposed backup/config
  files (`.env`, `wp-config.php.bak`, `.git/config`, …), HTTP TRACE. Every one
  is a request an anonymous visitor could already make; this only automates
  the well-known targets. Detection logic is split into small pure static
  methods (`isXmlrpcEnabled()` etc.), unit-tested without network, same
  pattern as `parseMainResponse()`/`parseRobots()`. **Every check also carries
  its own `evidence`** (2026-08-29, user: "je voudrais pouvoir me référer à la
  source") — the exact URL requested and the HTTP status back, plus whatever
  specific detail justified the verdict (`Location` header for author
  enumeration, the actual usernames parsed out for REST enumeration via
  `extractUsernames()`, which of `SENSITIVE_PATHS` were tried vs found).
  Rendered right under each Exposure row in `extraction.php` as a small mono
  line — a finding here is never "trust me", it is a URL and a status code an
  analyst can re-run by hand with curl. **Skips the sensitive-files
  and directory-listing checks entirely on a soft-404 site** (`soft404Check()`
  — every path would answer 200 regardless of whether it exists, which would
  report all of them as "exposed"); `null` there means "not checked", and
  rule `X4` treats that as `unknown`, never as a false "clean". Adds ~11
  sequential requests to one `HttpProbe` run (no concurrency) — each bounded
  by the probe's own connect/read timeouts, so a slow site adds real wall time
  to `ingest:process`, same tradeoff the existing 5-request baseline already
  accepted. A known SSRF-class caveat shared with the rest of `HttpProbe` and
  `TlsProbe`: every request here targets `$site->host`, which comes from the
  payload's `home_url` — a legitimately-paired but compromised or malicious
  site could point it at an internal address and get this server to probe it.
  **A site not public at all is not the same fact as a site with nothing
  exposed** (2026-08-30, user: "pour tes vérifications de fichiers
  sensibles, tu dis que tout est ok. Ce n'est pas ce que c'est ok... c'est
  que le site n'est pas public. Méga nuance"). A site paired behind HTTP
  Basic Auth (a staging environment, an IP-restriction bypass, …) 401s on
  every path regardless of what is being tested for — before this fix that
  read as "not exposed" (false green) for xmlrpc/REST-enum/author-enum/
  sensitive-files/directory-listing/TRACE alike, exactly the "tout est ok"
  the user flagged. `collect()` now checks the **homepage's own** status
  first: a bare 401 there sets `authRequired`, and `exposureCheck()` short-
  circuits to `authGatedExposureResult()` (a pure, unit-tested method) —
  every field `null` ("not checked"), not a single request made, rather
  than let each check independently misread its own 401 as "no". Credentials
  are **configurable per site** (user: "ça doit être paramétrable au niveau
  du site"), not global: `KeyStore::setHttpAuth()`/`getHttpAuth()` store an
  optional `{username, password}` on the site's `keys.json` record, a new
  "HTTP Basic Auth for probing" panel inside `/site/{id}`'s existing "⚙ Site
  settings" `<details>` (POSTs `http_auth`/`http_auth_clear` to the same
  `/keys` mutation endpoint as rebind/revoke), flows into `SiteContext` via
  `Pipeline` (now takes an optional `KeyStore` and calls
  `SiteContext::fromExtractionPayload($siteId, $payload, $keyStore
  ?->getHttpAuth($siteId))`), and `HttpProbe` sends it as the Guzzle client's
  `auth` option — so with the right credentials configured, `authRequired`
  never trips (the homepage authenticates and the checks run normally) and
  without them, they cleanly read as unknown instead of a false pass. The
  extraction report's Exposure card replaces the whole six-row table with a
  single explicit banner when `auth_required` is true ("Site not public —
  checks below could not run… that is not the same thing as 'nothing
  exposed'") rather than six identical, unexplained "not checked" rows that
  would look indistinguishable from the pre-existing soft-404 skip.
  Regression tests: `HttpProbeTest::testAuthGatedExposureResultLeavesEveryCheckUnknown`,
  `tests/Storage/KeyStoreTest.php`.
  Gated behind needing a valid signed API key already, not otherwise
  mitigated (no private/loopback/link-local IP-range guard on the resolved
  host) — flagged in a 2026-08-29 security review, not yet fixed.
- `src/Rules/`: `Category` (16 short English codes), `Severity` (C/E/M/I),
  `Status`, `Pastille` (green/orange/red/blue/grey, derived from status+severity),
  `Check`/`CheckResult`, `Finding` (neutral), `Rule`, `RuleCatalog`, `RuleEngine`,
  `Context` (dot paths `payload.*` / `probe.*` / reference), `Translator`.
- `config/rules.php`: 74 rules. Ids follow the plugin's
  `.github/validations-techniques.txt` section letters; `W*` = domain (WHOIS),
  `PS*` = Lighthouse, `BV*` = BlogVault, `WF*` = Wordfence Intelligence, `X*`
  (2026-08-29) = passive exposure checks (see below) — none of these four
  prefixes exist in the source document, kept distinct so they never collide
  with a real section letter. Thresholds overridable via
  `rules.thresholds.<id>`. `BV2` and `WF1` are two **independent**
  vulnerability detectors (deliberately not deduplicated at rule level — see
  `merge_vulnerabilities()` below for the display-side cross-source
  attribution); a site can fail one, both, or neither.
- `config/lang/{en,fr}.php`: per-rule `title`/`fail`/optional `pass`, plus UI /
  status / severity / pastille / category labels.
- `src/Domain/SiteContext.php`: `fromExtractionPayload()` also carries
  `plugins`/`themes`/`wpVersion` (raw payload slices) — needed by
  `WordfenceProbe`, which is the only probe with no HTTP call of its own.
  `registrableDomain()` (2026-08-29) was "strip a leading `www.`, else use
  the host as-is" — correct only for a bare or www-prefixed domain. Any
  other subdomain depth (a hosting panel's own multi-level vhost alias, e.g.
  `latest.1.example.ca`) was queried against WHOIS/RDAP **verbatim as if it
  were the registered domain** — which always comes back empty, silently,
  since a well-formed-but-empty WHOIS/RDAP response isn't a probe error. This
  is what "domain data doesn't seem to work" turned out to be for a real
  tracked site, `RdapProbe`/`DnsProbe`'s NS/MX/CAA/DMARC lookups included
  (both read `$site->registrableDomain`; A/AAAA correctly still read
  `$site->host` directly, untouched by this). Fixed to default to the last
  two labels (handles arbitrary subdomain depth for an ordinary TLD) with a
  curated exception list (`TWO_LABEL_SUFFIXES`) of common ccTLDs that
  actually need three (`co.uk`, `com.au`, …) — a hand-picked set for this
  project's likely client base, not the full Mozilla Public Suffix List,
  which is thousands of entries and almost entirely the single-label case
  this already gets right by default. Extend the list as a real site
  surfaces a missing one rather than importing the whole PSL pre-emptively.
  **Already-completed extractions keep their stale probe data** — `done` is
  a frozen snapshot in this UI (only pending/queued/error extractions get a
  "Run analysis" button), so fixing this doesn't retroactively correct a past
  extraction's stored `probes/{dns,rdap}.json`; re-run `pipeline:run <site>
  <extraction> --probe=dns,rdap` by hand if a specific past one needs it, or
  wait for the next real extraction.
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
- `WordfenceIndex::search()` streams the same JSON Lines cache to power
  `/data/vulnerabilities`, the full-catalogue Datatables.net browser (not
  just what a site's own scan matched) — one sequential pass per request,
  flattening each component's vulnerabilities. The result is always sorted by
  version **descending** (highest patched version, or else highest
  affected-range upper bound; a `"*"` upper bound outranks everything as the
  most urgent/current case) before the page window is sliced off — this
  needs the whole *filtered* set (flat scalar rows, a few tens of MB worst
  case) buffered in memory, unlike the streamed per-component lookups
  elsewhere in this class; that is a different, much smaller cost than the
  243 MB full-decode below. Column sorting by click is still **not**
  supported (the order is fixed, not user-choosable), and the columns are
  slug-first, no source badge (2026-08-29: dropped — analysts didn't use it,
  and it crowded the row).
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
  slugs (`"woocommerce"`). `license_select()`'s dropdown (used on `/catalog`
  **and** the per-plugin row on an extraction report — 22 of them on one real
  report) **saves via `fetch()` now, no page reload** (2026-08-29, user:
  "la page recharge et c'est contre-productif" when classifying many plugins
  in a row) — a global `submit` listener in `layout.php` intercepts any
  `form.lic-form`, POSTs the same body the old real submit did, and flashes
  the select green on success. **The actual bug took a live Playwright test
  to catch, not code review**: the select's `onchange` called
  `this.form.submit()`, and `HTMLFormElement.submit()` is spec'd to skip
  firing a `submit` event *entirely* (unlike a real click on a submit
  button) — so the interceptor was correctly written and never once ran.
  Fixed by switching to `this.form.requestSubmit()`, which does dispatch a
  cancelable `submit` event. Verified live: watched the actual network
  request change from `document` (a full navigation) to `fetch`, and
  confirmed `data/catalog/software.json` was still written correctly with no
  page reload in between. `/catalog` has **two distinct filters**, easy to
  conflate: `?needs=1` (`needsLicense()`) is the *effective* license
  (`license`, or the wp.org `suggested` one when `license` is still
  `unknown`) being premium/mixed — an entry wp.org already suggests "free"
  for never shows there even with no decision recorded; `?unclassified=1`
  (2026-08-29) is the literal, unconditional "no decision recorded yet"
  filter (`license === 'unknown'`, regardless of what's suggested) — the one
  to work through the backlog with.
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
  `sources: ['blogvault']` / `['wordfence']` / `['blogvault','wordfence']` —
  rendered (2026-08-29) as **one small tag per source**
  (`vulnerability_source_badge()`), never a merged "BlogVault + Wordfence"
  label. `patched_version` on a merged entry is picked by
  `nearest_patched_version()` from *every* patched-version candidate either
  side lists, relative to the component's installed version — not "whichever
  a source's array happened to list first". A WordPress core fix commonly
  lands in several branches at once (6.4.5 *and* 6.5.2 for the same CVE);
  naming a branch behind the one installed reads as "downgrade to fix this".
  Preference order: lowest patch in the **installed branch**, at or above the
  installed version → lowest patch in **any branch above** it → if every
  known patch is already at or below what's installed, the highest one seen.
  `cvss_badge()` colours by rating, not just score — **critical** gets its
  own dark-red class (`badge-critical`, `--error-2`) distinct from **high**
  (`badge-error`, the ordinary red); a page full of both in the same shade
  hid exactly the distinction that matters most.
- **`src/Crm/`** (2026-09-02): a second, wholly separate data source — an
  external MySQL CRM/billing database (`clients`/`subscriptions`/`products`
  as license-or-maintenance-plan/`websites`/`website_items`/`website_tags`;
  schema supplied by the user, not designed here) that this app only ever
  **reads**. `ClientsDb::isConfigured()`/`fromConfig()` follow the exact same
  nullable-service pattern as BlogVault/Wordfence (`App::crmRepository()`
  returns `null` until `crm_db.host`+`database` are set in
  `config.local.php`) — deliberately, since a real connection was explicitly
  **not available yet** when this was built (2026-09-02) and every CRM page
  needed to degrade to a clean "not connected" placeholder rather than error.
  `ClientsRepository` is written in **portable SQL only** (plain
  JOIN/subquery/LIKE, no MySQL-only `GROUP_CONCAT(... SEPARATOR ...)` — that
  syntax differs from SQLite's) specifically so it could be fully unit tested
  against a SQLite `:memory:` fixture (`tests/Crm/ClientsRepositoryTest.php`)
  before any live database existed; aggregating a website's tags/linked
  clients is therefore a second query + a PHP-side merge, not `GROUP_CONCAT`.
  A product's type (**license** / **maintenance_plan** / **other**, the
  `/products` filter) is **derived** from whether a matching row exists in
  `swp_licenses`/`swp_maintenance_plans` (joined by `auto_id`), not read off
  the free-text `category` column, which the schema does not constrain to
  any fixed set of values. **Clients/websites/products/items are flat
  sibling routes — `/clients`, `/websites`, `/products`, `/items` — none
  nested under another** (corrected same-day, user: "notre business
  fonctionne par site web et non par client... rien ne devrait être sous
  client. Tout est une entité de même hiérarchie" — a tech identifies a
  website first and finds its client after, never the reverse, so an initial
  `/clients/websites`, `/clients/products`, `/clients/items` shape was wrong
  the moment it shipped). The nav section is labelled **"CRM"**, not
  "Clients" — with four equal siblings, naming the group after one of them
  would have re-introduced the same implied hierarchy. Route/method/template
  names all carry a `crm` prefix for the same reason (`crm_clients`,
  `crm_websites`, … ; `Router::withCrmRepository()`; `crm-clients.php`, …) —
  visibly one flat family, not "clients, plus three things under it". Routes
  and pages are named **"Websites"**, not "Sites" — `swp_websites` is a
  different concept than Xtractor's own `data/sites/<uuid>` (extraction-
  tracked sites), and reusing "Sites" would have collided with the existing
  nav item and with `/site/{id}`. `/websites/{id}`'s detail page is
  deliberately scoped to exactly what was asked (subscriptions + items split
  plugin/theme) and does **not** surface `swp_websites`' many BlogVault-sync
  operational columns (backup/security-scanner/firewall/monitoring/staging
  status) even though they're right there in the same row — same "snapshot
  vs. evolving operational status" test as the earlier BlogVault-scanner
  decision; those columns describe a live, independently-changing state, not
  something this read-only browser's job is to surface. `/items` (cross-site
  "which sites have which plugins" search) is **server-side** Datatables
  (`/items/search`, same shape as `/data/vulnerabilities/search`) because
  item count scales with sites × plugins-per-site, not with portfolio size
  like the other three CRM lists, which stay client-side. No cross-
  referencing to Xtractor's own extraction data was built (e.g. matching a
  `swp_websites` row to a `data/sites/<uuid>` by URL) — out of scope for what
  was asked; the two site concepts are browsable side by side, not merged.
  **Live-verified against the real database once real credentials arrived**
  (2026-09-02): every read method exercised against the real 315 clients /
  110 websites / 216 products / 3148 items, matching expectations (e.g. zero
  divergence between the real `category` column and the derived
  `product_type` across all 216 real products).
- **CRM write path** (2026-09-02, user: "je veux pouvoir changer le site lié
  à l'abonnement"): `ClientsRepository::setSubscriptionWebsite()` is the
  **one** place this app writes to the external CRM database — everything
  else stays read-only. Always a delete-then-insert into
  `swp_subscriptions_websites`, never an update-in-place: relinking a
  subscription is a new fact ("this website, as of today"), not a correction
  of the old link's `date_added`, and delete+insert is the one form both
  MySQL and SQLite execute identically (an upsert would need MySQL's
  `ON DUPLICATE KEY UPDATE` vs. SQLite's `ON CONFLICT ... DO UPDATE`, not
  portable — same reasoning as avoiding `GROUP_CONCAT` above). The write is
  exposed as `POST /subscriptions` (mutation-only, no GET page, same pattern
  as `/keys`/`/users`), reachable from a "Website" column added to the
  subscriptions table on **both** `/clients/{id}` and `/websites/{id}` (a
  subscription is the same entity shown in two contexts; the control belongs
  wherever it's listed). **Save is a real form submit + full page reload,
  deliberately not the auto-submit-on-change pattern `license_select()` uses
  for licence classification** (user: "la sauvegarde de ce changement doit
  être explicite") — this changes a real billing/service relationship, not a
  low-stakes local classification, so `subscription_website_form()` in
  `helpers.php` renders a visible Save button. The dropdown
  (`ClientsRepository::assignableWebsites()`) excludes every website tagged
  **"DEV"** (confirmed live against the real `swp_website_tags` table —
  exact casing) — a dev/staging copy, not a real client site a subscription
  should point at — but if a subscription is *already* linked to a
  DEV-tagged site, that site stays selectable in its own dropdown (labelled
  "(excluded)") so saving without touching the select can't silently change
  or clear a link nobody asked to touch. The exclusion is enforced
  **server-side too** (`setSubscriptionWebsite()` rejects a DEV-tagged
  target), not just hidden from the dropdown. Two new `/clients` filters
  support finding what needs fixing: a `service` dropdown
  (`active` = ≥1 subscription with `subscription_status = 'active'`;
  `inactive` = none — confirmed live the only real status values are
  `active`/`pending`/`on-hold`, so "inactive" means "nothing currently
  active", not a literal status value) and an `orphan` checkbox (≥1
  subscription with no linked website at all — 74 real orphan subscriptions
  across 10 clients, confirmed live, which is what motivated this feature).
  **This table may be a sync target from another system** (every table here
  carries a `date_sync` column) — if so, a manual fix here could be
  overwritten on the next sync run; worth confirming with whoever owns that
  sync before relying on this as a permanent correction. The write path was
  verified against the real database with a careful round-trip (assign an
  orphan subscription to a real site, confirm, then clear it back to exactly
  its original unlinked state) rather than left to unit tests alone, since a
  real write is the one thing a SQLite-only test suite can't fully vouch for
  against the real MySQL server.
- **Extraction page display fixes** (2026-08-29): §Hosting's cards
  (`.cards cards-full`) and §Security's "Hardening (constants)" card
  (`section(..., class: 'card-full')`, a 4th param added to `section()`) are
  full-width now, not sharing a row in the `.cards` auto-fit grid — a card's
  own content (a long extension/disabled-function list, dozens of constants)
  was getting squeezed into a half-width column for no reason. §Hosting's PHP
  card also **stopped truncating** Extensions/Disabled functions
  (`fmt_list(..., PHP_INT_MAX)`) — a live site had 67 disabled functions
  behind a "+N" that hid all but 12 of them, on a card with room to show
  every one. `field()`'s boolean rendering was still `oui`/`non` (French) —
  missed in the English-only chrome pass, silently breaking every boolean
  `field()` value on the page (TLS chain valid, self-signed, multisite,
  core-writable, …), fixed to `yes`/`no`. An inactive plugin/theme row gets a
  pale red wash (`tr.row-inactive`, `--error-bg`) — visible at a glance
  without reading the Status column. `requirement_cell()` (WP/PHP "Requires"
  on a plugin/theme row) now flags red only when the site's *actual* running
  version fails to meet it; plain muted text on a bare declared minimum told
  an analyst nothing site-specific. A component's vulnerability cell renders
  nothing at all when clean, not a green "—" badge (`$vulnCell` in
  `extraction.php`) — a badge suggests something was checked and passed, not
  "no data".
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
- `src/Web/`: `templates/` (sidebar `layout`, `sites`, `site`, `extraction`
  (redesigned 2026-08-29 — see UI section below; the pre-redesign version was
  kept for a while as `extraction-legacy.php` for comparison/rollback, then
  deleted 2026-08-31 once parity was long since verified), `catalog`, `users`,
  `data-wp-versions`, `data-php-versions`, `data-databases`,
  `data-vulnerabilities`; no `keys.php`
  — see the pairing bullet above) +
  `helpers.php` (incl. `merge_vulnerabilities()`, `vulnerability_source_badge()`,
  `fmt_refreshed()`, `report_icon()`);
  `public/admin/assets/style.css` (every page) + `report.css`/`report.js`
  (extraction report only — see UI section below).
- **`/data/*`** ("Data" in the sidebar): cross-site reports, browsable via
  vendored Datatables.net + jQuery (`public/admin/assets/vendor/`, no CDN,
  no build step — `layout.php` loads them only when a template sets
  `dataTables => true`); all four default to **50 rows/page**
  (`pageLength: 50`). Every one shows a **"Last refreshed"**
  banner (`fmt_refreshed()` in `helpers.php`, from each source's own
  `refreshedAt()` — a plain `filemtime()` on its cache file) so an analyst can
  tell whether the data is current without checking the filesystem; it turns
  `badge-warn` past a threshold matched to that source's own refresh cadence
  (2h for the hourly `reference:refresh` sources, 36h for the daily
  `wordfence:refresh`) and `badge-error` "Never refreshed" when the cache
  file doesn't exist at all. `databases` and `php-versions` (2026-08-29,
  split out of the old wp-versions page — see below) list the **release
  catalogue itself** — MySQL/MariaDB/PHP cycles from the local endoflife.date
  cache (`EndOfLife::cycles()`, refreshed by `reference:refresh`),
  client-side table (`databases` adds an engine filter, mysql/mariadb) always
  sorted by version descending — not a per-site report of what any tracked
  site is running. `wp-versions` (2026-08-29: reworked) is **not**
  endoflife.date-sourced anymore — it lists every *explicit* WordPress
  version from wordpress.org's own "stable check" service
  (`src/Reference/WordPressVersions.php`, same refresh cadence as
  `wordpress` in `reference:refresh`), each carrying wordpress.org's own
  verdict collapsed to a 3-state badge: `unsecure` (`insecure` — a security
  update exists for that branch, labeled red "Insecure"), `uptodate`
  (`latest`, labeled green "Up to date"), `secure` (everything else — an old
  but still-supported release, labeled **orange "Outdated"**, not "Secure" —
  the internal status name stayed `secure` since only the display label
  needed to stop reading like a recommendation). `EndOfLife::cycleFor()` is
  only cross-referenced for the **branch's own release date** ("Branch
  released" column — deliberately not an end-of-life date: WordPress
  branches don't have one the way PHP/MySQL/MariaDB do, every branch keeps
  getting security backports, so a "when did this branch start" fact is the
  useful one here), never for the status itself. **wordpress.org's own list
  is not exhaustive**: e.g. `6.5.1` is absent from `stable-check` — verified
  live against the API directly, not a caching or parsing bug on this side;
  wordpress.org occasionally omits a point release from this feed (it stayed
  installable, just isn't enumerated here) and there is nothing to reconcile
  it against, since this page's whole premise is "trust wordpress.org's own
  list verbatim." Client-side table, search restricted to the Version column
  only via an **explicit non-searchable list**
  (`columnDefs: [{ targets: [1,2,3], searchable: false }]`) — reported
  broken with the earlier `targets: '_all'` + a column-0 override (that
  pattern relies on Datatables applying later columnDefs entries on top of
  earlier ones for the same column, order-of-application that wasn't
  actually verified here before switching to the unambiguous explicit-list
  form), sorted by version descending.
  restricted to the Version column only (`columnDefs` `searchable:false` on
  the rest), sorted by version descending.
  `vulnerabilities` is the full Wordfence Intelligence catalogue (~84k rows,
  not just what matched a site) via Datatables' `serverSide` AJAX mode against
  `WordfenceIndex::search()` (see above) — slug-first columns, sorted by
  version descending server-side, no Source column.

## UI (approved design)

- **The extraction report got a full visual/structural redesign** (2026-08-29,
  user: "je veux amener ce qu'on a à un autre niveau totalement... impressionne-
  moi"). `templates/extraction.php` is now the *new* design; the previous
  flat-stacked-cards layout was kept byte-for-byte at
  `templates/extraction-legacy.php` (unrouted, reference/rollback only) until
  2026-08-31, then deleted — nobody still needed to compare. **Same data, same helpers**
  (`field()`/`field_raw()`/`section()`/`merge_vulnerabilities()`/etc. from
  `helpers.php` are untouched and still generate every value) — only the
  surrounding markup changed. Verified 1:1 parity against the legacy output
  before treating this as done: identical `<th>` label set (147/147), `<tr>`
  count (313), `.card.info-card` count (17), badge count (169),
  `license_select()` form count (22) — a redesign that quietly dropped a
  field would still pass `php -l` and the test suite, so this was checked by
  literally diffing both templates' rendered output for the same extraction,
  not just eyeballing it.
  - **Structure**: a hero band (bigger animated health ring, site identity,
    pastille tally, Print/`findings.json` actions) → a sticky in-page nav
    (`.xt-nav`, scrollspy via `IntersectionObserver` in `report.js`) → four
    **groups** instead of ten flat sections: *Overview* (KPI stat cards +
    findings table), *Infrastructure* (Account & plan, Domain & email,
    Hosting, WordPress — the exact domain→hosting→WordPress order the user
    asked for, now visually one cluster), *Content & Access* (Plugins &
    themes, Content & languages, Users), *Quality & Security* (Performance,
    SEO & analytics, Security & backup), then *Raw data*. Each group carries
    a distinct accent colour via a `--group-color` custom property set once
    on the group's wrapping element and inherited down to its icon badge,
    header underline, and every `.card.info-card`'s top border inside it —
    never redefined as a new global default.
  - **New, separate assets, loaded only on this page**
    (`Router::extractionPage()` sets `reportAssets => true`; `layout.php`
    includes `report.css`/`report.js` only when that flag is set): `public/
    admin/assets/report.css` and `report.js`. Deliberately **not** merged into
    `style.css`/the global inline script — every new class is prefixed `xt-`
    and scoped under `.xt-report`, so nothing here can affect `/site`,
    `/catalog`, `/data/*`, etc. (`site.php` also uses the shared `.kv` table
    class for its API-key card; scoping under `.xt-report` is what keeps this
    redesign from also restyling that unrelated page). The existing findings
    filter bar JS in `layout.php` (`.filt`/`.frow`/`data-filter`) is untouched
    and still drives the findings table's filtering — `report.js` only owns
    the nav scrollspy, the ring fill-in animation, and the print button.
  - **Icons**: `report_icon()` in `helpers.php`, a `match` of small hand-drawn
    inline SVGs built from basic primitives (circle/rect/line/arc), not an
    icon font or library — keeps the project's no-CDN, no-build-step
    convention (same reasoning as vendoring Datatables) and needs no licence
    check since nothing is copied from an icon set.
  - **Print support**: a "Print report" button (`data-print`, handled in
    `report.js`) forces every `<details>` open then calls `window.print()`;
    `report.css`'s `@media print` block hides the sidebar/topbar/sticky nav
    and avoids breaking a card or group across a page boundary. No PDF
    library — this is the browser's own print-to-PDF, which is enough for a
    client-facing report and adds no dependency.
  - `@property --p` (report.css) registers the health-ring's CSS variable as
    a real `<number>` so the browser can tween it — `report.js` sweeps it
    from 0 to the real score on load. A browser without `@property` support
    just shows the final value immediately; there is no error path to handle.
  - **v2 pass** (2026-08-29, same day — first pass "wasn't impressive enough"
    per direct user feedback): the hero became a dark cover-page band (radial
    accent glow + gradient, not the light `.card` treatment used everywhere
    else) carrying a **letter grade** (`health_grade()` in `helpers.php`,
    A/B/C/D/F on the same bands as `health_color()`) next to the ring, a
    **severity distribution bar** (`.xt-sevbar` — one segment per pastille,
    width proportional to its share of `counts.total`, colour reusing the
    existing `dot-red/orange/blue/green/grey` classes) and an
    **auto-generated verdict sentence** (`health_verdict()`, names the 1-2
    categories carrying the most red+orange findings — "Most issues that need
    attention are concentrated in Security and Versions & updates" on the
    live tracked site, not a static caption). Each of the three content
    groups also grew its own **pass-rate bar** in its header
    (`$groupRate()`/`$groupBadge()` closures in `extraction.php`, built from a
    `$groupCategories` map using the real `Rules\Category` constants — finally
    a use for that import, unused since the original template) — computed
    from `$all` findings whose `category` falls in that group, `null` (renders
    nothing) when no applicable category has any pass/fail finding yet, never
    a fake "0%". **Parity re-verified the same way as the first pass** after
    every one of these additions — 147/147 `<th>` labels, 313 `<tr>`, 17 cards,
    169 badges, 22 licence forms, still identical to `extraction-legacy.php`
    — a visual pass adding this much markup is exactly the kind of change
    that could silently drop a field without that check.
  - **Two live-screenshot bugs found and fixed the same day, neither visible
    from reading the code or from the parity check above** — this project had
    no way to actually *see* the page render until a headless Chromium got
    installed locally purely to QA this redesign (`npx playwright install
    chromium`; the `--with-deps` system libraries needed root this box
    doesn't have, so they're `apt-get download`ed as `.deb`s and extracted
    with `dpkg-deb -x` into a local prefix instead — no `sudo`, fully
    user-space, see any future session's shell history/scratchpad for the
    exact package list if this needs reproducing). Both bugs were invisible
    to `php -l`, the test suite, and the HTML-diff parity check, because both
    are about what a *browser* does with otherwise-valid markup:
    1. Visiting `#overview` (the sticky nav's own first link!) scrolled the
       **hero itself out of view** — anchor-scroll brings its target to the
       top of the viewport, and the KPI/findings block that owned
       `id="overview"` sat *below* the hero, so arriving via that anchor (or
       clicking "Overview") skipped the grade/ring/verdict/severity-bar
       entirely and landed on what looked like a bare admin table. This is
       almost certainly why the redesign read as unimpressive on first look —
       the actual hero was never seen. Fixed by moving `id="overview"` onto
       the hero itself and decoupling the scrollspy target from it:
       `data-nav-target` now carries the group name as a *value*
       (`data-nav-target="overview"`) that `report.js` reads directly,
       instead of matching on `entry.target.id` — so the hero can own the id
       every link/URL should land on while the KPI/findings block (a
       different, lower element) is still what scrollspy highlights "Overview"
       for once scrolled past the hero.
    2. The scroll-reveal fade-in (`.xt-reveal`, `opacity:0` until an
       `IntersectionObserver` added `.in`) left **entire groups invisible**
       whenever the observer didn't fire before something looked at the page
       — confirmed with a `fullPage` screenshot taken without manually
       scrolling first: Infrastructure/Content & Access/Quality & Security
       were blank white space, not merely unstyled. A report whose findings
       can silently fail to render is worse than one with no animation at
       all, so the feature was removed outright (`.xt-reveal` deleted from
       both the markup and `report.css`; `report.js`'s reveal observer
       deleted) rather than patched with a safety-net timeout — the ring
       animation, scrollspy, and print button carry the "this page has some
       polish" job without that risk.
    Lesson for next time: **a template-parity diff is necessary but not
    sufficient for a visual redesign** — it catches a dropped field, not a
    browser behaviour (anchor scroll position, an observer that never fires)
    that hides a field which is still right there in the DOM. Screenshot it
    before calling a visual change done.
  - **v3 pass** (2026-08-30, user feedback on the F grade and the verdict
    sentence): `health_verdict()` — the auto-generated "Most issues that need
    attention are concentrated in X and Y" sentence — is **gone entirely**
    ("je crois qu'on devrait retirer ce concept... trop de gestion"), function
    and its tests deleted rather than left unused. In its place,
    `health_score_breakdown()` renders the **exact arithmetic** behind the
    score with this extraction's own numbers plugged in (red count × 0.9
    exponent = factor, orange count × 0.97 exponent = factor, the
    multiplication, the resulting grade band) inside a `<details>` right next
    to the grade badge (`.xt-hero-explain`) — asked for explicitly: "j'ai
    besoin d'avoir les détails précis pour être d'accord ou non." Caught its
    own bug live: the breakdown table's values were unreadable (light hero
    text colour inherited onto a white popover background) until
    `.xt-score-breakdown` got an explicit `color: var(--text)` — another one
    only a screenshot caught, not a code read. The "Analytics & tracking"
    pending-note card in §SEO & analytics is removed outright (user: "enlève
    ça") rather than left as a stub — unlike the other "coming soon" cards,
    there is no plan to build this one.
  - **v4 pass — data provenance** (2026-08-30, user: "on développe un tool
    d'expert... il faut savoir d'où viennent les données et ce qu'elles
    représentent. Chaque donnée doit pouvoir être expliquée" — triggered by
    asking what "page cache: yes" even means). Every value on the report must
    carry, on demand, exactly where Xtractor got it and whether Xtractor
    verified it itself or is only relaying what the plugin claimed. Built as
    `src_note(string $path): string` in `helpers.php` rather than ~150
    one-off sentences: it `match`es the dot-path *prefix* (`payload.` /
    `probe.dns.` / `probe.tls.` / `probe.rdap.` / `probe.http.` /
    `probe.pagespeed.` / `probe.wordfence.` / `probe.blogvault.` / `catalog.` /
    `reference.eol.` / `derived.`) against one of eleven category-level
    sentences, so adding the marker to a new field is one string, not one
    essay. `field()`/`field_raw()` take it as an optional 4th `$source` param
    and, when given, append a quiet "ⓘ" (`.xt-src` in `report.css`) whose
    native `title` tooltip carries the exact path plus the sentence — no JS,
    keyboard-reachable via `tabindex`. Applied across essentially every
    `field()`/`field_raw()` call in `extraction.php` (98 of 105; the 7 left
    bare are genuinely source-less: `DKIM` and `Hosting provider` are
    hardcoded placeholder text, not data, and the empty-constants fallback
    has nothing to point at). Two things matter about the *distinction* it
    draws: `payload.*` explicitly says "shown as received; Xtractor does not
    independently re-measure it" — `probe.*` says "verified/observed/fetched
    live by Xtractor during this extraction". That is the literal, complete
    answer to "page cache: yes, based on what?": it is
    `payload.object_cache.page_cache`, rendered by `field()` with **zero**
    transformation — Xtractor did not compute or verify it, it is printing
    the plugin's own collector output as-is (see the still-open question a
    few bullets up about what the plugin's collector itself actually checks).
    The Exposure card (xmlrpc/REST-enum/author-enum/sensitive-files/
    directory-listing/TRACE) predates this and already shows the literal
    request URL + HTTP status per check inline — a stronger, per-instance
    form of the same idea — so it was left as-is rather than downgraded to a
    generic marker. Tests: `tests/Web/HelpersSrcNoteTest.php` (7 cases).
- **Select2, in AJAX mode** (2026-09-02, user: had used it before, asked if
  it's still current — yes, actively maintained, 4.1.0 is the latest stable
  as of this check). Vendored the same way as jQuery/Datatables
  (`public/admin/assets/vendor/select2/`, no CDN, version-pinned,
  SRI-verified against cdnjs on download). **First pass preloaded the full
  option list (all 315 clients, ~100 websites) into the page and let Select2
  filter it client-side — wrong, per direct correction: "je veux les select2
  avec du ajax. C'était ça le point."** The distinction matters at this
  data's actual scale: preloading is a rendering annoyance past ~20 options,
  but a genuine, growing per-page-load cost past a few hundred — searching
  server-side means a page never carries more than the one currently-selected
  option (if any), and everything else is fetched as the operator types.
  Wiring: a `<select class="js-select2" data-ajax-url="/clients/search"
  data-placeholder="All clients">` carrying **only** its current selection as
  a real `<option>` (plus an empty placeholder option); `'select2' => true`
  on that page's `render()` call (same pattern as `dataTables`); one shared
  init in `layout.php` reads `data-ajax-url`/`data-placeholder` off each
  `.js-select2` element and wires select2's `ajax` option (`data: {q:
  params.term}`, `minimumInputLength: 0` so opening the dropdown shows an
  initial page of results before typing) — no page-specific JS needed
  anywhere. Three JSON search endpoints back this, all sharing one small
  `Router::crmJsonSearch()` body (same "not configured" / query-failed
  handling as `withCrmRepository()`, but returning select2's default
  `{results: [{id, text}, ...], pagination: {more: false}}` shape instead of
  rendering a page — no real pagination needed at this row count, so `more`
  is always false): `/clients/search` (`ClientsRepository::searchClients()`
  — email/name/company `LIKE`), `/websites/search`
  (`searchAssignableWebsites()` — same DEV-tag exclusion as
  `assignableWebsites()`, which stays as the *unfiltered* list used only for
  `setSubscriptionWebsite()`'s server-side validation), `/websites/tags/search`
  (`searchTags()`). This also **removed** the "current selection excluded
  from assignment" special case an earlier version needed (keeping a
  DEV-tagged current link selectable so saving without touching the dropdown
  couldn't silently clear it) — with nothing preloaded to match against,
  the current option is simply always rendered as-is, DEV-tagged or not,
  until deliberately changed. `dropdownParent: document.body` is set
  unconditionally in the shared init — several of these selects live inside a
  `<table>`, and `table { overflow: hidden }` (the rounded-corner rule) would
  otherwise clip the dropdown to the row it opens from. jQuery loading was
  decoupled from the `dataTables` flag it used to be tied to (now loaded once
  whenever either `dataTables` or `select2` is requested) so a page needing
  only one of the two — e.g. `/clients/{id}`'s subscription-website selects,
  no Datatable there — doesn't load jQuery twice. Retheming is a small CSS
  block in `style.css` (`.select2-container--default...`) mapping Select2's
  default blue onto this app's `--accent`/`--border`/`--surface` tokens.
  Applied: the tag/client filters on `/websites`, and the "linked website"
  select in `subscription_website_form()` (used on both `/clients/{id}` and
  `/websites/{id}`) — not applied to small selects (role, licence, service
  filter, item type: all under ~5 options) where AJAX would add a network
  round-trip for no benefit. Verified live against the real database: all
  three search endpoints return correctly-shaped results for a real partial
  query (`/clients/search?q=al`, `/websites/search?q=talents`,
  `/websites/tags/search?q=dev`), and every rendered page's `data-ajax-url`
  attributes point where expected.
- **CRM UI pass 2** (2026-09-02, a large batch of user feedback, item by
  item):
  - **Nav reorder**: sidebar now opens unlabeled with the four CRM
    entities (Websites, Items, Clients, Products — no "CRM" header), then
    **"Receptor"** (was "Monitoring"; "Sites" inside it renamed
    **"Extractions"** — fixed via `config/lang/{en,fr}.php`'s `ui.sites` key
    rather than hardcoding, since `sites.php`'s own `<h1>` reads the same
    key and picked up the rename for free), then **Data** (unchanged), then
    **Management** (Users + Sign out — was "Account").
  - **`SiteDisplay::of()`** (`src/Support/`, wrapped by `helpers.php`'s
    `site_display()`): strips a URL's scheme and leading "www." for display
    ("https://www.rds.ca" → "rds.ca") — never for an `href` or an AJAX
    target. A plain autoloaded class, not only a template helper function,
    specifically so `Router.php` can call it too (its `title`/breadcrumb
    strings are built before `helpers.php` is guaranteed loaded — the same
    constraint that already has `Router.php` use `htmlspecialchars()`
    instead of `e()`). Applied everywhere a site shows as text or in a
    dropdown: website list/detail, client's subscription rows, the
    `/websites/search` and `/items/search` AJAX results.
  - **`/clients` list**: "Last synced" moved to the top as
    `fmt_relative_time()` ("18 hours ago", exact ISO date in a `title`
    tooltip — a new general-purpose helper, not client-specific) sourced
    from `MAX(date_sync)` across the table
    (`ClientsRepository::clientsLastSyncedAt()`). Default filter is now
    **active** clients, not all — distinguished from an explicit
    `?service=all` by defaulting the `service` param to `'active'` only
    when *absent* from the URL, so the three real states (active/inactive/
    all) never collapse into an ambiguous empty-string case. A warning
    banner ("N subscriptions not linked to a site — view them") now shows
    **unconditionally** via `countOrphanSubscriptions()`, computed
    independently of whatever filter is currently applied — the point is to
    surface it even while looking at a filtered subset that happens to hide
    every orphan.
  - **`/clients/{id}`**: the Client card is 2-column (`.kv-cols-2`, CSS
    `column-count`) to use less vertical space. Email carries a
    `copy_button()` (pure `navigator.clipboard`, no library). Teamwork/
    HubSpot/BlogVault ids are `external_link()`-wrapped — clickable when
    `config/config.php`'s new `external_links.*` pattern is configured
    (`{id}` substituted), plain text otherwise, **never** a link to
    nowhere. An "Edit" button on the card (via `section()`'s existing
    `$badge` slot) and one per subscription row use
    `external_link_button()` — same idea, but renders **nothing at all**
    (not inert text) when unconfigured, since an action button with
    nowhere to go isn't worth showing. `wordpress_edit_user`'s `{id}` is
    the CRM client id for now — **unconfirmed** whether that's actually
    what the WordPress side expects; flagged in the config comment.
  - **`/websites` filters standardized**: tags are now a **select2
    multiple** (`tag[]`, OR-matched — a site carrying *any* selected tag
    qualifies, not all of them — `ClientsRepository::listWebsites()`'s
    `$tags` param). Host and Client(s) columns dropped from the table
    (`clientsByWebsiteIds()` removed as dead code now nothing renders it —
    `getWebsite()`'s equivalent need is served by the new, richer
    `clientsForWebsite()` instead, added for the detail page's client
    card). New **Connection** filter (`CONNECTED`/`DISCONNECTED` — the
    exact real values, confirmed live against `swp_websites`).
  - **`/websites/{id}`**: new "View in BlogVault" button
    (`external_link_button()`, `blogvault_view_website` pattern,
    `blogvault_site_id`). New Client card(s) (company/first/last/email +
    copy button + "View client" link) via `clientsForWebsite()` — a website
    isn't guaranteed exactly one client by the schema, so this is a loop,
    usually rendering once. The subscriptions table's **Client** column is
    gone (redundant with the new card); **Next renewal** is date-only now
    (`substr(..., 0, 10)` off the `YYYY-MM-DD HH:MM:SS` value — confirmed
    that's the real format live, not a guess).
  - **"Linked website" is display-only by default**, a ✎ edit icon reveals
    the select2 dropdown (`subscription_website_form()`, both pages) — a
    real, if narrow, JS design problem: select2 initialized against a
    `display:none` element can't measure it correctly, so the reveal is
    **lazy** (select2 constructed the first time the form is shown, not
    eagerly with the page's other selects — this control's `<select>`
    deliberately carries `.wf-select`, not `.js-select2`, to opt out of the
    eager init loop). Toggling uses `element.style.display` directly on
    both the display span and the edit form, never the `hidden` attribute —
    an inline `style="display:flex"` on the *same* element as `hidden`
    would silently defeat it (equal CSS specificity; the actual winner
    would depend on stylesheet source order). Cancel resets the select back
    to its original value before hiding, so a later re-open can't show an
    abandoned, never-saved pick as if it might be current.
- **Every Datatable requires an explicit "Search"/"Filter" click, never a
  live filter on keystroke or dropdown change** (2026-09-02, user: confirmed
  this covers DataTables' own native quick-search box too, not just a
  page's custom filter-bar controls — applied across all 8 tables in the
  app, not only the CRM ones). Mechanism: every `DataTable()` call now
  passes `dom: '<"dt-top">rt<"dt-bottom"lip>'` — the classic (still
  supported in DT 2.1.8, though newer `layout` is the documented-preferred
  option) dom-string form, chosen over `layout` here since its
  array-per-slot composability wasn't something to risk getting wrong
  without a browser to verify against. This drops the native box entirely
  and moves length+info+pagination together into one bottom bar (also
  satisfies "Entries per page toujours en bas" for free — it was
  top-left by default before). `helpers.php`'s `dt_search_box()` renders
  the replacement input+button; `layout.php`'s shared `initExplicitSearch(
  tableId, dt)` wires it to call `dt.search(val).draw()` only on click or
  Enter — the underlying DataTables search *feature* (and, for
  server-side tables, the `search[value]` sent to the AJAX endpoint) is
  unchanged, only the trigger is, so no backend endpoint needed touching.
  Two tables (`/items`, `/data/databases`) already had their own
  dropdown/checkbox filters that used to auto-apply `.draw()` on
  `change` — both rebuilt around **one shared "Filter" button** governing
  every control in that bar together (text + dropdown(s) + checkboxes),
  rather than mixing that with the separate native-box-replacement pattern,
  which would have left two different "how do I apply a filter" triggers on
  one page. Verified live: all 8 pages render clean (no PHP warnings), each
  carrying its explicit search control and the `dom` option.
- **`UserStore` gained full CRUD + suspend** (2026-09-02, user: "je veux
  pouvoir créer, éditer, supprimer, et suspendre un utilisateur"). Schema
  grew from `{email, role}` to `{email, role, first_name, last_name,
  status}` — both older file shapes (the original flat email list, and the
  two-field `{email, role}` form this project shipped the same day) are
  read transparently and default the new fields (`first_name`/`last_name`
  `''`, `status` `STATUS_ACTIVE`) so nobody's access changes on upgrade,
  same discipline as the original legacy-list migration.
  **`isAllowed()` now means "listed **and** active"** — the one method
  `Router::currentUser()` re-checks on every request and the OAuth callback
  checks at sign-in, so suspension takes effect immediately, same as
  removal already did. A new `exists()` (listed regardless of status) is
  what `add()`/`updateUser()` use for duplicate-detection instead, since
  `isAllowed()` would have let a second record for an already-suspended
  email slip through. **The "last admin can't be removed" invariant became
  "the last *active* admin can't be removed, demoted, or suspended"**
  (`hasOtherActiveAdmin()`, replacing the old role-only `hasOtherAdmin()`):
  a second admin record that exists but is itself suspended doesn't count,
  since nobody could sign in as them either — checked in `remove()`,
  `updateUser()` (demoting an admin), and the new `setStatus()` (suspending
  one). `setRole()` is gone, folded into one `updateUser($email, $newEmail,
  $firstName, $lastName, $role)` that can rename the email itself too (one
  of the four fields the user asked to be able to edit) — validated against
  the same "not already taken by someone else" check `add()` uses.
  **`/users`** (`Router`'s `edit`/`suspend`/`reactivate` POST actions,
  alongside the existing `add`/`remove`) renders each row **display-only by
  default with a ✎ edit icon revealing an inline form** — the same idea as
  `subscription_website_form()`, generalized into a plain (no select2)
  `.row-display`/`.row-edit-form`/`.row-edit-btn`/`.row-cancel-btn` toggle
  in `layout.php`'s shared click handler (kept as a **separate** class set
  from the `wf-*` one — a shared style rule can target both by listing both
  classes, as `style.css` does, without ever making one button trigger the
  other's handler). The edit row is a second `<tr>` (a colspan'd `<form>`),
  toggled via `style.display = 'table-row'`/`'none'`, not `'flex'` like the
  subscription form's — a `<tr>` needs its own display value. The sole
  active admin's row shows no Suspend/Remove control at all (computed
  per-row in the template, mirroring `UserStore`'s own protection — belt
  and suspenders, the server-side check is still authoritative). Verified:
  a rendered 3-user fixture (one sole active admin, one active
  maintenance, one suspended coordinator) produces exactly the expected
  button set per row, no PHP warnings.
- **Re-audit the same day found three real issues, fixed** (2026-09-02):
  1. The "Management" nav section (Users link) was gated on
     `!empty($currentUser)` — user, direct challenge: "pourquoi ça me prend
     un user pour voir le menu? ... valider qu'on est un user, ça sert à
     quoi?" Correct call: that check protects nothing — `/users` already
     gates every real action on `$isAdmin` server-side regardless of nav
     visibility, so hiding the *link* only when nobody happened to be signed
     in made a legitimate page undiscoverable for zero security gain (and is
     exactly how "where are the Users?" happened, since Google Sign-In isn't
     configured on this install yet). Fixed: "Users" is unconditional now,
     like every other nav item; only "Sign out" stays behind `currentUser` —
     that one's condition is real (nothing to sign out of otherwise).
  2. `.xt-dt-search`/`.xt-dt-top`/`.xt-dt-bottom` — the explicit-search CSS
     classes above were originally named `dt-search`/`dt-top`/`dt-bottom`
     without the `xt-` prefix, and `dt-search` collides with a class
     DataTables 2.1.8 uses **internally** for its own native search box
     (`search:{container:"dt-search",...}` in the vendored source). Harmless
     today only because `dom` excludes `'f'` so DT's own box never renders —
     confirmed via datatables.net's own reference docs (fetched live) that
     `dom` is deprecated-but-still-functional in 2.x, not actually broken, so
     that part of the design held up under scrutiny. Renamed anyway to close
     the fragility: a future dom string that ever re-adds `'f'`, or a
     DataTables upgrade, would otherwise silently apply this project's own
     search-box CSS to DataTables' native one too.
  3. `UserStore::admin()` — its own docblock claimed "the first *active*
     admin", but the implementation never checked `status`, and it had
     become fully unused (dead code) since the `users.php` rewrite computes
     per-row admin protection directly instead of relying on one email. That
     mismatch could have bitten later if some new caller trusted the
     docblock; removed rather than fixed-then-left-unused, matching this
     project's standing "remove dead code" rule.
- Sidebar shell, **orange accent `#f26f2b`**, cool-slate neutrals, **light
  only** (dark mode removed 2026-08-29 — was a half-finished toggle nobody
  used; `:root` in `style.css` carries the one palette, no `@media
  (prefers-color-scheme)` or `[data-theme]` variants), mono for data/ids.
  Brand is text only ("**SatelliteWP** Xtractor", no icon).
- **UI chrome is English-only** (2026-08-29): the `?lang=en|fr` topbar toggle
  was removed along with every hardcoded French string in the chrome
  templates — it was half-wired (most literal French text never routed
  through `Translator`, so switching languages did nothing for it) and
  confusing to look at. `Translator`/`config/lang/{en,fr}.php` are untouched
  and still render **findings/rules content** (the extraction report) in
  either language via `Context`/`Rule` — that FR/EN equivalence is kept
  deliberately, ahead of a planned feature where the *extraction itself* is
  done in French or English and the report should follow. `Router::locale()`
  still honours `?lang=` if passed, it just has no visible control anymore.
- Extraction page: **nothing below the "Analysis" card renders until
  `status === 'done'`** — pending/queued/running/error each show only a status
  message (and the "Run analysis" trigger, for pending/queued); an analyst
  must never read partial or stale data as if it were a finished report. Once
  done: **overview** (health ring = `100 × 0.9^red × 0.97^orange`,
  `health_score()` in `helpers.php` — **not** the flat `100 − red*8 − orange*3`
  this used to be: that clipped to exactly 0 for any site with ~13+ red
  findings, which a real tracked site hit on *every single extraction*
  (9-12 red, 19 orange each time) with zero differentiation between "quite
  bad" and "catastrophic" — reported 2026-08-29 as "the health meter doesn't
  work at all", which is what it looked like. The decay approaches but never
  actually reaches 0 for a realistic finding count; semantic
  ring colour; pastille tally; KPI tiles) → **findings** full list filterable
  by type → **10 sections** with COMPLETE data (e.g. TLS 1.0/1.1/1.2/1.3
  independently, full plugin info) → raw (collapsible).
- The 10 sections, in this order (2026-08-29, reordered — see below): Account
  & plan · Domain & email · Hosting · WordPress · Performance · SEO &
  analytics · Plugins & themes · Content & languages · Users · Security &
  backup. **Every datum has a home**; not-yet-collected ones show a
  "coming soon" note.
- **Section order and card placement fixed** (2026-08-29, user feedback: "je
  différencie nom de domaine et courriel, hébergement web puis WordPress...
  tu as mixé des crons et de la cache, ça ne fait pas de sens"). WordPress
  moved from 6th to 4th (right after Hosting, matching that
  domain-email → hosting → WordPress mental model) — nothing else reordered.
  Two card-level fixes: the old "Cache & cron" card in §Performance mixed a
  performance concern (autoload/object cache/page cache — kept in
  §Performance as **"Cache"**) with a WordPress-internals concern (WP-Cron,
  overdue/scheduled events — moved into §WordPress as a new **"Cron"** card);
  and **"Security headers"** was sitting in §Performance (it measures
  security posture, not speed) — moved into §Security & backup, next to
  Hardening/Filesystem/Exposure where the other security signals already
  live.
- **"Email (DNS)" card split** (2026-08-30, user: "Email et DNS, ça ne
  devrait pas être dans la même section... ça n'a rien à voir"). The card mixed
  genuine mail-authentication records (SPF, SPF record, DMARC, DKIM, MX) with
  two general DNS records that have nothing to do with email: CAA (which CAs
  may issue a certificate for the domain) and A/AAAA (the site's own IP
  addresses). §Domain & email's card is now **"Email"**, mail-only. CAA moved
  into the SSL/TLS card in §Hosting (it governs certificate issuance — sits
  naturally next to issuer/expiry/chain-valid) and A/AAAA was merged into the
  Server card's existing single-address field, renamed **"IP address (A /
  AAAA)"**, replacing the old `$dns['a'][0]`-only value with the full record
  list — avoiding showing the same DNS data twice on the page under two
  different labels.
- **Pastilles replace severity in the display**: green = pass, red = fail (C/E),
  orange = fail (M), blue = info, grey = n/a/unknown.
- `design/dashboard-proposal.html` = the standalone style mockup.

## CLI (`bin/xtractor`)

`ingest:process` · `pipeline:run` · `probe:run`/`probe:list` ·
`rules:evaluate [--lang]`/`rules:list` · `reference:refresh [--product=…]`
(cron: **hourly** for wordpress/php/mysql/mariadb, feeds `/data/wp-versions`,
`/data/php-versions` and `/data/databases`) · `wordfence:refresh` (suggested
cron: **daily**) ·
`catalog:list [--needs-license]`/`catalog:set`/`catalog:suggest` ·
`keys:add [--origin]`/`list`/`revoke`/`rebind` (also manageable from each
site's own page in the web UI, `/site/{id}` — see below) · `users:add` (seeds the web allowlist; no argument
lists it) · `sites:list` · `extractions:list` · `index:rebuild`.

## Testing

`composer test` — 359 tests, no network. Manual end-to-end: `docs/TESTING.md`.
`phpunit.xml.dist` excludes the `network` group, reserved for any future
live-probe tests; none exist yet, so the suite runs fully offline.
`composer analyse` — PHPStan, see "Conventions" below.

## Conventions

`declare(strict_types=1)` everywhere; typed params; English docblocks; PHPUnit
`#[DataProvider]` attributes. Commit messages end with the `Co-Authored-By` line.
**PHPStan** added 2026-08-31 (`composer analyse`, level 6, `src/` + `bin/`) —
`src/Web/templates/*` excluded (`extract($vars, EXTR_SKIP)` in
`Router::render()` makes every template variable read as "might not be
defined", which isn't a real finding). `phpstan-baseline.neon` snapshots 13
pre-existing warnings, all the same harmless shape (`array_values()` on a
value a PHPDoc already types as a list, `is_array()` already narrowed by a
PHPDoc, one genuinely-defensive `?:` fallback on a stub-typed core function)
— fixing any of them for real would mean weakening a correct type annotation
or removing legitimate defensive code, not fixing a bug, so they're baselined
rather than "fixed" to make the count read zero.

## Config / secrets

`config/config.php` (committed) + `config/config.local.php` (gitignored):
`pagespeed.api_key`, `blogvault.api_key` and `wordfence.api_key` live there;
base URLs / timeouts / auth schemes are non-secret and stay in `config.php`.

## Pending / next steps

- ~~`payload.object_cache.page_cache`'s exact detection logic is unverified~~
  **Resolved 2026-08-30** — confirmed straight from the plugin's actual
  source, now readable at
  `/home/extractor/webapps/wpsite/public/wp-content/plugins/satellitewp-plugin-maintenance`
  (see the corrected golden rule above; the guess that it was called
  `ObjectCacheCollector` was right). `src/collectors/class-object-cache-collector.php`:
  ```php
  'object_cache' => array(
      'external'   => function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : false,
      'dropin'     => isset($dropins['object-cache.php']),
      'page_cache' => isset($dropins['advanced-cache.php']),
  ),
  ```
  `$dropins = get_dropins()` — WordPress core's own function. `page_cache` is
  **purely "does the file `wp-content/advanced-cache.php` exist"**, via
  `get_dropins()`'s file-presence + header check — it does **not** check
  `WP_CACHE`. This fully explains the earlier puzzle (a real tracked site
  showing `page_cache: true` with `WP_CACHE` constant `false`): WordPress
  core only actually *loads* that drop-in when `WP_CACHE` is true, so a site
  can have the file sitting in `wp-content/` (left behind by a caching
  plugin that was deactivated, or never wired up) while `page_cache: true`
  reports a drop-in that isn't actually active. Not a bug on either side —
  the field measures file presence, not "is a page actually being served
  from cache right now"; worth remembering next time this field looks
  inconsistent with `WP_CACHE`.
- **Vulnerabilities are rendered** (§WordPress core + §Plugins & themes: a
  "Vulnérabilités" column per component, a merged CVE table tagged BlogVault /
  Wordfence / both). The *non*-vulnerability BlogVault detail — scanner
  status, firewall mode, backup/snapshot state, and (on a hacked site) the
  `data.scanner.remediation` block — is **not** coming: decided out of scope
  2026-08-31 (see the "snapshot in time" golden rule above), and the
  "Integrity, malware & backup" placeholder card in §Security & backup was
  removed from `extraction.php` rather than left as a stub.
- Wordfence's **production** feed shape is now confirmed live (2026-08-31,
  `wordfence:refresh`: 39 455 vulnerabilities received) — the assumed shape
  from Wordfence's own wordfence-cli source (`cve_id`, nested `cvss:
  {rating, score}` flattened by `refresh()` into `cvss_rating`/`cvss_score`,
  `patched`/`patched_versions`, `affected_versions`) matched a real response
  field-by-field; `merge_vulnerabilities()`'s cross-source attribution can
  now actually fire (needs `production` populated — see the bullet below).
  The **scanner** variant's shape — `id`, `title`, `software[]`,
  `informational`, wildcard `"*"` version bounds — was already confirmed
  separately. One correction to an earlier assumption: this same refresh had
  `scanner` 429 (rate limited) while `production` succeeded, so the two
  feeds' rate limits are **independent**, not shared as previously guessed —
  a 429 on one does not mean the other is also exhausted, worth retrying
  separately rather than assuming both are dead for the day.
- **Plugin data now has a home in the UI** (2026-08-29): `post_types` /
  `post_type_count` (§Content & languages, "Content types" table),
  `mu_plugins` / `dropin_plugins` (§Plugins & themes, "Must-use & drop-in
  plugins" table), `auto_update_plugins` / `plugin_updates` / `theme_updates`
  (§Plugins & themes tables — Auto-update column, and the authoritative
  update-available list backing the Update column alongside `new_version`),
  `super_admins` (§Users, "Super admins (network)"), `connectors.woocommerce`
  (§Content & languages, "Commerce (WooCommerce)" card, alongside the
  already-shipped WPML languages card), `filesystem.permissions` (§Security &
  backup, "File permissions" table), `is_backend_ssl` (§Hosting, SSL/TLS
  card), `multisite_type` / `multisite_count` / `multisite_sites_status`
  (§WordPress, shown when `is_multisite` is true). `database.transients` and
  `is_multisite` itself were already shown (this list had gone stale on those
  two). `_errors` (per-collector failures) will never need a UI home: removed
  plugin-side entirely 2026-08-31 (see the golden rules and the `_errors`
  bullet further down) rather than ever having its shape confirmed.
- **§Account & plan client/care-plan data — checked live 2026-08-31, does not
  exist where hoped.** WooCommerce platform data was rejected as the source
  (same "snapshot, not evolving status" test as the BlogVault
  scanner/firewall bullet above). The alternative — BlogVault's own
  `GET /sites/{id}` (already called by `BlogVaultProbe`, passing the account
  key + that site's BlogVault site id) — was checked against a **real, full,
  unfiltered response** before building anything against it (this project's
  standing rule; same discipline as the Wordfence bullet below). The real
  response has no care-plan or renewal concept at all: only `client` (an
  optional, often-null link to a BlogVault-side client record) and `tags`
  (free-text labels — `"DEV"`, `"MANUEL"` on the one real site checked).
  BlogVault has no way to know about a SatelliteWP-specific care plan or
  renewal date — that is this reseller's own business layer, not something
  any BlogVault endpoint can carry. **Decided: drop it, look for another
  source later** — no card was built rather than one showing mostly-null
  fields under a "care plan" heading that don't actually mean that.
- 2026-08-31 triage of every remaining proposed feature, decided in one pass
  (user: "pose-moi des questions, features par feature… en ordre de
  priorité") — recorded here so none of these get silently re-proposed later:
  - **Admin/super-admin list with email** — accepted, reframed from the
    original "flag a login literally named 'admin'" proposal: the user wants
    the full raw list (administrators *and* network super-admins, each with
    login + email) for manual review, not an automated flag. Needs a plugin
    change (`UsersCollector` — `administrators[]` already carries `id`/
    `login`, add `email`; `super_admins` is currently a bare login-string
    list from `get_super_admins()`, needs the same `id`/`login`/`email` shape
    via a `get_user_by('login', …)` lookup per entry) plus an Xtractor
    rendering update and a fixture update.
  - **Absence-of-known-security-plugin flag** — declined ("pas convaincu").
  - **Absence-of-known-2FA-plugin flag** — declined, and rightly: the user
    caught that this is the exact same false-confidence shape as `page_cache`
    and `is_backend_ssl` before it was even built — a plugin being *installed*
    proves nothing about whether it is actually configured/enforced. Only a
    human can judge that; do not resurrect this as an automated rule.
  - **Per-admin last-login timestamps** — declined. Confirmed correct in the
    asking: WordPress core does not track this natively (Wordfence and
    similar do, via their own hook); building it would mean adding a
    `wp_login` hook to the plugin that only starts recording going forward,
    with no retroactive history. Not worth it for now.
  - **Admin-email breach-database check** (HaveIBeenPwned-style) — declined
    for now: real privacy cost (sending admin emails to a third party) for
    uncertain benefit; revisit once the admin/email list above is actually in
    place and used.
  - **File-integrity checksum vs. wp.org** (core/plugins/themes, to catch a
    "nulled" plugin bundling a backdoor) — declined: BlogVault already does
    this as part of its own scanning. Would also have been core-only anyway —
    wp.org publishes an official prebuilt checksums API
    (`api.wordpress.org/core/checksums`) for core, but no equivalent for
    plugins/themes; those would need Xtractor to download the exact released
    zip and hash it itself.
  - **ASN/hosting-provider lookup** — declined: most tracked sites sit behind
    Cloudflare, so an ASN lookup on the resolved IP would mostly just say
    "Cloudflare", not the real host. Domain-level RDAP/WHOIS (already built)
    is unaffected by this and needed no change.
  - **Computed SSL/TLS grade** (Qualys-style A–F) — declined: the individual
    facts (TLS 1.0/1.1/1.2/1.3, chain valid, expiry) are already all shown
    separately; an aggregate score risks hiding the nuance rather than
    helping, the same failure mode this session kept finding and fixing
    elsewhere (`page_cache`, `is_backend_ssl`, the old flat health-score
    formula).
  - **Analytics tags** (GA/Ads/pixel) — removed from scope entirely, not just
    deferred; do not re-propose.
  - **Mail validator** (external API) — kept as an open TBD; the user wants
    to research it before it gets scoped, not a decision either way yet.
  - **`_errors`** — resolved the next day (2026-08-31), and not by giving it
    a UI: user, "ça devrait être retiré. Soit l'extraction fonctionne à
    100%, soit elle vaut rien." Removed from the plugin entirely rather than
    documented further — `Extraction::build_data()` and
    `ConnectorRegistry::collect()` both used to isolate a failing
    collector/connector into an `_errors`/`_error` stub so the rest of the
    payload still sent; neither catches anything now; a failure aborts the
    whole build and propagates to the caller. `ExtractionSender::send_build_data()`
    catches it at that boundary and returns the existing `\WP_Error` contract
    (`swp_extraction_build_failed`) instead of sending a payload that looks
    complete apart from an easy-to-miss key — nothing partial is ever sent.
    `Cli::extract()`'s direct `build_data()` call is deliberately left to
    throw uncaught (it is the raw debug/preview command; seeing the real
    failure is the point). Xtractor needed no change: `_errors` never
    reached a real fixture, so nothing there ever depended on it.
