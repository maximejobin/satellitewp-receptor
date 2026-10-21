# SatelliteWP extraction bundle

This directory holds everything SatelliteWP Manager knows about this WordPress
site at one point in time: what the site reported about itself, what Manager
measured from the outside, and the verdicts Manager drew from both. It sits
next to a local copy of the site so you can go further than Manager can:
read the code, the theme, the plugins and the configuration, and confirm,
refine or extend the findings.

Read `meta.json` first: it says which site and which extraction this is, when
it was taken and in which language the client works.

## What is where

```
.swp-extraction/
├── instructions.md          this file
├── meta.json                site, extraction id, dates, status, client language
├── raw/                     facts, as collected
│   ├── payload.json         sent by the SatelliteWP plugin from inside WordPress
│   └── probes/<name>.json   measured by Manager from outside the site
├── analysis/                what Manager concluded
│   ├── findings.json        one verdict per rule (language-neutral)
│   ├── findings.<lang>.md   the same verdicts as the client reads them
│   ├── report.json          the data that fills the client's "Bilan de santé"
│   ├── observations.json    analyst notes (present only when written)
│   └── licenses.json        premium licence status (present only when set)
└── reference/
    └── rules-catalog.md     every rule: what it checks, thresholds, texts
```

### raw/payload.json

Collected by the plugin inside WordPress: versions (WordPress, PHP, database),
plugins and themes (keyed by plugin file / theme stylesheet), must-use plugins
and drop-ins, `wp-config.php` constants, PHP settings, database size and
tables, autoloaded options, cron, users and roles, content counts, update
policy, file permissions. A constant that is not defined is the string
`"N/A"`, not `false`. When a collector failed on the site, its section is
missing and listed in `collector_errors` (also in `meta.json`).

`php.sapi` tells which PHP produced the data. `cli` means the extraction was
sent with WP-CLI: PHP settings then describe the command line, not the PHP
that serves visitors.

### raw/probes/<name>.json

Each file is an envelope: `probe`, `ran_at`, `status` (`ok`, `error`,
`pending`), `errors` (sub-checks that did not complete) and `data`.

| Probe | Measures |
|---|---|
| `dns` | name servers, A/AAAA, MX, SPF and DMARC records |
| `rdap` | registrar, creation, update and expiry dates of the domain |
| `tls` | certificate issuer, validity, covered names, TLS versions |
| `http` | redirects, status, security headers, compression, HTTP/2–3, exposed files, robots.txt and sitemap |
| `pagespeed` | Google Lighthouse scores and lab metrics, mobile and desktop |
| `blogvault` | the BlogVault scanner's view (malware, vulnerabilities, users) |
| `wordfence` | known vulnerabilities matched against installed versions |
| `wporg` | when each plugin and theme hosted on wordpress.org was last updated |
| `mail` | SPF, DKIM and DMARC verdicts of a test email sent by the site |
| `seranking` | SE Ranking site audit: score, crawled pages, issues by check |

A `null` value means "not measured", never "absent" or "false".

### analysis/findings.json

One entry per rule:

- `id`: the rule, described in `reference/rules-catalog.md`;
- `status`: `pass`, `fail`, `na` (does not apply) or `unknown` (the data
  could not prove a verdict). `unknown` is not a pass: it is something
  Manager could not see, often something you can check in the code;
- `severity`: `C` critical, `E` high, `M` medium, `I` informational;
- `pastille`: the colour in the report — `red` and `orange` failures,
  `purple` a request for the client's input, `blue` information, `green`
  pass, `grey` not applicable or unknown;
- `observed`, `threshold`, `data`: the values behind the verdict.

### analysis/findings.<lang>.md

The same findings with their title and text in the client's language, worst
first, followed by the analyst's observations. Use it as the tone and
vocabulary reference for anything written for the client.

### analysis/report.json

The data of the client report, keyed by the template's `{{variable}}` names
(business fields such as the client and the maintenance plan are left out).
`value` fields carry a display value and a colour, `table` fields headers and
rows, `observations` fields the findings placed in that section.

## Things to keep in mind

- **It is a snapshot.** Everything describes the site at `received_at`. The
  local copy may be newer or older: check versions before concluding that a
  finding still holds or no longer does.
- **Personal data is left out.** Email addresses and administrator logins
  were removed before the bundle was built: a missing `email` or `login`
  field was omitted on purpose, not missing on the site.
  A login that is exactly `admin` is kept, because it is a finding in itself.
- **Write for the client in their language** (`extraction.language` in
  `meta.json`): short sentences that say what the element is, its impact,
  and what to do. Use the conditional when an impact is likely rather than
  certain. Do not recommend a specific plugin.
- **Do not commit this directory.** Add `.swp-extraction/` to the copy's
  `.gitignore` if it is a git repository. Running the bundle command again
  replaces the whole directory with the newest data.
