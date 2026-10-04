# SatelliteWP Manager — working context

SatelliteWP's management tool (formerly "Xtractor"). Its public ingestion
endpoint is the **Extractor** (formerly "Receptor"), still served from
`public/receptor/` because the hosting panel's web root points there. Server-side
companion to the `satellitewp-plugin-maintenance` WordPress plugin.
It receives signed extraction payloads, runs external probes, evaluates a rule
catalogue into **findings**, shows an analyst dashboard and feeds the client
"Bilan de santé" Google Doc. PHP 8.4, Composer, symfony/console, Guzzle, no
framework.

## Golden rules

- **Senior agency quality, always.** Findings must be accurate and worth a
  client's time: no false positives, no noise rules, no placeholder or raw
  value reaching a client document. When data can't prove a verdict, the rule
  returns *unknown* — never a guessed pass or fail.
- **Comments explain the technical why, in one or two lines.** No dates,
  client or site names, debugging stories, "confirmed live", quotes of
  requests, or history of what code used to do — that belongs in commit
  messages. Tests use `example.com`, never a client domain.
- **Validate judgement calls with the user before implementing.** Design
  choices (layout, colours, wording strategy, scope) are confirmed first;
  mechanical follow-through of an explicit instruction is not.
- **Keep it simple.** ~10-person SMB. No speculative abstraction.
- **An extraction is a snapshot of a site's configuration**, not a tracker of
  operational state. Before adding a data source, ask: does it describe what
  the site *is configured as*, or an independently changing status that
  belongs to another tool (BlogVault dashboard, monitoring)? The latter is out
  of scope, not "coming soon".
- **Snapshots are frozen.** A `done` extraction is never re-probed silently.
  Rule changes are applied to stored extractions with `rules:reevaluate`
  (always `--dry-run` first); re-probing is the explicit `debugging_tools`
  re-run or `pipeline:run`/`probe:run`, both of which overwrite stored data.
- **Two repos, one protocol.** The plugin lives at
  `/home/extractor/webapps/wpsite/public/wp-content/plugins/satellitewp-plugin-maintenance`
  (own git repo, editable). Protocol changes (headers, signed string,
  envelope) land on both sides in one change; `tests/fixtures/extraction-valid.json`
  mirrors the plugin's collectors exactly (maps keyed by plugin file /
  stylesheet, WP count objects, all 22 constants with the `"N/A"` sentinel).
- **`data/` holds raw data + analysis only**: `payload.json`, `meta.json`,
  `probes/*.json`, `findings.json`, plus analyst inputs `observations.json`
  and `licenses.json`. No derived files. HTTP 500s go to `logs/`.
- **Source files are language-neutral.** Findings and probes carry ids,
  status, observed values and data — never prose. Sentences live in
  `config/lang/{fr,en}.php`, rendered by `Rules\Translator`.
- Don't build what WP-CLI / WordPress core already does.
- Never set a real extraction to `queued` for testing (the production crontab
  runs `ingest:process` every minute), never trigger `wordfence:refresh` /
  `reference:refresh` or other live external calls without asking.

## Flow

Plugin `POST` → `public/receptor/index.php` (**Extractor**: HMAC over
`timestamp . '.' . body`, timestamp window ±300 s, replay cache, store, index as
`pending`) → analyst presses **Run analysis** (→ `queued`) → cron
`ingest:process` runs **Pipeline** on queued extractions only: probes `dns`,
`rdap`, `tls`, `http`, `pagespeed`, `blogvault`, `wordfence`, `wporg`, `mail`, `crm`, `seranking` write
`probes/*.json`, plugin/theme slugs go to the **SoftwareCatalog**, then
**RuleEngine** evaluates `config/rules.php` → `findings.json`. Any exception
after `running` sets the extraction to `error`.

JSON files are the source of truth; `data/index.sqlite` is a rebuildable index
(`index:rebuild`, schema versioned with `PRAGMA user_version`).

## Components and their pitfalls

**Front controllers.** `public/receptor/` (signed pushes only, no session, flat
404 otherwise) and `public/admin/` (the UI behind Google sign-in). They share
`src/` and `data/` only.

**HTTP layer (`src/Http`).** `Router` resolves declarative GET/POST route
tables (`matchRoute()`, `matchPostRoute()`), enforces auth + CSRF and hands
off to one controller per domain in `Controller/` (Auth, Home, Site,
Extraction, Report, Data, Catalog, User, Crm). All output goes through
`Response` (testable with `tests/Http/RecordingResponse`). `ReportContext` is
the pure, tested builder of the report data context; `ReportContract` loads
`config/reports/*.php`. `safeReturn()`, `withQueryParam()`, `resolveRawFile()`
are pure static helpers (`resolveRawFile` has no name allowlist: `basename()`
confines it).

