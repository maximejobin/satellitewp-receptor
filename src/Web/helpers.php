<?php

declare(strict_types=1);

/**
 * Template helpers — loaded by layout.php.
 */

/** HTML-escape. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** See SatelliteWP\Xtractor\Support\SiteDisplay — this is just the template-friendly wrapper. */
function site_display(mixed $url): string
{
    return \SatelliteWP\Xtractor\Support\SiteDisplay::of($url);
}

/**
 * The explicit-search box every Datatable now needs instead of the native
 * live-as-you-type quick search (2026-09-02, user: "on devra absolument
 * cliquer sur 'Search'... " — confirmed to apply to the built-in box too,
 * not just a page's own filter-bar controls). `#$tableId table.DataTable()`
 * must be initialized with `dom` excluding 'f' (no native box rendered) —
 * this input is wired by the shared `initExplicitSearch()` in layout.php,
 * matched to the table by `data-table="#$tableId"`.
 */
function dt_search_box(string $tableId, string $placeholder = 'Search…'): string
{
    return '<input type="search" class="xt-dt-search" data-table="#' . e($tableId) . '" placeholder="' . e($placeholder) . '">'
        . '<button type="button" class="btn xt-dt-search-btn" data-table="#' . e($tableId) . '">Search</button>';
}

/** Human-readable bytes. */
function fmt_bytes(mixed $bytes): string
{
    if (!is_numeric($bytes)) {
        return '—';
    }

    $bytes = (float) $bytes;
    foreach (['o', 'Ko', 'Mo', 'Go', 'To'] as $unit) {
        if ($bytes < 1024) {
            return round($bytes, 1) . ' ' . $unit;
        }
        $bytes /= 1024;
    }

    return round($bytes, 1) . ' Po';
}

