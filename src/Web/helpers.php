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

function dt_search_box(string $tableId): string
{
    return '<input type="search" class="xt-dt-search" data-table="#' . e($tableId) . '" placeholder="Search…">'
        . '<button type="button" class="btn btn-secondary xt-dt-search-btn" data-table="#' . e($tableId) . '">Search</button>';
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

/** Status badge (ok / warn / error / pending / queued / running / done / aborted). */
function badge(?string $status): string
{
    $status = $status ?? 'unknown';
    $class  = match ($status) {
        'ok', 'done'       => 'badge-ok',
        'pending'          => 'badge-pending',
        'warn', 'queued', 'running' => 'badge-warn',
        'error'            => 'badge-error',
        default            => 'badge-muted', // aborted is a deliberate skip, not something to act on
    };

    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

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
 * Trail back up the page hierarchy, e.g. Extractions › example.com › this
 * extraction. Each entry is [label, href]; a null href (or the last entry,
 * whichever comes first) renders as plain text for the current page.
 *
 * @param list<array{0: string, 1: string|null}> $trail
 */
function breadcrumb(array $trail): string
{
    if ($trail === []) {
        return '';
    }

    $last  = count($trail) - 1;
    $parts = [];
    foreach ($trail as $i => [$label, $href]) {
        $parts[] = ($href === null || $i === $last)
            ? '<span aria-current="page">' . e($label) . '</span>'
            : '<a href="' . e($href) . '">' . e($label) . '</a>';
    }

    return '<nav class="crumbs" aria-label="Breadcrumb">'
        . implode('<span class="crumbs-sep" aria-hidden="true">›</span>', $parts)
        . '</nav>';
}

/** Page-level banner: info, warning, critical or progress (spinner). $html is trusted, pre-rendered HTML. */
function notice(string $level, string $html): string
{
    $level = in_array($level, ['info', 'warning', 'critical', 'progress'], true) ? $level : 'info';
    $icon = match ($level) {
        'warning'  => '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" '
            . 'stroke-linecap="round" stroke-linejoin="round"><path d="M8.18 2.6 1.4 14.2A1.4 1.4 0 0 0 '
            . '2.62 16.3h12.76a1.4 1.4 0 0 0 1.22-2.1L9.82 2.6a1.4 1.4 0 0 0-2.64 0Z"/>'
            . '<line x1="9" y1="7" x2="9" y2="10.6"/><circle cx="9" cy="13.1" r=".2" fill="currentColor"/></svg>',
        'critical' => '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" '
            . 'stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="7.2"/>'
            . '<line x1="6.6" y1="6.6" x2="11.4" y2="11.4"/><line x1="11.4" y1="6.6" x2="6.6" y2="11.4"/></svg>',
        // Faint ring + bright arc; style.css spins it into a loading spinner.
        'progress' => '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" '
            . 'stroke-linecap="round"><circle cx="9" cy="9" r="7.1" stroke-opacity=".25"/>'
            . '<path d="M16.1 9a7.1 7.1 0 0 0-7.1-7.1"/></svg>',
        default    => '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" '
            . 'stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="7.2"/>'
            . '<line x1="9" y1="8.3" x2="9" y2="13"/><circle cx="9" cy="5.6" r=".2" fill="currentColor"/></svg>',
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

/** Link built from an `external_links.*` pattern ("{id}" substituted); plain text when there's no pattern or id. */
function external_link(?string $pattern, mixed $id, string $label): string
{
    $idString = $id !== null && $id !== '' ? (string) $id : null;
    if ($pattern === null || $pattern === '' || $idString === null) {
        return e($label);
    }

    $url = str_replace('{id}', rawurlencode($idString), $pattern);

    return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer" class="ext-link">'
        . e($label) . ' <span class="icon">' . icon_external_link() . '</span></a>';
}

/** Action-button variant of external_link(): renders nothing when there's nowhere to go. */
function external_link_button(?string $pattern, mixed $id, string $label): string
{
    $idString = $id !== null && $id !== '' ? (string) $id : null;
    if ($pattern === null || $pattern === '' || $idString === null) {
        return '';
    }

    $url = str_replace('{id}', rawurlencode($idString), $pattern);

    return '<a class="btn" style="margin:0;padding:.25rem .6rem;font-size:.8rem" href="' . e($url) . '" '
        . 'target="_blank" rel="noopener noreferrer">' . e($label) . ' <span class="icon">' . icon_external_link() . '</span></a>';
}

function external_link_icon(?string $pattern, mixed $id, string $title): string
{
    $idString = $id !== null && $id !== '' ? (string) $id : null;
    if ($pattern === null || $pattern === '' || $idString === null) {
        return '';
    }

    $url = str_replace('{id}', rawurlencode($idString), $pattern);

    return '<a class="icon-btn" href="' . e($url) . '" target="_blank" rel="noopener noreferrer" '
        . 'title="' . e($title) . '" aria-label="' . e($title) . '">' . icon_external_link() . '</a>';
}

/**
 * **bold**, _italic_ and [text](url) in an observation — the same markup the
 * report script resolves. Escapes first, then converts, so nothing raw slips
 * through a link. One pass, so a marker inside a URL is never re-read;
 * _italic_ only at word boundaries (snake_case names stay untouched).
 */
function format_observation_text(string $text): string
{
    return preg_replace_callback(
        '/\*\*(.+?)\*\*|(?<![\p{L}\p{N}])_(.+?)_(?![\p{L}\p{N}])|\[(.+?)\]\((https?:\/\/[^\s)]+)\)/su',
        static fn (array $m): string => match (true) {
            $m[1] !== ''         => '<strong>' . $m[1] . '</strong>',
            $m[2] !== ''         => '<em>' . $m[2] . '</em>',
            default              => '<a href="' . $m[4] . '" target="_blank" rel="noopener">' . $m[3] . '</a>',
        },
        e($text)
    ) ?? e($text);
}

function icon_edit(): string
{
    return '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M11.5 2.5l4 4L6 16H2v-4l9.5-9.5z"/><line x1="10" y1="4" x2="14" y2="8"/></svg>';
}

function icon_external_link(): string
{
    return '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M8 3H4a1.5 1.5 0 0 0-1.5 1.5v9A1.5 1.5 0 0 0 4 15h9a1.5 1.5 0 0 0 1.5-1.5V9.5"/>'
        . '<path d="M10.5 2.5H15v4.5"/><line x1="7.5" y1="10.5" x2="14.5" y2="3.5"/></svg>';
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
        // requestSubmit() fires the submit event layout.php intercepts; submit() does not.
        . '<select name="license" class="' . $cls . '" onchange="this.form.requestSubmit()">' . $options . '</select>'
        . '</form>';
}

/**
 * This install's licence-key status as of this extraction (licenses.json) —
 * unlike license_select(), which is the cross-site free/premium classification.
 */
function license_status_select(
    string $siteId,
    string $extractionId,
    string $type,
    string $slug,
    string $current,
    string $csrf,
    string $return
): string {
    $labels = [
        'n_a'         => 'N/A',
        'active'      => 'Active license',
        'missing'     => 'Missing license',
        'to_validate' => 'To validate',
    ];
    $options = '';
    foreach ($labels as $status => $label) {
        $selected = $status === $current ? ' selected' : '';
        $options .= '<option value="' . $status . '"' . $selected . '>' . e($label) . '</option>';
    }

    return '<form method="post" action="/site/' . e($siteId) . '/extraction/' . e($extractionId) . '/licenses" class="lic-form">'
        . '<input type="hidden" name="_csrf" value="' . e($csrf) . '">'
        . '<input type="hidden" name="type" value="' . e($type) . '">'
        . '<input type="hidden" name="slug" value="' . e($slug) . '">'
        . '<input type="hidden" name="return" value="' . e($return) . '">'
        . '<select name="status" class="lic-' . e($current) . '" onchange="this.form.requestSubmit()">' . $options . '</select>'
        . '</form>';
}

/**
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
            : '<span class="val-error">Unassigned</span>')
        . ' <button type="button" class="wf-edit-btn" data-wf-id="' . $subId . '" '
        . 'title="Change linked website" aria-label="Change linked website">' . icon_edit() . '</button>'
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
        . '<button type="button" class="btn btn-muted wf-cancel-btn" style="padding:.25rem .6rem">Cancel</button>'
        . '</form>';

    return $display . $form;
}

/** Colored pastille (green/orange/red/purple/blue/grey) + label — the analyst signal. */
function pastille(string $color, string $label): string
{
    return '<span class="pastille pastille-' . e($color) . '" title="' . e($label) . '">'
        . '<span class="dot"></span>' . e($label) . '</span>';
}

/** Label/value card from field()/field_raw() rows; $class e.g. "card-full" spans the grid. */
function section(string $title, string $rows, string $badge = '', string $class = ''): string
{
    if (trim($rows) === '') {
        return '';
    }

    return '<section class="card info-card' . ($class !== '' ? ' ' . e($class) : '') . '"><h3>' . e($title) . ($badge !== '' ? ' ' . $badge : '')
        . '</h3><table class="kv"><tbody>' . $rows . '</tbody></table></section>';
}

/**
 * A WordPress count map (wp_count_posts() & co. arrive verbatim, keyed by
 * status/mime/role): the first named key present, else the sum without trash.
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

function field(string $label, mixed $value, ?string $status = null, ?string $source = null): string
{
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    // A structure would render the literal word "Array".
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
 * "ⓘ" provenance marker: the value's dot-path and whether Xtractor measured
 * it or relays what the plugin reported — one sentence per path root.
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

/** Like fmt_list(), but one item per line instead of comma-separated — a name list read easier this way. */
function fmt_lines(mixed $items, int $max = 30): string
{
    if (!is_array($items) || $items === []) {
        return '—';
    }

    $shown = array_slice($items, 0, $max);
    $more  = count($items) - count($shown);

    return implode('<br>', array_map(static fn ($i): string => e((string) $i), $shown))
        . ($more > 0 ? '<br><span class="val-muted">+' . $more . '</span>' : '');
}

/**
 * One component's BlogVault + Wordfence vulnerabilities, merged by CVE — see Rules\VulnerabilityMerge.
 *
 * @param list<array<string, mixed>> $blogvault
 * @param list<array<string, mixed>> $wordfence
 * @param list<string> $ignored ids configured under vulnerabilities.ignored
 * @return list<array<string, mixed>>
 */
function merge_vulnerabilities(array $blogvault, array $wordfence, ?string $installedVersion = null, array $ignored = []): array
{
    return \SatelliteWP\Xtractor\Rules\VulnerabilityMerge::merge($blogvault, $wordfence, $installedVersion, $ignored);
}

/** @param list<mixed> $candidates */
function nearest_patched_version(array $candidates, ?string $installedVersion): ?string
{
    return \SatelliteWP\Xtractor\Rules\VulnerabilityMerge::nearestPatchedVersion($candidates, $installedVersion);
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

/** CVSS badge banded by score (same bands as /data/vulnerabilities); the rating goes in the tooltip. */
function cvss_badge(mixed $score, ?string $rating): string
{
    if ($score === null) {
        return '—';
    }

    $score = (float) $score;
    $cls   = match (true) {
        $score >= 9.0 => 'badge-critical',
        $score >= 8.1 => 'badge-error',
        $score >= 6.1 => 'badge-warn',
        // Never green: green means compliant, not a low-severity flaw.
        default => 'badge-low',
    };
    $title = 'CVSS ' . $score . ($rating ? ' — ' . $rating : '');

    return '<span class="badge ' . $cls . '" title="' . e($title) . '">' . e($score) . '</span>';
}

/** Multisite sites tallied by status; accepts status strings or objects with a `status` key. */
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

/** "Last refreshed" badge for a reference cache; warns past $maxAgeSeconds (a missed scheduled refresh). */
function fmt_refreshed(?string $isoDate, int $maxAgeSeconds, string $label = 'Last refreshed', ?string $title = null): string
{
    $titleAttr = $title !== null ? ' title="' . e($title) . '"' : '';
    if ($isoDate === null) {
        return '<span class="badge badge-error"' . $titleAttr . '>Never refreshed</span>';
    }

    $age  = time() - (int) strtotime($isoDate);
    $cls  = $age > $maxAgeSeconds ? 'badge-warn' : 'badge-muted';

    return '<span class="badge ' . $cls . '"' . $titleAttr . '>' . e($label) . ': ' . e($isoDate) . '</span>';
}

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

    return '<span class="text-subtle" data-tippy-content="' . e($isoDate) . '">' . e($label) . '</span>';
}

function copy_button(string $value): string
{
    return '<button type="button" class="copy-btn" data-copy="' . e($value) . '" '
        . 'title="Copy" aria-label="Copy ' . e($value) . '" onclick="'
        . "navigator.clipboard.writeText(this.dataset.copy);"
        . "var b=this;b.classList.add('copied');setTimeout(function(){b.classList.remove('copied');},1000);"
        . '">⧉</button>';
}

/** "WP x.y · PHP x.y" requirements, red where the site's running version doesn't meet them. */
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

/** Inline SVG icons for the report's groups; aria-hidden since each sits next to a text label. */
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