**Auth & permissions.** Google OAuth (userinfo endpoint, no JWT handling);
Basic auth / open mode only as dev fallbacks. The allowlist is re-checked on
every request. `data/users.json`: first entry is the admin; no "first sign-in
becomes admin" bootstrap (seed with `users:add`). Capabilities are
`<entity>_<verb>` (`config/roles.php`); `Controller::requireCapability()` is
the single gate (permissive only when Google sign-in is off). `/users`
mutations additionally require a real Google identity. The last active admin
can't be removed, demoted or suspended.

**Site binding.** A key record carries an `origin` (normalized `home_url`);
an extraction from another address gets 409. `PayloadValidator::normalizeOrigin()`
mirrors the plugin's `ConfigFile::normalize_url()` — keep them in step. A real
move is `keys:rebind` (keeps `site_id`, hence history). Pairing UI lives in
`/site/{id}` → "⚙ Site settings"; the site_id UUID always comes from the plugin.

**`@` does not suppress an `Error`.** A function in `disable_functions` throws;
optional calls need `try/catch`.

**Probes (`src/Probe`).** Parsing is pure static and unit-tested offline.
- `HttpProbe`: the redirect chain for A10 starts on `http://host/`; every
  other check (status, headers, exposure, soft-404, assets, robots,
  compression, protocols) targets `home_url` (fallback `site_url`). A plain-HTTP
  vhost can be broken independently of the real site. Redirects are followed
  manually with GET, hop by hop, each hop through `HostGuard` (non-global
  addresses refused, connection pinned to the vetted IP via `CURLOPT_RESOLVE`,
  no DNS rebinding). A homepage 401 means "not public": exposure checks are
  skipped (`null` = not checked), never reported clean; per-site Basic Auth
  credentials live in `keys.json` and are only sent over https to the site's
  own host. On a soft-404 site, file checks are skipped. A sensitive file only
  counts as exposed when its content matches the file's signature (streamed,
  ≤ 4 KB). Cookie flags are true only if every cookie carries them. Top-level
  `gzip`/`brotli` describe the main request only; `compression.*` is what the
  server can produce.
- `TlsProbe`: legacy TLS 1.0/1.1 is tested at `security_level=0` (the local
  OpenSSL refuses them at the default level); `null` = not testable.
  `chain_valid` is the chain alone, `hostname_covered` the name.
- `DnsProbe`: a failed lookup is `null` (unknown), never an empty "absent".
  WHOIS/RDAP/NS/MX/DMARC use `SiteContext::registrableDomain()` (last two
  labels + curated `TWO_LABEL_SUFFIXES`, incl. Canadian provinces).
- `WordfenceProbe` makes no network call: it matches the local index against
  the payload's plugins/themes/core (empty versions skipped). `WordfenceIndex`
  is a JSON Lines cache streamed per component (a full decode OOMs), refreshed
  daily under a strict rate limit; a refresh where every feed fails writes
  nothing. The `scanner` feed has no CVE ids; `production` does. Some
  production records are open-ended and unpatched on every version (e.g. two
  core entries): that is upstream data, deliberately not filtered.
- `WporgProbe`: wp.org "last updated" per slug, 5 concurrent requests; 404 =
  not on wp.org (premium/custom), not an error. A per-extraction snapshot, not
  the catalogue.
- `MailProbe`: reads the newest test email (≤ 24 h) whose subject carries the
  site's reference `sha256(site_id)[:16]` (the plugin appends it to the
  subject) from the validation Gmail over IMAP (`mail.*` config, app password,
  `INBOX` then the server's `\Junk` folder; `ImapClient` is read-only, PHP 8.4 has no imap
  ext). Verdicts come from the first `Authentication-Results` whose authserv-id
  is `mx.google.com` — a header from an earlier hop is never trusted. No
  message = `found: false`, verdicts `null`, not a failure. `D1` (SPF), `D2` (DKIM),
  `D3` (DMARC) read only these verdicts (DNS records are display values, never
  a verdict); a method the receiver did not record is `none` (except SPF), a
  transient `temperror` is unknown.