/** Status badge (ok / warn / error / pending / running / done). */
function badge(?string $status): string
{
    $status = $status ?? 'unknown';
    $class  = match ($status) {
        'ok', 'done'       => 'badge-ok',
        'warn', 'pending', 'queued', 'running' => 'badge-warn',
        'error'            => 'badge-error',
        default            => 'badge-muted',
    };

    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

/**
 * A colored dot + colored text status label — a lighter-weight alternative to
 * badge()'s solid pill, modeled after a reference design the user shared for
 * the "Services" table on /clients/{id} (2026-09-02: active/pending/on-hold,
 * the three real swp_subscriptions.subscription_status values — see
 * ClientsRepository). Not a general replacement for badge(), which every
 * other status column in the app still uses.
 */
function status_dot(?string $status): string
{
    $status = $status ?? 'unknown';
    $class  = match ($status) {
        'active', 'ok', 'done' => 'status-dot-ok',
        'pending', 'warn', 'queued', 'running' => 'status-dot-warn',
        'on-hold', 'error' => 'status-dot-error',
        default => 'status-dot-muted',
    };

    return '<span class="status-dot ' . $class . '">' . e(ucfirst(str_replace('-', ' ', $status))) . '</span>';
}

/**
 * A page-level alert banner — info (blue), warning (orange/yellow), or
 * critical (red). Unlike badge(), a small inline pill for one value, this is
 * a block-level box meant to surface something the analyst should notice
 * before reading the rest of the page (e.g. "N subscriptions not linked to
 * a website"). $html is trusted, pre-rendered HTML (so a notice can carry a
 * link), same convention as field_raw() vs field().
 */
function notice(string $level, string $html): string
{
    $level = in_array($level, ['info', 'warning', 'critical'], true) ? $level : 'info';
    $icon  = match ($level) {
        'warning'  => '⚠',
        'critical' => '⛔',
        default    => 'ⓘ',
    };

    return '<div class="notice notice-' . $level . '"><span class="notice-icon">' . $icon . '</span>'
        . '<span>' . $html . '</span></div>';
}

/** Lighthouse-style score badge: green >= 90, orange >= 50, red below. */
function badge_score(int $score): string
{
    $class = match (true) {
        $score >= 90 => 'badge-ok',
        $score >= 50 => 'badge-warn',
        default      => 'badge-error',
    };

    return '<span class="badge ' . $class . '">' . $score . '</span>';
}

/**
 * A clickable link to an external system (Teamwork/HubSpot/BlogVault client,
 * BlogVault website, WordPress "edit at the source" — config/config.php's
 * `external_links.*`), built from a URL pattern with a literal "{id}"
 * placeholder. Plain, unlinked text whenever the pattern isn't configured
 * yet or there's no id to substitute — never a link to nowhere.
 */
function external_link(?string $pattern, mixed $id, string $label): string
{
    $idString = $id !== null && $id !== '' ? (string) $id : null;
    if ($pattern === null || $pattern === '' || $idString === null) {
        return e($label);
    }

    $url = str_replace('{id}', rawurlencode($idString), $pattern);

    return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($label) . '</a>';
}

/**
 * Like external_link(), but for an *action* button ("Edit", "View in
 * BlogVault") rather than a value shown as its own label: unlike a value,
 * which is still worth displaying unlinked, an action with nowhere to go is
 * not worth showing at all — this returns '' (render nothing) instead of
 * inert text when the pattern isn't configured yet or there's no id.
 */
function external_link_button(?string $pattern, mixed $id, string $label): string
{
    $idString = $id !== null && $id !== '' ? (string) $id : null;
    if ($pattern === null || $pattern === '' || $idString === null) {
        return '';
    }

    $url = str_replace('{id}', rawurlencode($idString), $pattern);

    return '<a class="btn" style="margin:0;padding:.25rem .6rem;font-size:.8rem" href="' . e($url) . '" '
        . 'target="_blank" rel="noopener noreferrer">' . e($label) . '</a>';
}

/**
 * Inline licence editor: a select that auto-submits to /catalog. $current is the
 * analyst-set licence ('unknown' by default); $suggested is shown as a hint.
 */
function license_select(
    string $type,
    string $slug,
    string $current,
    string $csrf,
    string $return,
    ?string $suggested = null
): string {
    $labels = ['unknown' => 'unknown', 'free' => 'free', 'premium' => 'premium', 'mixed' => 'mixed', 'custom' => 'custom code'];
    $options = '';
    foreach ($labels as $licence => $label) {
        if ($licence === 'unknown' && $suggested) {
            $label = "unknown ({$suggested}?)";
        }
        $selected = $licence === $current ? ' selected' : '';
        $options .= '<option value="' . $licence . '"' . $selected . '>' . e($label) . '</option>';
    }

    $cls = 'lic-' . e(($current === 'unknown' && $suggested) ? $suggested : $current);

    return '<form method="post" action="/catalog" class="lic-form">'
        . '<input type="hidden" name="_csrf" value="' . e($csrf) . '">'
        . '<input type="hidden" name="type" value="' . e($type) . '">'
        . '<input type="hidden" name="slug" value="' . e($slug) . '">'
        . '<input type="hidden" name="return" value="' . e($return) . '">'
        // requestSubmit(), not submit(): the plain .submit() DOM method
        // is specified to skip firing a "submit" event entirely, which is
        // exactly what layout.php's global listener needs to intercept this
        // and save via fetch() instead of a full page reload.
        . '<select name="license" class="' . $cls . '" onchange="this.form.requestSubmit()">' . $options . '</select>'
        . '</form>';
}

/**
 * The "change linked website" control shown next to a subscription, wherever
 * one is listed (client detail, website detail). Deliberately **not** the
 * auto-submit-on-change pattern license_select() uses above: this changes a
 * real billing/service relationship in the external CRM database (2026-09-02,
 * user: "la sauvegarde de ce changement doit être explicite"), so it is a
 * real form submit + full page reload behind a visible Save button, not a
 * one-click dropdown.
 *
 * select2 in **AJAX** mode (2026-09-02, user: "je veux les select2 avec du
 * ajax. C'était ça le point" — a first pass that preloaded every assignable
 * website into the <select> missed this): only the *currently linked*
 * website, if any, is rendered as an <option> here — everything else is
 * fetched from /websites/search as the operator types. This also removes the
 * "current site excluded from assignment" edge case an earlier version had
 * to special-case: with nothing preloaded to match against, the current
 * option is simply always shown as-is, DEV-tagged or not, until deliberately
 * changed.
 *
 * Display-only by default, an edit icon reveals the form (2026-09-02, user:
 * "je voudrais que ce soit seulement afficher... avoir un icône pour edit et
 * là, on affiche le dropdown") — layout.php's shared click handler toggles
 * `.wf-display`/`.wf-edit-form` by matching `data-wf-id` and initializes
 * select2 the first time the form is revealed (not eagerly: this select
 * deliberately carries `.wf-select`, not `.js-select2`, to opt out of the
 * page's normal eager init, since a select2 initialized against a
 * display:none element cannot correctly measure it).
 *
 * @param array<string, mixed> $subscription carries 'id' and optionally 'website_id'/'website_url'
 */
function subscription_website_form(array $subscription, string $csrf, string $return): string
{
    $currentId  = isset($subscription['website_id']) ? (int) $subscription['website_id'] : null;
    $currentUrl = $subscription['website_url'] ?? null;
    $subId      = (int) $subscription['id'];
    $label      = $currentId !== null ? (site_display($currentUrl) ?: ('#' . $currentId)) : null;

    $display = '<span class="wf-display" data-wf-id="' . $subId . '">'
        . ($label !== null
            ? '<a href="/websites/' . $currentId . '">' . e($label) . '</a>'
            // Red, not the usual muted "—": a subscription with no linked
            // website is something to act on, not a neutral absence
            // (2026-09-02, user: "Unassigned en rouge").
            : '<span class="val-error">Unassigned</span>')
        . ' <button type="button" class="wf-edit-btn" data-wf-id="' . $subId . '" '
        . 'title="Change linked website" aria-label="Change linked website">✎</button>'
        . '</span>';

    $options = '<option value=""></option>';
    if ($currentId !== null) {
        $options .= '<option value="' . $currentId . '" selected>' . e((string) $label) . '</option>';
    }

    $form = '<form method="post" action="/subscriptions" class="wf-edit-form" data-wf-id="' . $subId . '" '
        . 'style="display:none;gap:.3rem;align-items:center;margin:0">'
        . '<input type="hidden" name="_csrf" value="' . e($csrf) . '">'
        . '<input type="hidden" name="subscription_id" value="' . $subId . '">'
        . '<input type="hidden" name="return" value="' . e($return) . '">'
        . '<select name="website_id" class="wf-select" data-ajax-url="/websites/search" data-placeholder="— None —">'
        . $options . '</select>'
        . '<button type="submit" class="btn" style="padding:.25rem .6rem">Save</button>'
        . '<button type="button" class="btn wf-cancel-btn" style="padding:.25rem .6rem;background:var(--surface-2);color:var(--text)">Cancel</button>'
        . '</form>';

    return $display . $form;
}

/**
 * Multiplicative decay, not a flat "100 - red*8 - orange*3" deduction: the
 * flat version clips to 0 the moment a site accumulates ~13 red findings,
 * which a real, if unhealthy, WordPress site reaches easily — a live tracked
 * site here scored exactly 0 on every one of its extractions (9-12 red,
 * 19 orange each time), with no way to tell "quite bad" from "catastrophic"
 * apart, which reads as the meter being broken rather than reporting a
 * genuinely low score. Each red multiplies by 0.90, each orange by 0.97:
 * the score approaches 0 for a site with very many failures but never
 * actually floors there for a realistic count, so two bad sites still
 * compare meaningfully against each other.
 *
 * @param array{by_pastille?: array<string, int>} $counts
 */
function health_score(array $counts): int
{
    $p      = $counts['by_pastille'] ?? [];
    $red    = (int) ($p['red'] ?? 0);
    $orange = (int) ($p['orange'] ?? 0);

    return (int) round(100 * (0.9 ** $red) * (0.97 ** $orange));
}

/** Semantic colour for a health score: green ≥80, orange ≥50, red below. */
function health_color(int $score): string
{
    return $score >= 80 ? 'var(--ok)' : ($score >= 50 ? 'var(--warn)' : 'var(--error)');
}

/** A report-card letter for the same score — the one-glance version of the number. */
function health_grade(int $score): string
{
    return match (true) {
        $score >= 90 => 'A',
        $score >= 80 => 'B',
        $score >= 65 => 'C',
        $score >= 50 => 'D',
        default      => 'F',
    };
}

/**
 * The exact arithmetic behind health_score(), spelled out with this
 * extraction's own numbers — not a restatement of the formula, the formula
 * *applied*. An analyst asked to sign off on a client-facing grade needs to
 * see precisely how it was reached, not just be told to trust it.
 *
 * @param array{by_pastille?: array<string, int>} $counts
 */
function health_score_breakdown(array $counts): string
{
    $p      = $counts['by_pastille'] ?? [];
    $red    = (int) ($p['red'] ?? 0);
    $orange = (int) ($p['orange'] ?? 0);
    $redFactor    = 0.9 ** $red;
    $orangeFactor = 0.97 ** $orange;
    $score        = health_score($counts);

    $rows = field_raw('Critical / High findings (red)', e($red) . ' × 10% penalty each → <span class="mono">0.9<sup>' . e($red) . '</sup> = ' . e(round($redFactor, 4)) . '</span>')
        . field_raw('Medium findings (orange)', e($orange) . ' × 3% penalty each → <span class="mono">0.97<sup>' . e($orange) . '</sup> = ' . e(round($orangeFactor, 4)) . '</span>')
        . field_raw('Score', '<span class="mono">100 × ' . e(round($redFactor, 4)) . ' × ' . e(round($orangeFactor, 4)) . ' ≈ ' . e($score) . '</span>')
        . field('Grade', health_grade($score) . ' (A ≥ 90, B ≥ 80, C ≥ 65, D ≥ 50, else F)');

    return '<div class="xt-score-breakdown"><table class="kv"><tbody>' . $rows . '</tbody></table>'
        . '<p class="muted" style="padding:0 1.1rem 1rem;margin:0;font-size:.82rem">'
        . 'Blue (info) and grey (n/a/unknown) findings never affect the score — only red and orange do. '
        . 'Each red multiplies the score by 0.90 and each orange by 0.97, compounding rather than subtracting flat points, '
        . 'so the score approaches 0 for a very unhealthy site without ever floor-clipping to exactly 0 the way a flat '
        . '"100 − red×8 − orange×3" deduction would.</p></div>';
}

/** Coloured pastille (green/orange/red/blue/grey) + label — the analyst signal. */
function pastille(string $color, string $label): string
{
    return '<span class="pastille pastille-' . e($color) . '" title="' . e($label) . '">'
        . '<span class="dot"></span>' . e($label) . '</span>';
}

/**
 * A label/value card. $rows is pre-rendered <tr> HTML from field()/field_raw().
 * Inspired by the section-per-topic layout of the existing Xtract tool, styled
 * with this project's own CSS (no Bootstrap/Metronic). $class adds extra
 * classes to the wrapping <section> — e.g. "card-full" to span the whole
 * width of a `.cards` grid instead of sharing a row with the next card.
 */
function section(string $title, string $rows, string $badge = '', string $class = ''): string
{
    if (trim($rows) === '') {
        return '';
    }

    return '<section class="card info-card' . ($class !== '' ? ' ' . e($class) : '') . '"><h3>' . e($title) . ($badge !== '' ? ' ' . $badge : '')
        . '</h3><table class="kv"><tbody>' . $rows . '</tbody></table></section>';
}

/**
 * Reads a WordPress count. The plugin ships wp_count_posts(),
 * wp_count_comments(), wp_count_attachments() and count_users() verbatim, so
 * these arrive as maps keyed by status / mime type / role, never as integers.
 * Returns the first named key that is present, or the sum of the map when none
 * is named; `trash` is excluded from that sum. A plain integer passes through,
 * which keeps older payloads working.
 */
function wp_count(mixed $value, string ...$keys): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_array($value)) {
        return is_numeric($value) ? (int) $value : null;
    }

    foreach ($keys as $key) {
        if (isset($value[$key]) && is_numeric($value[$key])) {
            return (int) $value[$key];
        }
    }

    unset($value['trash']);

    return (int) array_sum(array_map('intval', array_filter($value, 'is_numeric')));
}

