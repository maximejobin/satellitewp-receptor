# SatelliteWP Xtractor — working context

Server-side companion to the `satellitewp-plugin-maintenance` WordPress plugin.
It receives signed extraction payloads, runs external validation probes,
evaluates a rule catalogue into **findings**, and shows an analyst dashboard.
PHP 8.4+, Composer, symfony/console, Guzzle. No framework.

## Golden rules (user feedback — also in the persistent memory/)

- **Work only in this repo.** Never modify `../satellitewp-plugin-maintenance`;
  it is read-only reference (wire protocol in `src/class-remote-client.php`,
  planning docs in `.github/*.txt` — French).
- **Keep it simple.** This is for a ~10-person SMB, not the US army. Do not
  over-architect.
- **`data/` holds raw data + one analysis file only:** `payload.json`,
  `meta.json`, `probes/*.json`, `findings.json`. No derived files (no summary.json).
- **Source files are language-neutral.** `findings.json` and probes carry only
  ids/status/observed/data — never FR/EN prose. Sentences live in
  `config/lang/{en,fr}.php` and are rendered at display time by
  `Rules\Translator`. (Exception: PageSpeed locale is a probe input.)
- Don't propose building what WP-CLI / WP core already does natively.

## Flow

plugin `POST` signed payload → `public/index.php` **Receptor** (HMAC verify via
`X-SWP-Signature`, anti-replay, store, index as `pending`) → cron
`bin/xtractor ingest:process` → **Pipeline** runs probes (dns, rdap, tls, http,
pagespeed) writing `probes/*.json`, records plugin/theme slugs into the
**SoftwareCatalog**, then **RuleEngine** evaluates `config/rules.php` → neutral
`findings.json` → web UI renders (localized).

JSON files are the source of truth; `data/index.sqlite` is a rebuildable index
(`index:rebuild`).

## Key components

- `src/Http/`: Receptor, SignatureVerifier, PayloadValidator, Router (GET web +
  POST licence editor with CSRF; `matchRoute`/`resolveRawFile`/`safeReturn` are
  pure & tested).
- `src/Probe/`: `ProbeInterface` + Dns/Rdap/Tls/Http/PageSpeed. Parsing is a
  pure static method per probe, unit-tested without network.
- `src/Rules/`: `Category` (16 short English codes), `Severity` (C/E/M/I),
  `Status`, `Pastille` (green/orange/red/blue/grey, derived from status+severity),
  `Check`/`CheckResult`, `Finding` (neutral), `Rule`, `RuleCatalog`, `RuleEngine`,
  `Context` (dot paths `payload.*` / `probe.*` / reference), `Translator`.
- `config/rules.php`: 62 rules. Ids follow the plugin's
  `.github/validations-techniques.txt` section letters; `W*` = domain (WHOIS),
  `PS*` = Lighthouse. Thresholds overridable via `rules.thresholds.<id>`.
- `config/lang/{en,fr}.php`: per-rule `title`/`fail`/optional `pass`, plus UI /
  status / severity / pastille / category labels.
- `src/Reference/EndOfLife.php`: endoflife.date cache (php, wordpress, mysql,
  mariadb) refreshed by `reference:refresh`; read offline by rules F2/F3/H1.
- `src/Catalog/SoftwareCatalog.php`: cross-site plugin/theme licence catalogue
  (`data/catalog/software.json`): free/premium/mixed/unknown; wp.org suggestion
  via `catalog:suggest`.
- `src/Integration/BlogVaultClient.php`: generic parameter-driven v6 client —
  **not yet wired** to rule F10 (awaits BlogVault base_url + auth scheme).
- `src/Web/`: `templates/` (sidebar `layout`, `sites`, `site`, `extraction`,
  `catalog`) + `helpers.php`; `public/assets/style.css`.

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
`catalog:list [--needs-license]`/`catalog:set`/`catalog:suggest` ·
`keys:add`/`list`/`revoke` · `sites:list` · `extractions:list` · `index:rebuild`.

## Testing

`composer test` — 135 tests, no network. Manual end-to-end: `docs/TESTING.md`.
`phpunit.xml.dist` excludes the `network` group, reserved for any future
live-probe tests; none exist yet, so the suite runs fully offline.

## Conventions

`declare(strict_types=1)` everywhere; typed params; English docblocks; PHPUnit
`#[DataProvider]` attributes. Commit messages end with the `Co-Authored-By` line.
No PHPStan yet (user chose a targeted quality pass).

## Config / secrets

`config/config.php` (committed) + `config/config.local.php` (gitignored):
`pagespeed.api_key` lives there; `blogvault` base_url/auth there once known.

## Pending / next steps

- Wire **BlogVault** → rule F10 (vulnerabilities/malware) + §Security backup +
  §Account backup status. Needs v6 base URL + auth scheme.
- **WooCommerce platform** → §Account & plan (client, care plan, renewal).
- **Analytics tags** (GA / Ads / pixel) → §SEO & analytics — needs a `[DATA+]`
  extractor field (plugin side).
- Exposure probes (source 7), mail validator (external API TBD), ASN/hosting
  lookup, computed SSL grade.