- `CrmProbe`: a snapshot, never read live at display or report time. Chain:
  BlogVault site id (stored `blogvault` probe, `data.site.id`; the full 32-hex
  id, matched case-insensitively) → `swp_websites.blogvault_site_id` →
  `swp_subscriptions_websites` → `swp_subscriptions` → `swp_clients`, and
  `swp_products` → `swp_maintenance_plans` for the plan. Runs after `blogvault`
  and gets its id from that run or the stored file. Unlinked reasons
  (`no_blogvault_id`, `website_not_found`, `website_ambiguous`, `no_client`) are
  data, not errors; an unconfigured or failing CRM database is an `error` that
  stores the SQLSTATE only. `maintenance_plan` is set only when exactly one
  `active` plan exists. Relinking is `probe:run crm <site> [ext]` (overwrites
  `probes/crm.json`); feeds `{{client}}` and `{{maintenance_plan}}`.
- `SeRankingProbe`: an SE Ranking website audit is a crawl that finishes
  minutes to hours later. The pipeline only creates it (crawl credits spent
  per page; `seranking.settings` caps pages and requests/s) and stores a
  `pending` envelope; the extraction is still `done`. `ingest:process` (or
  `seranking:poll [--force]`) runs `Pipeline\AuditPoller`, which finds
  pending runs through the index and checks each at most every
  `poll_minutes`: finished = `ok` with score, totals and a flat `checks` list
  (passed ones included); cancelled/expired/404 or older than `give_up_hours`
  = `error`; a transient API failure stays pending (`last_error`). No rule
  reads it yet.
- `BlogVaultProbe` strips the Basic-auth password `GET /sites/{id}` returns;
  `Support\Secret::redact()` masks keys raw and URL-encoded in every stored
  error.

**Rules (`src/Rules`, `config/rules.php`).** 79 active rules. Ids follow the
plugin's `.github/validations-techniques.txt` letters; `W*` domain, `PS*`
Lighthouse, `BV*` BlogVault, `X*` passive exposure, `F10` merged
vulnerabilities (BlogVault + Wordfence by CVE, `Rules\VulnerabilityMerge`,
also used by the UI), `F12` auto-update policy, `F13` inactive plugins/themes.
`F9` is reserved. Thresholds overridable via `rules.thresholds.<id>`.
- **Ignored vulnerabilities**: `vulnerabilities.ignored` in `config/config.php`
  (edit it there, not in `config.local.php` — `array_replace_recursive` merges
  lists by position) is a list of Wordfence ids (a BlogVault id also works,
  case-insensitive). Applied at evaluation/display time only (`App::ignoredVulnerabilities()`
  → F10 via `reference('ignored_vulnerabilities')`, `ReportBuilder`, extraction
  page), never at probe time, so `probes/*.json` stay raw; run
  `rules:reevaluate` to re-score. `/data/vulnerabilities` still lists them, with
  an "Ignored" tag, an ignored-only/hide filter and a banner.
- Pastille = f(status, severity, `client_action`): red/orange fail, **purple**
  = standing request for the client's input (W2/W3), blue info, green pass,
  grey n/a–unknown. `Rules\Pastille` is the only list of colours — every
  consumer iterates `Pastille::values()`/`isValid()`/`needsAttention()`.
- Every rule has FR+EN `title`, `title_success`, `title_failure`, `fail`,
  `pass`. `data.variant` selects `fail_<variant>`/`pass_<variant>`. Numbers and
  ISO dates are localized by the Translator; `{n|singulier|pluriel}` handles
  agreement — never "(s)". Client voice: impact + action, no raw units or
  unexplained jargon, French typography (espaces insécables, « »).
- Read constants through `Context::constant()` (`"N/A"` → false; `(bool) "N/A"`
  is true). WP count fields are objects (`wp_count()` in helpers).
- `payload.object_cache.page_cache` means "`advanced-cache.php` exists", not
  "pages are served from cache" (WP only loads it with `WP_CACHE`).

**Client report.** `config/reports/bilan-de-sante.php` maps every `{{variable}}`
of the Google Doc to a `value` (dot path + transform + colour transform), a
`table` (named builder) or `observations` (categories/ids, pastille order
purple → blue → red → orange → green → grey, plus analyst observations filed
under that field). A test enforces that every rule lands in a topic section.
`Web\ReportBuilder` resolves it; `HardeningConstants` is shared with the UI.
The Apps Script (Google Doc side) only renders: `{{field:colours:mode}}`
filters colours (`red,orange`; empty = all) and `short` renders titles only,
11 pt, two borderless columns; purple items render as a ☐ checkbox line. It is
two files in `resources/apps-script/`: `loader.gs`, pasted once per Doc
(deliver it as a full inline code block, never a file path), and `report-engine.gs`,
the engine, served by `…/report-script.json` behind the same credentials as
`report.json`. The loader derives the engine URL from the pasted data URL (so
DEV loads DEV), trusts `satellitewp.com` and its subdomains (any other host asks
first), stores the engine per origin in document properties and offers an
update when the served `var VERSION` is higher — bump it on every engine change.
Both files are English only (identifiers, comments, UI strings).