/**
 * One label/value row. $status colours the value: ok (green), warn (orange),
 * error (red), or null (default). Booleans are rendered yes/no. $source, when
 * given, appends a small "ⓘ ) info marker (src_note()) naming exactly which
 * dot-path this came from and how Xtractor can vouch for it — "every datum
 * must be explainable" (user, 2026-08-30): a field on a report an analyst
 * has to sign off on is not allowed to be a mystery number.
 */
function field(string $label, mixed $value, ?string $status = null, ?string $source = null): string
{
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    // Never let a structure reach e(): "(string) $array" is a warning that
    // renders the literal word "Array" in the page.
    if (is_array($value)) {
        $value = null;
    }
    $display = ($value === null || $value === '') ? '—' : e($value);

    return field_raw($label, $display, $status, $source);
}

/** Like field() but the value is trusted, pre-rendered HTML. See field() for $source. */
function field_raw(string $label, string $html, ?string $status = null, ?string $source = null): string
{
    $cls = match ($status) {
        'ok'    => 'val-ok',
        'warn'  => 'val-warn',
        'error' => 'val-error',
        default => '',
    };
    $note = $source !== null ? src_note($source) : '';

    return '<tr><th>' . e($label) . '</th><td class="' . $cls . '">' . $html . $note . '</td></tr>';
}

/**
 * A small "ⓘ" marker naming exactly where one displayed value came from —
 * the precise dot-path (`payload.*`, `probe.<name>.*`, `catalog.*`,
 * `reference.*`) plus a plain-language sentence on who produced it and
 * whether Xtractor independently verified it or is just relaying what the
 * WordPress plugin reported. Dispatches on the path's root, not per-field,
 * so every field gets an accurate provenance without 150 hand-written
 * one-off sentences that would drift out of sync with each other.
 *
 * `payload.*` is the one category this project can never verify further:
 * it is whatever the WordPress plugin's own collector measured on the site
 * and sent, unmodified — Xtractor displays it as received.
 */
function src_note(string $path): string
{
    $explain = match (true) {
        str_starts_with($path, 'payload.')            => "Reported by the WordPress plugin's own collector during this extraction — shown as received; Xtractor does not independently re-measure it.",
        str_starts_with($path, 'probe.dns.')           => "Looked up live by Xtractor's own DNS probe during this extraction.",
        str_starts_with($path, 'probe.tls.')           => "Verified live by Xtractor's own TLS probe (a direct HTTPS handshake to the site) during this extraction.",
        str_starts_with($path, 'probe.rdap.')          => 'Fetched live via RDAP or WHOIS by Xtractor during this extraction — from the domain registry, not from the WordPress site.',
        str_starts_with($path, 'probe.http.')          => "Observed live by Xtractor's own HTTP probe (a direct request to the site) during this extraction.",
        str_starts_with($path, 'probe.pagespeed.')     => 'Fetched live from Google PageSpeed Insights (Lighthouse) by Xtractor during this extraction.',
        str_starts_with($path, 'probe.wordfence.')     => 'Cross-referenced by Xtractor against the local Wordfence Intelligence vulnerability cache — not measured on the site directly.',
        str_starts_with($path, 'probe.blogvault.')     => "Fetched from BlogVault's own account data for this site during this extraction — not measured directly by Xtractor.",
        str_starts_with($path, 'catalog.')             => "Set by hand by an analyst in Xtractor's own software catalogue (/catalog) — not collected from the site at all.",
        str_starts_with($path, 'reference.eol.')       => 'From endoflife.date, cached locally and refreshed on a schedule — not measured on this site.',
        str_starts_with($path, 'derived.')             => 'Computed by Xtractor from other fields on this same page — see the linked detail for the exact arithmetic.',
        default                                        => '',
    };
    if ($explain === '') {
        return '';
    }

    return ' <span class="xt-src" tabindex="0" title="' . e($path . ' — ' . $explain) . '">ⓘ</span>';
}