**Catalogue & reference.** `SoftwareCatalog` (`data/catalog/software.json`):
cross-site free/premium licence classification; `normalizeSlug()` turns
`woocommerce/woocommerce.php` into `woocommerce`. `EndOfLife` (endoflife.date)
and `WordPressVersions` (wordpress.org stable-check, which omits some point
releases) refresh hourly. Cache writes go through `Support\AtomicFile`.

**CRM (`src/Crm`).** External MySQL, read-only except
`ClientsRepository::setSubscriptionWebsite()` (delete + insert, DEV-tagged
websites refused server-side). Portable SQL only (tested on SQLite); LIKE
input escaped via `Storage\SqlLike`. Entities are flat siblings (`/websites`,
`/items`, `/clients`, `/products`) — the business works by website first.
Access runbook: `docs/acces-mysql-distant.md`.

**Web UI.** English chrome, light theme, orange accent `#f26f2b`. The
extraction page renders nothing but the status until `done`. Every value can
show its provenance (`src_note()`: payload = as reported by the plugin, probe
= measured by Manager). Datatables: explicit Search/Filter button, never live
filtering; server-side AJAX when row counts grow. Select2 in AJAX mode only.
`license_select()` saves via `fetch()` (`requestSubmit()`, since
`form.submit()` fires no submit event). Assets are vendored, no CDN, no build.
`debugging_tools` (config, dev only) adds a re-run panel on done extractions.

**Errors.** `Support\ErrorLog` writes one JSON line per 500 into
`logs/error-<date>.log` and returns a short `ref`; installed before boot by
both front controllers; never logs bodies, signatures or cookies.

## CLI (`bin/swpmgr`)

`ingest:process` · `pipeline:run <site> [ext] [--probe=a,b]` ·
`probe:run <probe> <site> [ext]` (re-runs and stores) · `probe:list` ·
`rules:evaluate [--all] [--lang]` · `rules:list [--category]` ·
`rules:reevaluate [site] [--dry-run]` · `rules:doc > docs/rules-catalog.md` ·
`reference:refresh [--product]` (hourly) · `wordfence:refresh` (daily) ·
`catalog:list|set|suggest|reindex` · `keys:add|list|revoke|rebind` ·
`users:add` · `users:set-role` · `sites:list` · `extractions:list` ·
`seranking:poll [--force]` · `index:rebuild`.

## Testing & conventions

`composer check` = PHPUnit (offline; `network` group reserved) + PHPStan
level 6 on `src/` and `bin/` with an empty baseline — fix causes, never add
baseline entries. Templates are excluded from PHPStan (`extract()` in render).
Manual end-to-end: `docs/TESTING.md`; pairing/ops: `docs/PAIRING.md`.
`declare(strict_types=1)`, typed signatures, English code and docblocks,
PHPUnit attributes. Commit messages end with the `Co-Authored-By` line.
Regenerate `docs/rules-catalog.md` after any rules or lang change.

## Config / secrets

`config/config.php` (committed) + `config/config.local.php` (gitignored):
API keys (`pagespeed`, `blogvault`, `wordfence`, `seranking`), `crm_db.*`, Google OAuth,
`app.base_url` (absolute URLs for report links, icons and the OAuth
callback; the request host is used only when empty), `debugging_tools`.

## Decided — don't re-propose

Declined: flag for "no security plugin", flag for "no 2FA plugin" (installed ≠
enforced), per-admin last-login tracking, breach-database email checks,
file-integrity checksums (BlogVault does it), ASN/hosting-provider lookup
(Cloudflare hides it), a computed SSL grade, analytics tags, an SMTP rule
(php.ini `SMTP` is Windows-only; the payload can't tell authenticated SMTP),
BlogVault scanner/firewall/backup status and care-plan data (operational
state, not configuration), per-collector `_errors` (an extraction is complete
or it is not sent).

Open: rules/UI for the CRM snapshot beyond the report fields.