/**
 * Inline EOL annotation from EndOfLife::eolStatus(): "(end of life: DATE)" in
 * red when past, "(supported until: DATE)" muted otherwise. Localized via the
 * Translator so it follows the page language.
 *
 * @param array{0: bool, 1: string|null}|null $status
 */
function eol_annotation(?array $status, \SatelliteWP\Xtractor\Rules\Translator $t): string
{
    if ($status === null || $status[1] === null) {
        return '';
    }

    [$isEol, $date] = $status;
    $cls   = $isEol ? 'val-error' : 'val-muted';
    $label = $isEol ? $t->ui('eol', 'end of life') : $t->ui('supported_until', 'supported until');

    return ' <span class="' . $cls . '">(' . e($label) . ': ' . e($date) . ')</span>';
}

/** Compact comma list with a "+N" overflow. */
function fmt_list(mixed $items, int $max = 12): string
{
    if (!is_array($items) || $items === []) {
        return '—';
    }

    $shown = array_slice($items, 0, $max);
    $more  = count($items) - count($shown);

    return e(implode(', ', array_map('strval', $shown))) . ($more > 0 ? " <span class=\"val-muted\">+{$more}</span>" : '');
}

/**
 * Merges one component's vulnerability lists from BlogVault and Wordfence
 * Intelligence into one list, each entry tagged with `sources` — the answer
 * to "does this come from BlogVault, Wordfence, or both?".
 *
 * Matched by strict, case-insensitive `cve_id` equality only — the one
 * unambiguous key two independent databases share. A vulnerability without a
 * CVE on either side (common in Wordfence's "scanner" feed, published before
 * a CVE is assigned) is never fuzzy-matched by version range: it stays listed
 * under its own single source rather than risking a false "confirmed by both".
 *
 * `patched_version` is picked by `nearest_patched_version()` from *every*
 * candidate either source lists, relative to `$installedVersion` — not just
 * "the first one a source happens to return". A WordPress core fix commonly
 * lands in several branches at once (6.4.5 *and* 6.5.2 for the same CVE);
 * showing whichever came first in the source's own array could name a branch
 * behind the one actually installed, which reads as "downgrade to fix this".
 *
 * @param list<array<string, mixed>> $blogvault BlogVaultProbe vulnerabilities[] for one component
 * @param list<array<string, mixed>> $wordfence WordfenceProbe vulnerabilities[] for the same component
 * @param string|null $installedVersion the component's version on this site, for `patched_version`
 * @return list<array<string, mixed>> each entry: cve_id, title, cvss_rating,
 *     cvss_score, patched_version, published_at, sources (list of 'blogvault'/'wordfence')
 */
function merge_vulnerabilities(array $blogvault, array $wordfence, ?string $installedVersion = null): array
{
    $cveKey = static function (array $vuln): ?string {
        $cve = $vuln['cve_id'] ?? null;

        return is_string($cve) && $cve !== '' ? strtoupper($cve) : null;
    };

    $merged  = [];
    $matched = [];

    foreach ($blogvault as $bv) {
        $cve   = $cveKey($bv);
        $match = null;
        if ($cve !== null) {
            foreach ($wordfence as $i => $wf) {
                if (!isset($matched[$i]) && $cveKey($wf) === $cve) {
                    $match      = $wf;
                    $matched[$i] = true;
                    break;
                }
            }
        }

        $candidates = array_merge(
            [$bv['patched_version'] ?? null],
            (array) ($match['patched_versions'] ?? [])
        );

        $merged[] = [
            'cve_id'          => $bv['cve_id'] ?? null,
            'title'           => $bv['title'] ?? ($match['title'] ?? null),
            'cvss_rating'     => $bv['cvss_rating'] ?? ($match['cvss_rating'] ?? null),
            'cvss_score'      => $bv['cvss_score'] ?? ($match['cvss_score'] ?? null),
            'patched_version' => nearest_patched_version($candidates, $installedVersion),
            'published_at'    => $bv['published_at'] ?? ($match['published_at'] ?? null),
            'sources'         => $match !== null ? ['blogvault', 'wordfence'] : ['blogvault'],
        ];
    }

    foreach ($wordfence as $i => $wf) {
        if (isset($matched[$i])) {
            continue;
        }
        $merged[] = [
            'cve_id'          => $wf['cve_id'] ?? null,
            'title'           => $wf['title'] ?? null,
            'cvss_rating'     => $wf['cvss_rating'] ?? null,
            'cvss_score'      => $wf['cvss_score'] ?? null,
            'patched_version' => nearest_patched_version((array) ($wf['patched_versions'] ?? []), $installedVersion),
            'published_at'    => $wf['published_at'] ?? null,
            'sources'         => ['wordfence'],
        ];
    }

    return $merged;
}

/**
 * The one patched version worth showing next to an installed version,
 * out of every candidate either source lists for the same vulnerability:
 * prefer the lowest patch **in the installed branch, at or above the
 * installed version** (the fix that applies without a branch change);
 * failing that, the lowest patch in any branch above the installed version
 * (the nearest upgrade path that actually carries the fix); failing that
 * (every known patch is already at or below what's installed), the highest
 * one seen, since there's nothing left to recommend. `null`/`""`/`"*"`
 * entries are ignored as non-answers, not treated as version "0".
 *
 * @param list<mixed> $candidates
 */
function nearest_patched_version(array $candidates, ?string $installedVersion): ?string
{
    $versions = array_values(array_unique(array_filter(
        $candidates,
        static fn ($v): bool => is_string($v) && $v !== '' && $v !== '*'
    )));
    if ($versions === []) {
        return null;
    }

    if ($installedVersion === null || $installedVersion === '') {
        usort($versions, 'version_compare');

        return $versions[0];
    }

    $branch = static fn (string $v): string => implode('.', array_slice(explode('.', $v), 0, 2));
    $installedBranch = $branch($installedVersion);

    $sameBranch = array_values(array_filter(
        $versions,
        static fn (string $v): bool => $branch($v) === $installedBranch && version_compare($v, $installedVersion, '>=')
    ));
    if ($sameBranch !== []) {
        usort($sameBranch, 'version_compare');

        return $sameBranch[0];
    }

    $above = array_values(array_filter(
        $versions,
        static fn (string $v): bool => version_compare($v, $installedVersion, '>')
    ));
    if ($above !== []) {
        usort($above, 'version_compare');

        return $above[0];
    }

    usort($versions, 'version_compare');

    return $versions[count($versions) - 1];
}

/**
 * One small, neutral tag per vulnerability source — never a merged "X + Y" label.
 *
 * @param list<string> $sources
 */
function vulnerability_source_badge(array $sources): string
{
    $labels = [
        'blogvault' => 'BlogVault',
        'wordfence' => 'Wordfence',
    ];

    return implode(' ', array_map(
        static fn (string $s): string => '<span class="badge badge-muted">' . e($labels[$s] ?? $s) . '</span>',
        $sources
    ));
}

/**
 * CVSS score + rating as one coloured badge: critical (dark red) and high
 * (red) are visually distinct from each other, not just from medium
 * (orange) — a page full of "High" and "Critical" rows in the same shade
 * hides exactly the distinction that matters most.
 */
function cvss_badge(mixed $score, ?string $rating): string
{
    if ($score === null) {
        return '—';
    }

    $cls = match (strtolower((string) $rating)) {
        'critical' => 'badge-critical',
        'high'     => 'badge-error',
        'medium'   => 'badge-warn',
        default    => 'badge-muted', // low, or no rating
    };

    return '<span class="badge ' . $cls . '">' . e($score) . ($rating ? ' (' . e($rating) . ')' : '') . '</span>';
}

/**
 * Multisite network sites, tallied by status. Accepts either a list of plain
 * status strings or a list of per-site objects carrying a `status` key —
 * the exact shape isn't exercised by the fixture (a single-site install has
 * nothing to report), so this stays defensive rather than assuming one.
 */
function fmt_status_tally(mixed $items): string
{
    if (!is_array($items) || $items === []) {
        return '—';
    }

    $tally = [];
    foreach ($items as $item) {
        $status = is_array($item) ? (string) ($item['status'] ?? 'unknown') : (string) $item;
        $tally[$status] = ($tally[$status] ?? 0) + 1;
    }

    $parts = [];
    foreach ($tally as $status => $count) {
        $parts[] = e($status) . ' <span class="val-muted">' . $count . '</span>';
    }

    return implode(' · ', $parts);
}

/**
 * "Last refreshed" banner for a reference cache (endoflife.date, wordpress.org,
 * Wordfence Intelligence…) — an analyst reading a table of external data has
 * no other way to tell whether it is current. `$maxAgeSeconds` is the refresh
 * cron's own interval (plus a little slack): older than that means a
 * scheduled refresh was missed, not just "not brand new".
 */
function fmt_refreshed(?string $isoDate, int $maxAgeSeconds): string
{
    if ($isoDate === null) {
        return '<span class="badge badge-error">Never refreshed</span>';
    }

    $age  = time() - (int) strtotime($isoDate);
    $cls  = $age > $maxAgeSeconds ? 'badge-warn' : 'badge-muted';

    return '<span class="badge ' . $cls . '">Last refreshed: ' . e($isoDate) . '</span>';
}

/**
 * "50 minutes ago", with the exact timestamp in a native tooltip (2026-09-02,
 * user: "je veux '50 minutes ago' et un tooltip contenant la date") — quick
 * to scan, never lossy: the real date is always one hover away.
 */
function fmt_relative_time(?string $isoDate): string
{
    if ($isoDate === null || $isoDate === '') {
        return '<span class="muted">—</span>';
    }

    $timestamp = strtotime($isoDate);
    if ($timestamp === false) {
        return e($isoDate);
    }

    $diff   = time() - $timestamp;
    $abs    = abs($diff);
    $suffix = $diff >= 0 ? 'ago' : 'from now';
    $unit   = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's') . ' ' . $suffix;

    $label = match (true) {
        $abs < 60         => 'just now',
        $abs < 3600       => $unit(intdiv($abs, 60), 'minute'),
        $abs < 86400      => $unit(intdiv($abs, 3600), 'hour'),
        $abs < 86400 * 30 => $unit(intdiv($abs, 86400), 'day'),
        default           => $unit(intdiv($abs, 86400 * 30), 'month'),
    };

    return '<span title="' . e($isoDate) . '">' . e($label) . '</span>';
}

/**
 * A small inline "copy to clipboard" button next to a value (2026-09-02,
 * client email). Pure JS (navigator.clipboard), no library — the value is
 * embedded in a data attribute rather than read from the DOM text, so it
 * works next to a link/badge too, not just plain text.
 */
function copy_button(string $value): string
{
    return '<button type="button" class="copy-btn" data-copy="' . e($value) . '" '
        . 'title="Copy" aria-label="Copy ' . e($value) . '" onclick="'
        . "navigator.clipboard.writeText(this.dataset.copy);"
        . "var b=this;b.classList.add('copied');setTimeout(function(){b.classList.remove('copied');},1000);"
        . '">⧉</button>';
}

/**
 * "Requires WP x.y / PHP x.y" for a plugin/theme row, flagged red when the
 * site's actual running version doesn't meet it — plain muted text told an
 * analyst nothing beyond the bare number, which is the plugin's declared
 * minimum, not a fact about this site; whether it's actually satisfied here
 * is the only reason to show it on a per-site report at all.
 */
function requirement_cell(?string $requiresWp, ?string $requiresPhp, ?string $installedWp, ?string $installedPhp): string
{
    $part = static function (string $label, ?string $required, ?string $installed): string {
        if ($required === null || $required === '') {
            return '';
        }
        $unmet = $installed !== null && $installed !== '' && version_compare($installed, $required, '<');

        return '<span' . ($unmet ? ' class="val-error"' : '') . '>' . e($label) . ' ' . e($required) . '</span>';
    };

    $parts = array_filter([
        $part('WP', $requiresWp, $installedWp),
        $part('PHP', $requiresPhp, $installedPhp),
    ]);

    return $parts === [] ? '—' : implode(' · ', $parts);
}

/**
 * Small inline icon set for the extraction report's section groups — hand-
 * drawn from basic SVG primitives (circle/rect/line/arc), not copied from an
 * icon library, so there is no external font/CDN dependency (this project
 * vendors its own assets, no CDN, no build step — see Datatables). `aria-hidden`
 * throughout: every icon sits next to a text label, never alone.
 */
function report_icon(string $name): string
{
    $inner = match ($name) {
        'overview'    => '<rect x="4" y="12" width="4" height="8"/><rect x="10" y="7" width="4" height="13"/><rect x="16" y="3" width="4" height="17"/>',
        'account'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8" cy="12" r="2"/><line x1="13" y1="10" x2="18" y2="10"/><line x1="13" y1="14" x2="17" y2="14"/>',
        'domain'      => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><line x1="3" y1="12" x2="21" y2="12"/>',
        'hosting'     => '<rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><circle cx="7" cy="7" r=".8" fill="currentColor" stroke="none"/><circle cx="7" cy="17" r=".8" fill="currentColor" stroke="none"/>',
        'wordpress'   => '<circle cx="12" cy="12" r="9"/><text x="12" y="16" font-size="10" font-weight="700" fill="currentColor" stroke="none" text-anchor="middle" font-family="Arial, sans-serif">W</text>',
        'plugins'     => '<rect x="4" y="4" width="12" height="12" rx="2"/><rect x="8" y="8" width="12" height="12" rx="2"/>',
        'content'     => '<rect x="5" y="3" width="14" height="18" rx="1.5"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/>',
        'users'       => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><circle cx="17" cy="8" r="2.2"/><path d="M15 20a5 5 0 0 1 8 0" opacity=".6"/>',
        'performance' => '<path d="M4 16a8 8 0 0 1 16 0"/><line x1="12" y1="16" x2="16" y2="10"/><circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/>',
        'seo'         => '<circle cx="10" cy="10" r="6"/><line x1="15" y1="15" x2="20" y2="20"/>',
        'security'    => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
        'raw'         => '<polyline points="9 6 3 12 9 18"/><polyline points="15 6 21 12 15 18"/>',
        'printer'     => '<rect x="6" y="9" width="12" height="7" rx="1"/><path d="M6 9V4h12v5"/><path d="M8 16v4h8v-4"/>',
        default       => '<circle cx="12" cy="12" r="9"/>',
    };

    return '<svg class="xt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

/** Pretty-printed JSON inside a collapsible block. */
function json_details(string $label, mixed $data, bool $open = false): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return '<details' . ($open ? ' open' : '') . '><summary>' . e($label) . '</summary>'
        . '<pre>' . e($json) . '</pre></details>';
}
