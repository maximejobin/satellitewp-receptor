<?php
/**
 * @var \SatelliteWP\Manager\Rules\Translator $t
 */
use SatelliteWP\Manager\Catalog\SoftwareCatalog;
use SatelliteWP\Manager\Rules\Category;
use SatelliteWP\Manager\Rules\Pastille;
use SatelliteWP\Manager\Web\HardeningConstants;

$p    = $payload;
$dns  = $probes['dns']['data'] ?? [];
$mail = $probes['mail']['data'] ?? [];
$crm  = $probes['crm']['data'] ?? [];
$tls  = $probes['tls']['data'] ?? [];
$rdap = $probes['rdap']['data'] ?? [];
$http = $probes['http']['data'] ?? [];
$ps   = $probes['pagespeed']['data'] ?? [];
$bv    = $probes['blogvault']['data'] ?? [];
$wf    = $probes['wordfence']['data'] ?? [];
$wporg = $probes['wporg']['data'] ?? [];

// Normalized slug -> that component's vulnerability record, per source.
$bvPluginsBySlug = array_column($bv['plugins']['items'] ?? [], null, 'slug');
$wfPluginsBySlug = array_column($wf['plugins']['items'] ?? [], null, 'slug');
$bvThemesBySlug  = array_column($bv['themes']['items'] ?? [], null, 'slug');
$wfThemesBySlug  = array_column($wf['themes']['items'] ?? [], null, 'slug');

// Merged once, shared by the WordPress card and the CVE table so they agree.
$coreVulns = merge_vulnerabilities($bv['core']['vulnerabilities'] ?? [], $wf['core']['vulnerabilities'] ?? [], $p['wp_version'] ?? null, $ignoredVulnerabilities);

/**
 * State column: Active/Inactive, then Auto-update/Vulnerable/Abandoned badges.
 * "Abandoned" uses F8's default 365-day threshold, read from probe.wporg
 * directly (a rules.thresholds.F8 override is not reflected here).
 *
 * @param list<array<string, mixed>> $merged
 * @param array<string, mixed>|null $wporgEntry probe.wporg.plugins[slug] / .themes[slug]
 */
$stateCell = static function (bool $inactive, array $merged, ?array $wporgEntry, bool $autoUpdate = false): string {
    $html = !$inactive
        ? '<span class="badge badge-ok">Active</span>'
        : '<span class="badge badge-muted">Inactive</span>';

    if ($autoUpdate) {
        $html .= ' <span class="badge badge-ok">Auto-update</span>';
    }
    if ($merged !== []) {
        $html .= ' <span class="badge badge-error">Vulnerable</span>';
    }

    $lastUpdated = is_array($wporgEntry) ? ($wporgEntry['last_updated'] ?? null) : null;
    $timestamp   = is_string($lastUpdated) ? strtotime($lastUpdated) : false;
    if (!empty($wporgEntry['on_wporg']) && $timestamp !== false && $timestamp < time() - 365 * 86400) {
        $html .= ' <span class="badge badge-warn">Abandoned</span>';
    }

    return $html;
};

/** Name linked to its wordpress.org page only when WporgProbe found it there; raw slug underneath. */
$nameCell = static function (string $type, string $name, string $rawSlug, string $normalizedSlug, ?array $wporgEntry): string {
    $label = is_array($wporgEntry) && !empty($wporgEntry['on_wporg'])
        ? external_link('https://wordpress.org/' . $type . 's/{id}/', $normalizedSlug, $name)
        : e($name);

    return $label . '<div class="muted mono" style="font-size:.82em">' . e($rawSlug) . '</div>';
};

/**
 * Active theme first, then its parent (shown active: a child theme loads it),
 * then the rest in their original order.
 *
 * @param array<string, array<string, mixed>> $themes keyed by theme file
 * @return array<string, array<string, mixed>>
 */
$orderThemes = static function (array $themes): array {
    $activeFile = null;
    foreach ($themes as $file => $th) {
        if (!empty($th['active'])) {
            $activeFile = $file;
            break;
        }
    }
    if ($activeFile === null) {
        return $themes;
    }

    $active = $themes[$activeFile];
    unset($themes[$activeFile]);

    $parentSlug = strtolower((string) ($active['parent_slug'] ?? ''));
    $parentFile = null;
    if ($parentSlug !== '' && $parentSlug !== strtolower((string) ($active['slug'] ?? ''))) {
        foreach ($themes as $file => $th) {
            if (strtolower((string) ($th['slug'] ?? '')) === $parentSlug) {
                $parentFile = $file;
                break;
            }
        }
    }

    $ordered = [$activeFile => $active];
    if ($parentFile !== null) {
        $parent           = $themes[$parentFile];
        $parent['active'] = true;
        $ordered[$parentFile] = $parent;
        unset($themes[$parentFile]);
    }

    return $ordered + $themes;
};

$eolPhp = $eol->eolStatus('php', (string) ($p['php']['version'] ?? ''));
$eolWp  = $eol->eolStatus('wordpress', (string) ($p['wp_version'] ?? ''));
$dbType = str_contains(strtolower((string) ($p['database_type'] ?? '')), 'maria') ? 'mariadb'
    : (str_contains(strtolower((string) ($p['database_type'] ?? '')), 'mysql') ? 'mysql' : null);
$eolDb  = $dbType !== null ? $eol->eolStatus($dbType, (string) ($p['database_version'] ?? '')) : null;

$all      = $findings['findings'] ?? [];
$counts   = $findings['counts'] ?? ['by_pastille' => [], 'total' => 0];
$byPast   = $counts['by_pastille'] ?? [];

// category -> finding count, for the filter bar
$catCount = [];
foreach ($all as $f) { $catCount[$f['category']] = ($catCount[$f['category']] ?? 0) + 1; }

$stripe = static function (string $c): string {
    $pastille = Pastille::tryFrom($c);

    return $pastille !== null && ($pastille->needsAttention() || $pastille === Pastille::Blue) ? "sev-{$c}" : '';
};
$attentionCount = 0;
foreach (Pastille::cases() as $pastille) {
    if ($pastille->needsAttention()) {
        $attentionCount += (int) ($byPast[$pastille->value] ?? 0);
    }
}
$mobilePs = $ps['mobile'] ?? (is_array(reset($ps)) ? reset($ps) : []);

// Categories per page group, for each group header's pass-rate bar — grouped
// by where the topic renders on this page (HTTP sits under Performance).
$groupCategories = [
    'infrastructure' => [Category::DOMAIN, Category::EMAIL, Category::DNS, Category::SSL, Category::PHP, Category::DATABASE, Category::HOSTING, Category::CRON],
    'content'        => [Category::UPDATES, Category::USERS, Category::CONTENT],
    'quality'        => [Category::HTTP, Category::SECURITY, Category::PERFORMANCE, Category::SEO, Category::CACHE],
];
/** @return array{pass: int, fail: int, rate: int}|null null when nothing in this group applies to this site */
$groupRate = static function (string $group) use ($all, $groupCategories): ?array {
    $pass = 0;
    $fail = 0;
    foreach ($all as $f) {
        if (!in_array($f['category'], $groupCategories[$group], true)) {
            continue;
        }
        if ($f['pastille'] === Pastille::Green->value) {
            $pass++;
        } elseif (in_array($f['pastille'], [Pastille::Red->value, Pastille::Orange->value], true)) {
            $fail++;
        }
    }
    $applicable = $pass + $fail;

    return $applicable > 0 ? ['pass' => $pass, 'fail' => $fail, 'rate' => (int) round($pass / $applicable * 100)] : null;
};
/** Pass-rate bar for a group header; nothing before findings exist. */
$groupBadge = static function (string $group) use ($groupRate, $findings): string {
    if ($findings === null) {
        return '';
    }
    $r = $groupRate($group);
    if ($r === null) {
        return '';
    }

    return '<div class="xt-group-rate"><div class="xt-group-rate-bar"><div style="width:' . $r['rate'] . '%"></div></div>'
        . '<span>' . $r['rate'] . '% passing' . ($r['fail'] > 0 ? ' · ' . $r['fail'] . ' need attention' : '') . '</span></div>';
};
?>

<?php
$status = (string) ($row['status'] ?? '');
echo match ($notice) {
    'rerun-done'   => notice('info', 'Probes re-run and findings re-evaluated.'),
    'rerun-none'   => notice('warning', 'No probe selected — nothing was re-run.'),
    'rerun-failed' => notice('critical', 'The re-run failed' . ($noticeRef !== '' ? ' (log ref <span class="mono">' . e($noticeRef) . '</span>)' : '') . '.'),
    default        => '',
};
if ($status !== 'done'):
    $bvFound = ($blogVault['found'] ?? false) === true;
?>
    <?= breadcrumb([
        [$t->ui('sites'), '/extractions'],
        [site_display($site['site_url'] ?? '') ?: $siteId, '/site/' . $siteId],
        [$extractionId, null],
    ]) ?>
    <h1><?= e(site_display($site['site_url'] ?? '') ?: $siteId) ?></h1>
    <p class="muted">
        <a href="<?= e($site['site_url'] ?? '#') ?>"><?= e($site['site_url'] ?? '') ?></a>
        · <?= e($t->ui('received')) ?> <?= e($meta['received_at'] ?? '?') ?>
        <?php if (!in_array($status, ['queued', 'running'], true)): ?>
            · <?= badge($row['status'] ?? null) ?>
        <?php endif; ?>
    </p>
    <!-- Nothing below renders before status "done": partial data must never read as a report. -->
    <section class="section">
        <h2>Analysis</h2>
        <div style="padding:0 1.1rem 1.1rem">
        <?php if ($status === 'queued' || $status === 'running'): ?>
            <?= notice('progress', ($status === 'running'
                ? 'The analysis is running.'
                : 'Queued — waiting for the worker (cron <span class="mono">ingest:process</span>, every minute).')
                . ' This page will refresh automatically once it\'s done.') ?>
            <script>setTimeout(function () { location.reload(); }, 5000);</script>
        <?php elseif ($status === 'error'): ?>
            <p><span class="badge badge-error">Error</span>
               The last analysis failed. Check the worker logs if needed, then retry.</p>
            <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/run">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="return" value="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>">
                <button type="submit" class="btn">Retry analysis</button>
            </form>
        <?php elseif ($status === 'aborted'): ?>
            <p><span class="badge badge-muted">Aborted</span>
               This extraction was not analysed — an analyst chose to skip it.</p>
        <?php else: ?>
            <p class="muted">This extraction was received but <b>not analysed yet</b>.
               No probe has run, no quota has been spent.</p>

            <?php
            // A 401 here means every external probe would come back empty;
            // "no credentials stored" and "stored ones refused" need different fixes.
            $authBlocked = ($httpAuth['required'] ?? false) === true;
            if ($authBlocked): ?>
                <?= notice('warning',
                    '<b>This site is behind HTTP Basic Auth.</b> It answered <span class="mono">HTTP 401</span>, so '
                    . 'every external check — security headers, exposure, robots/sitemap, PageSpeed — would come back '
                    . 'empty. '
                    . (($httpAuth['configured'] ?? false) === true
                        ? 'Credentials <b>are</b> stored for this site but were not accepted — check them under '
                        : 'Add this site\'s credentials under ')
                    . '<a href="/site/' . e($siteId) . '">⚙ Site settings</a>, then reload this page to re-check.'
                ) ?>
            <?php elseif (!empty($httpAuth['error'])): ?>
                <p><span class="badge badge-warn">Site unreachable</span>
                   <span class="mono"><?= e($httpAuth['error']) ?></span> — external checks may come back empty.</p>
            <?php elseif (($httpAuth['checked'] ?? false) === true && ($httpAuth['configured'] ?? false) === true): ?>
                <?php // Credentials were sent, so this says nothing about whether the site needs them. ?>
                <p><span class="badge badge-ok">Credentials accepted</span>
                   The site answered <span class="mono">HTTP <?= e($httpAuth['status']) ?></span> with the stored
                   credentials — external checks will run.</p>
            <?php elseif (($httpAuth['checked'] ?? false) === true): ?>
                <p><span class="badge badge-ok">Publicly reachable</span>
                   The site answered <span class="mono">HTTP <?= e($httpAuth['status']) ?></span> anonymously.</p>
            <?php endif; ?>

            <?php if (($blogVault['configured'] ?? false) !== true): ?>
                <p><span class="badge badge-muted">BlogVault not configured</span>
                   Cannot check whether this site is managed there.</p>
            <?php elseif (!empty($blogVault['error'])): ?>
                <p><span class="badge badge-warn">BlogVault unreachable</span>
                   <span class="mono"><?= e($blogVault['error']) ?></span></p>
            <?php elseif ($bvFound): ?>
                <p><span class="badge badge-ok">On BlogVault</span>
                   <?= e($blogVault['name'] ?? '') ?>
                   <span class="mono muted"><?= e($blogVault['id'] ?? '') ?></span></p>
            <?php else: ?>
                <p><span class="badge badge-error">Not on BlogVault</span>
                   <span class="mono"><?= e($blogVault['host'] ?? '') ?></span> is not in the account —
                   this site is probably not under a maintenance plan.
                   Rules <span class="mono">BV1</span>–<span class="mono">BV6</span> will stay indeterminate.</p>
            <?php endif; ?>

            <?php
            $runWarnings = [];
            if ($authBlocked) {
                $runWarnings[] = ($httpAuth['configured'] ?? false) === true
                    ? 'This site answered HTTP 401 even with the stored credentials — every external check will come back empty.'
                    : 'This site is behind HTTP Basic Auth and no credentials are stored — every external check will come back empty.';
            }
            if (!$bvFound) {
                $runWarnings[] = 'This site is not on BlogVault.';
            }
            $confirm = $runWarnings === [] ? '' : 'onsubmit="return confirm('
                . e(json_encode(implode("\n\n", $runWarnings) . "\n\nRun the analysis anyway?"))
                . ')"';
            ?>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
                <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/run" <?= $confirm ?>
                      style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="return" value="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>">
                    <label style="display:flex;gap:.4rem;align-items:center;font-size:.85rem" class="muted">
                        Language
                        <select name="language" style="padding:.35rem .5rem;font:inherit;color:var(--text)" title="PageSpeed locale and the Google Docs report's default language">
                            <option value="fr" selected>Français</option>
                            <option value="en">English</option>
                        </select>
                    </label>
                    <button type="submit" class="btn"><?= $runWarnings === [] ? 'Run analysis' : 'Run analysis anyway' ?></button>
                </form>
                <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/abort"
                      onsubmit="return confirm('Abort this extraction? Its data will not be analysed — you can still run a fresh extraction from the site later.')">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="return" value="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>">
                    <button type="submit" class="btn btn-secondary">Abort</button>
                </form>
            </div>
        <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($status === 'done'): ?>
<div class="xt-report">

    <!-- Hero -->
    <div class="xt-hero" id="overview">
        <?= breadcrumb([
            [$t->ui('sites'), '/extractions'],
            [site_display($site['site_url'] ?? '') ?: $siteId, '/site/' . $siteId],
            [$extractionId, null],
        ]) ?>
        <div class="xt-hero-top">
            <div class="xt-hero-main">
                <div class="xt-hero-title">
                    <div class="xt-hero-eyebrow">Health &amp; Security Report</div>
                    <h1><?= e(site_display($site['site_url'] ?? '') ?: $siteId) ?></h1>
                    <div class="xt-hero-url">
                        <a href="<?= e($site['site_url'] ?? '#') ?>"><?= e($site['site_url'] ?? '') ?></a>
                        · <?= e($t->ui('received')) ?> <?= e($meta['received_at'] ?? '?') ?>
                        · signature <?= !empty($meta['signature_valid']) ? 'valid' : 'absent/unverified' ?>
                        · <?= badge($row['status'] ?? null) ?>
                    </div>
                </div>
            </div>
            <div class="xt-hero-actions">
                <button type="button" class="btn-ghost" data-print><?= report_icon('printer') ?> Print report</button>
                <?php if ($findings !== null): ?>
                    <a class="btn-ghost mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/findings"><?= report_icon('raw') ?> findings.json</a>
                    <button type="button" class="btn-ghost" id="xt-report-key-btn"
                            data-action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/report-token"
                            data-csrf="<?= e($csrf) ?>" title="Copy a one-hour link for the Google Docs report template">
                        <?= report_icon('raw') ?> Report data key
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($findings !== null): ?>
            <div class="xt-sevbar" role="img" aria-label="<?= e($counts['total'] ?? 0) ?> checks total">
                <?php $sevTotal = max(1, (int) ($counts['total'] ?? 0));
                foreach (Pastille::values() as $c):
                    $n = (int) ($byPast[$c] ?? 0);
                    if ($n === 0) { continue; } ?>
                    <div class="xt-sevbar-seg dot-<?= $c ?>" style="width:<?= round($n / $sevTotal * 100, 2) ?>%" title="<?= e($t->pastille($c)) ?>: <?= e($n) ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="xt-hero-tally">
                <?php foreach (Pastille::values() as $c): ?>
                    <span class="chip"><span class="dot dot-<?= $c ?>"></span><?= e($t->pastille($c)) ?> <b><?= e($byPast[$c] ?? 0) ?></b></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sticky section nav -->
    <nav class="xt-nav">
        <a href="#overview" style="--group-color:var(--group-overview)"><?= report_icon('overview') ?> Overview</a>
        <a href="#infrastructure" style="--group-color:var(--group-infra)"><?= report_icon('hosting') ?> Infrastructure</a>
        <a href="#content-access" style="--group-color:var(--group-content)"><?= report_icon('plugins') ?> Content &amp; Access</a>
        <a href="#quality-security" style="--group-color:var(--group-quality)"><?= report_icon('performance') ?> Quality &amp; Security</a>
        <a href="#observations" style="--group-color:var(--accent)"><?= report_icon('raw') ?> Observations</a>
        <a href="#raw-data" style="--group-color:var(--muted)"><?= report_icon('raw') ?> Raw data</a>
    </nav>

    <!-- ============================== OVERVIEW ============================== -->
    <section class="xt-group" data-nav-target="overview" style="--group-color:var(--group-overview)">
        <section class="tiles xt-stats">
            <div class="xt-stat<?= ($eolWp[0] ?? false) ? ' crit' : '' ?>"><?= report_icon('wordpress') ?>
                <div><div class="xt-stat-k">WordPress</div><div class="xt-stat-v"><?= e($p['wp_version'] ?? '—') ?></div>
                    <div class="xt-stat-s<?= ($eolWp[0] ?? false) ? ' crit' : '' ?>"><?= ($eolWp[0] ?? false) ? 'end of life' : 'core' ?></div></div></div>
            <div class="xt-stat<?= ($eolPhp[0] ?? false) ? ' crit' : '' ?>"><?= report_icon('hosting') ?>
                <div><div class="xt-stat-k">PHP</div><div class="xt-stat-v"><?= e($p['php']['version'] ?? '—') ?></div>
                    <div class="xt-stat-s<?= ($eolPhp[0] ?? false) ? ' crit' : '' ?>"><?= isset($eolPhp[1]) ? 'until ' . e(substr((string) $eolPhp[1], 0, 4)) : '' ?></div></div></div>
            <div class="xt-stat<?= ($eolDb[0] ?? false) ? ' crit' : '' ?>"><?= report_icon('hosting') ?>
                <div><div class="xt-stat-k">Database</div><div class="xt-stat-v"><?= e(($p['database_type'] ?? '') . ' ' . implode('.', array_slice(explode('.', (string) ($p['database_version'] ?? '')), 0, 2))) ?></div>
                    <div class="xt-stat-s<?= ($eolDb[0] ?? false) ? ' crit' : '' ?>"><?= ($eolDb[0] ?? false) ? ('EOL ' . e(substr((string) ($eolDb[1] ?? ''), 0, 7))) : '' ?></div></div></div>
            <div class="xt-stat<?= (($tls['days_to_expiry'] ?? 999) < 30) ? ' warn' : '' ?>"><?= report_icon('security') ?>
                <div><div class="xt-stat-k">SSL expiry</div><div class="xt-stat-v"><?= isset($tls['days_to_expiry']) ? e($tls['days_to_expiry']) . ' d' : '—' ?></div>
                    <div class="xt-stat-s"><?= e($tls['issuer'] ?? '') ?></div></div></div>
            <div class="xt-stat<?= (($rdap['days_to_expiry'] ?? 999) < 30) ? ' warn' : '' ?>"><?= report_icon('domain') ?>
                <div><div class="xt-stat-k">Domain expiry</div><div class="xt-stat-v"><?= isset($rdap['days_to_expiry']) ? e($rdap['days_to_expiry']) . ' d' : '—' ?></div>
                    <div class="xt-stat-s<?= (($rdap['days_to_expiry'] ?? 999) < 30) ? ' warn' : '' ?>"><?= (($rdap['days_to_expiry'] ?? 999) < 30) ? 'renew soon' : '' ?></div></div></div>
            <div class="xt-stat"><?= report_icon('performance') ?>
                <div><div class="xt-stat-k">HTTP</div><div class="xt-stat-v">HTTP/<?= e($http['http_version'] ?? '?') ?></div>
                    <div class="xt-stat-s"><?= isset($http['content_encoding']) ? e($http['content_encoding']) : '' ?></div></div></div>
        </section>

        <?php if ($findings !== null): ?>
            <!-- Findings — full, filterable by type -->
            <section class="findings">
                <header>
                    <h3><?= e($t->ui('findings')) ?></h3>
                    <span class="muted"><?= e($counts['total'] ?? 0) ?> checks · <?= e($counts['fail'] ?? 0) ?> need attention</span>
                    <div class="spacer"></div>
                    <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/findings">findings.json</a>
                </header>
                <div class="filt">
                    <button class="on" data-filter="all">All <b><?= e($counts['total'] ?? 0) ?></b></button>
                    <button data-filter="attn">Needs attention <b><?= e($attentionCount) ?></b></button>
                    <span class="filt-sep"></span>
                    <?php foreach ($catCount as $cat => $n): ?>
                        <button data-filter="<?= e($cat) ?>"><?= e($t->category($cat)) ?> <b><?= e($n) ?></b></button>
                    <?php endforeach; ?>
                </div>
                <table class="ftable"><tbody>
                <?php foreach ($all as $f): ?>
                    <?php $col = $f['pastille']; ?>
                    <tr class="frow <?= $stripe($col) ?>" data-cat="<?= e($f['category']) ?>" data-attn="<?= Pastille::tryFrom((string) $col)?->needsAttention() ? '1' : '0' ?>">
                        <td><?= pastille($col, $t->pastille($col)) ?></td>
                        <td class="id"><?= e($f['id']) ?></td>
                        <td class="tag"><?= e($t->category($f['category'])) ?></td>
                        <td class="rule"><?= e($t->title($f['id'], $f['status'] ?? null)) ?></td>
                        <td class="obs"><?= e($t->message($f) ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </section>
        <?php endif; ?>
    </section>

    <!-- ============================== INFRASTRUCTURE ============================== -->
    <!-- Account & plan · Domain & email · Hosting · WordPress -->
    <section class="xt-group" id="infrastructure" data-nav-target="infrastructure" style="--group-color:var(--group-infra)">
        <header class="xt-group-head">
            <span class="xt-icon-badge"><?= report_icon('hosting') ?></span>
            <div><h2>Infrastructure</h2><p class="muted">Account, domain, hosting environment and WordPress core settings — in the order an analyst checks them.</p></div>
            <?= $groupBadge('infrastructure') ?>
        </header>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('account') ?><h3>Account &amp; plan</h3></div>
            <?php
            $crmReasons = [
                'no_blogvault_id'   => 'This site is not linked in BlogVault, so it cannot be matched to the CRM.',
                'website_not_found' => 'No CRM website carries this BlogVault site id.',
                'website_ambiguous' => 'Several CRM websites carry this BlogVault site id — not guessed.',
                'no_client'         => 'The CRM website has no subscription, hence no client.',
            ];
            $crmPlan = $crm['maintenance_plan'] ?? null;
            $crmNote = ($crm['linked'] ?? false) ? (is_array($crmPlan) ? null : 'No single active maintenance plan') : ($crmReasons[$crm['reason'] ?? ''] ?? null);
            if ($crm === []) : ?>
                <div class="pending-note">No CRM snapshot for this extraction — run the <code>crm</code> probe to link it.</div>
            <?php elseif (($probes['crm']['status'] ?? '') === 'error') : ?>
                <div class="pending-note" style="border-color:var(--warn);background:var(--bg-warn)">CRM snapshot failed: <?= e(implode(' ', (array) ($probes['crm']['errors'] ?? []))) ?></div>
            <?php else : ?>
                <div class="cards">
                    <?php echo section('Client & plan',
                        field('Client', implode(', ', array_column((array) ($crm['clients'] ?? []), 'label')), null, 'probe.crm.clients')
                        . field('Maintenance plan', is_array($crmPlan) ? $crmPlan['name'] : null, null, 'probe.crm.maintenance_plan')
                        . field('Next renewal', is_array($crmPlan) ? $crmPlan['next_renewal'] : null, null, 'probe.crm.maintenance_plan')
                        . field('CRM website', $crm['website']['url'] ?? null, null, 'probe.crm.website')
                        . ($crmNote !== null ? field('Note', $crmNote, 'warn', 'probe.crm.reason') : '')
                    ); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('domain') ?><h3>Domain &amp; email</h3></div>
            <div class="cards">
                <?php echo section('Domain',
                    field('Registrar', $rdap['registrar'] ?? null, null, 'probe.rdap.registrar')
                    . field('Created', $rdap['created_at'] ?? null, null, 'probe.rdap.created_at')
                    . field('Updated', $rdap['updated_at'] ?? null, null, 'probe.rdap.updated_at')
                    . field_raw('Expires', e($rdap['expires_at'] ?? '—') . (isset($rdap['days_to_expiry'])
                        ? ' <span class="' . ($rdap['days_to_expiry'] < 30 ? 'val-warn' : 'val-muted') . '">(' . e($rdap['days_to_expiry']) . ' d)</span>' : ''), null, 'probe.rdap.expires_at')
                    . field('Statuses', is_array($rdap['statuses'] ?? null) ? implode(', ', $rdap['statuses']) : null, null, 'probe.rdap.statuses')
                    . field_raw('Nameservers', fmt_list($rdap['nameservers'] ?? ($dns['nameservers'] ?? [])), null, 'probe.rdap.nameservers')
                    . field('Source', $rdap['source'] ?? null, null, 'probe.rdap.source')
                ); ?>
                <?php
                $mailFound   = ($mail['found'] ?? false) === true;
                $mailVerdict = static fn (string $label, string $key): string => field(
                    $label,
                    $mailFound ? ($mail[$key] ?? null) : ($mail === [] ? null : 'no test email found'),
                    $mailFound ? (($mail[$key] ?? null) === 'pass' ? 'ok' : (($mail[$key] ?? null) === null ? null : 'warn')) : null,
                    'probe.mail.' . $key
                );
                echo section('Email',
                    $mailVerdict('SPF (test email)', 'spf')
                    . $mailVerdict('DKIM (test email)', 'dkim')
                    . $mailVerdict('DMARC (test email)', 'dmarc')
                    . field('Test email received', $mailFound ? trim(($mail['received_at'] ?? '') . ' · ' . ($mail['from_domain'] ?? ''), ' ·') : null, null, 'probe.mail.received_at')
                    . field_raw('SPF record (DNS)', '<span class="mono">' . e($dns['spf']['record'] ?? '—') . '</span>', null, 'probe.dns.spf.record')
                    . field('DMARC policy (DNS)', ($dns['dmarc']['present'] ?? false) ? ('p=' . ($dns['dmarc']['policy'] ?? 'none')) : 'absent', null, 'probe.dns.dmarc')
                    . field_raw('MX', fmt_list(array_map(static fn ($m) => $m['host'] ?? '', $dns['mx'] ?? [])), null, 'probe.dns.mx')
                ); ?>
            </div>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('hosting') ?><h3>Hosting</h3></div>
            <div class="cards cards-full">
                <?php echo section('Server',
                    field('Web server', $p['web_server'] ?? null, null, 'payload.web_server')
                    . field('Operating system', $p['os_name'] ?? ($p['os_family'] ?? null), null, 'payload.os_name')
                    . field_raw('IP address (A / AAAA)', fmt_list($dns['a'] ?? []) . ' · ' . (($dns['aaaa'] ?? []) ? fmt_list($dns['aaaa']) : '<span class="val-muted">no IPv6</span>'), null, 'probe.dns.a')
                    . field('Hosting provider', 'from ASN lookup — coming soon', 'muted')
                    . field('Document root', $p['document_root'] ?? null, null, 'payload.document_root')
                    . field('Max execution time', ($p['php']['max_execution_time'] ?? null) !== null ? $p['php']['max_execution_time'] . ' s' : null, null, 'payload.php.max_execution_time')
                    . field('Post / upload max', ($p['php']['post_max_size'] ?? '?') . ' / ' . ($p['php']['upload_max_filesize'] ?? '?'), null, 'payload.php.post_max_size')
                    . field('Max input vars', $p['php']['max_input_vars'] ?? null, null, 'payload.php.max_input_vars')
                ); ?>
                <?php
                // Each TLS version tested independently, next to the certificate facts.
                $proto = $tls['protocols'] ?? [];
                $protoRow = static function (string $label, ?bool $on, bool $legacy, string $source) {
                    if ($on === null) { return field($label, null, null, $source); }
                    return field($label, $on ? 'accepted' : 'no', $on && $legacy ? 'warn' : ($on ? 'ok' : 'muted'), $source);
                };
                echo section('SSL / TLS',
                    field('Issuer', $tls['issuer'] ?? null, null, 'probe.tls.issuer')
                    . field('Subject (CN)', $tls['subject_cn'] ?? null, null, 'probe.tls.subject_cn')
                    . field_raw('Expires', e($tls['not_after'] ?? '—') . (isset($tls['days_to_expiry']) ? ' (' . e($tls['days_to_expiry']) . ' d)' : ''), null, 'probe.tls.not_after')
                    . field_raw('SAN', fmt_list($tls['san'] ?? []), null, 'probe.tls.san')
                    // CAA restricts which CAs may issue for the domain: a certificate fact.
                    . field_raw('CAA', fmt_list(array_map(static fn ($c) => $c['value'] ?? '', $dns['caa'] ?? [])), null, 'probe.dns.caa')
                    . field('Chain valid', $tls['chain_valid'] ?? null, ($tls['chain_valid'] ?? true) ? 'ok' : 'error', 'probe.tls.chain_valid')
                    . field('Hostname covered', $tls['hostname_covered'] ?? null, ($tls['hostname_covered'] ?? true) ? 'ok' : 'error', 'probe.tls.hostname_covered')
                    . field('Self-signed', $tls['self_signed'] ?? null, ($tls['self_signed'] ?? false) ? 'error' : 'ok', 'probe.tls.self_signed')
                    . field('Backend (admin) SSL', $p['is_backend_ssl'] ?? null, ($p['is_backend_ssl'] ?? true) ? 'ok' : 'error', 'payload.is_backend_ssl')
                    . $protoRow('TLS 1.0', $proto['tls1_0'] ?? null, true, 'probe.tls.protocols.tls1_0')
                    . $protoRow('TLS 1.1', $proto['tls1_1'] ?? null, true, 'probe.tls.protocols.tls1_1')
                    . $protoRow('TLS 1.2', $proto['tls1_2'] ?? null, false, 'probe.tls.protocols.tls1_2')
                    . $protoRow('TLS 1.3', $proto['tls1_3'] ?? null, false, 'probe.tls.protocols.tls1_3')
                );
                ?>
                <?php echo section('PHP',
                    field_raw('Version', e($p['php']['version'] ?? '—') . eol_annotation($eolPhp, $t), null, 'payload.php.version')
                    . field('Memory limit', $p['php']['memory_limit'] ?? null, null, 'payload.php.memory_limit')
                    // Never truncated: the hidden tail is what an analyst needs to check.
                    . field_raw('Extensions (' . count($p['php']['extensions'] ?? []) . ')', fmt_list($p['php']['extensions'] ?? [], PHP_INT_MAX), null, 'payload.php.extensions')
                    . field_raw('Disabled functions (' . count($p['php']['disable_functions'] ?? []) . ')', fmt_list($p['php']['disable_functions'] ?? [], PHP_INT_MAX), null, 'payload.php.disable_functions')
                ); ?>
                <?php echo section('Database',
                    field('Type', $p['database_type'] ?? null, null, 'payload.database_type')
                    . field_raw('Version', e($p['database_version'] ?? '—') . eol_annotation($eolDb, $t), null, 'payload.database_version')
                    . field('Prefix', $p['db_table_prefix'] ?? null, ($p['db_table_prefix'] ?? '') === 'wp_' ? 'warn' : null, 'payload.db_table_prefix')
                    . field_raw('Size', fmt_bytes($p['database']['total_bytes'] ?? null), null, 'payload.database.total_bytes')
                    . field_raw('Transients', e($p['database']['transients']['total'] ?? '?') . ' (' . e($p['database']['transients']['expired'] ?? '?') . ' expired)', null, 'payload.database.transients')
                ); ?>
            </div>
            <?php
            $tables = $p['database']['tables'] ?? [];
            if (is_array($tables) && $tables !== []): ?>
                <h4 style="font-size:.9rem;margin:.9rem 0 .3rem" class="muted">Largest tables</h4>
                <table><thead><tr><th>Table</th><th>Rows</th><th>Size</th><th>Overhead</th></tr></thead><tbody>
                <?php usort($tables, static fn ($a, $b) => ($b['size_bytes'] ?? 0) <=> ($a['size_bytes'] ?? 0));
                foreach (array_slice($tables, 0, 10) as $tb): ?>
                    <tr><td class="mono"><?= e($tb['name'] ?? '?') ?></td><td class="num"><?= e($tb['row_count'] ?? '—') ?></td>
                        <td class="num"><?= fmt_bytes($tb['size_bytes'] ?? null) ?></td><td class="num"><?= fmt_bytes($tb['overhead_bytes'] ?? 0) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('wordpress') ?><h3>WordPress</h3></div>
            <div class="cards">
                <?php
                $isMultisite = ($p['is_multisite'] ?? false) === true;
                echo section('Core & settings',
                    field_raw('Version', e($p['wp_version'] ?? '—') . eol_annotation($eolWp, $t), null, 'payload.wp_version')
                    . field_raw('Known vulnerabilities', $coreVulns !== []
                        ? '<span class="badge badge-error">' . count($coreVulns) . ' CVE</span> — see §Plugins &amp; themes'
                        : '<span class="badge badge-ok">—</span>', null, 'derived.core_vulnerabilities')
                    . field('Core update', $p['core_update']['available_version'] ?? ($p['core_update']['status'] ?? null), !empty($p['core_update']['available_version']) ? 'warn' : 'ok', 'payload.core_update')
                    . field('Auto-update core', $p['core_update']['auto_update_core'] ?? null, null, 'payload.core_update.auto_update_core')
                    . field('Multisite', $p['is_multisite'] ?? null, null, 'payload.is_multisite')
                    . ($isMultisite ? field('Multisite type', $p['multisite_type'] ?? null, null, 'payload.multisite_type')
                        . field('Network sites', $p['multisite_count'] ?? null, null, 'payload.multisite_count')
                        . field_raw('Sites status', fmt_status_tally($p['multisite_sites_status'] ?? []), null, 'payload.multisite_sites_status') : '')
                    . field('Active theme', $p['active_theme'] ?? null, null, 'payload.active_theme')
                    . field('Language', $p['site_locale'] ?? null, null, 'payload.site_locale')
                    . field('Timezone', $p['timezone_string'] ?? null, null, 'payload.timezone_string')
                    . field('Admin email', $p['website_administrator_email'] ?? null, null, 'payload.website_administrator_email')
                    . field('Permalinks', $p['permalink_structure'] ?? null, null, 'payload.permalink_structure')
                ); ?>
                <?php echo section('Cron',
                    field('WP-Cron', ($p['cron']['disabled'] ?? false) ? 'disabled' : 'enabled', null, 'payload.cron.disabled')
                    . field('Overdue events', $p['cron']['overdue_events'] ?? null, ($p['cron']['overdue_events'] ?? 0) > 0 ? 'warn' : 'ok', 'payload.cron.overdue_events')
                    . field('Scheduled events', $p['cron']['scheduled_events'] ?? null, null, 'payload.cron.scheduled_events')
                ); ?>
            </div>
        </div>
    </section>

    <!-- ============================== CONTENT & ACCESS ============================== -->
    <!-- Plugins & themes · Content & languages · Users -->
    <section class="xt-group" id="content-access" data-nav-target="content-access" style="--group-color:var(--group-content)">
        <header class="xt-group-head">
            <span class="xt-icon-badge"><?= report_icon('plugins') ?></span>
            <div><h2>Content &amp; Access</h2><p class="muted">What is installed, what is published, and who can sign in.</p></div>
            <?= $groupBadge('content') ?>
        </header>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('plugins') ?><h3>Plugins &amp; themes</h3></div>
            <?php
            $plugins = is_array($p['plugins'] ?? null) ? $p['plugins'] : [];
            $themes  = is_array($p['themes'] ?? null) ? $p['themes'] : [];
            // Every merged vulnerability for the CVE table below, core first.
            $allVulns = array_map(
                static fn (array $v): array => $v + ['component' => 'WordPress', 'slug' => 'wordpress'],
                $coreVulns
            );
            // Keyed by plugin file ("akismet/akismet.php"), as the collector reports them.
            $autoUpdatePlugins = is_array($p['auto_update_plugins'] ?? null) ? $p['auto_update_plugins'] : [];
            $pluginUpdates     = is_array($p['plugin_updates'] ?? null) ? $p['plugin_updates'] : [];
            $themeUpdates      = is_array($p['theme_updates'] ?? null) ? $p['theme_updates'] : [];
            if ($plugins !== []): ?>
                <h4 style="font-size:.9rem;margin:0 0 .3rem" class="muted">Plugins — <?= count($plugins) ?> installed,
                    <?= count(array_filter($plugins, static fn ($x) => !empty($x['active']))) ?> active,
                    <?= count(array_filter($plugins, static fn ($x) => !empty($x['new_version']))) ?> with update</h4>
                <table><thead><tr><th>Name</th><th>Version</th><th>Update</th><th>Requires</th><th>State</th><th>Licence</th></tr></thead><tbody>
                <?php foreach ($plugins as $file => $pl):
                    $slug   = SoftwareCatalog::normalizeSlug('plugin', (string) ($pl['slug'] ?? ''));
                    $merged = merge_vulnerabilities(
                        $bvPluginsBySlug[$slug]['vulnerabilities'] ?? [],
                        $wfPluginsBySlug[$slug]['vulnerabilities'] ?? [],
                        $pl['version'] ?? null,
                        $ignoredVulnerabilities
                    );
                    $hasUpdate = !empty($pl['new_version']) || in_array($file, $pluginUpdates, true);
                    $inactive  = empty($pl['active']);
                    foreach ($merged as $v) { $allVulns[] = $v + ['component' => $pl['name'] ?? $slug, 'slug' => $slug]; } ?>
                    <tr<?= $inactive ? ' class="row-inactive"' : '' ?>>
                        <td><?= $nameCell('plugin', (string) ($pl['name'] ?? '?'), (string) ($pl['slug'] ?? ''), $slug, $wporg['plugins'][$slug] ?? null) ?></td>
                        <td class="mono"><?= e($pl['version'] ?? '?') ?></td>
                        <td><?= $hasUpdate ? '<span class="b-upd">' . e($pl['new_version'] ?: 'available') . '</span>' : '—' ?></td>
                        <td><?= requirement_cell($pl['requires_wp'] ?? null, $pl['requires_php'] ?? null, $p['wp_version'] ?? null, $p['php']['version'] ?? null) ?></td>
                        <?php $pluginLicense = $licenseStatuses['plugin:' . $slug] ?? 'n_a'; ?>
                        <td><?= $stateCell($inactive, $merged, $wporg['plugins'][$slug] ?? null, in_array($file, $autoUpdatePlugins, true)) ?></td>
                        <td><?= license_status_select($siteId, $extractionId, 'plugin', $slug, $pluginLicense, $csrf,
                            '/site/' . e($siteId) . '/extraction/' . e($extractionId)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <?php if ($themes !== []): ?>
                <h4 style="font-size:.9rem;margin:1rem 0 .3rem" class="muted">Themes — <?= count($themes) ?> installed</h4>
                <table><thead><tr><th>Name</th><th>Version</th><th>Update</th><th>Requires</th><th>Template</th><th>State</th><th>Licence</th></tr></thead><tbody>
                <?php foreach ($orderThemes($themes) as $file => $th):
                    $slug   = SoftwareCatalog::normalizeSlug('theme', (string) ($th['slug'] ?? ''));
                    $merged = merge_vulnerabilities(
                        $bvThemesBySlug[$slug]['vulnerabilities'] ?? [],
                        $wfThemesBySlug[$slug]['vulnerabilities'] ?? [],
                        $th['version'] ?? null,
                        $ignoredVulnerabilities
                    );
                    $hasUpdate = !empty($th['new_version']) || in_array($file, $themeUpdates, true);
                    $inactive  = empty($th['active']);
                    foreach ($merged as $v) { $allVulns[] = $v + ['component' => $th['name'] ?? $slug, 'slug' => $slug]; } ?>
                    <tr<?= $inactive ? ' class="row-inactive"' : '' ?>><td><?= $nameCell('theme', (string) ($th['name'] ?? '?'), (string) ($th['slug'] ?? ''), $slug, $wporg['themes'][$slug] ?? null) ?></td>
                        <td class="mono"><?= e($th['version'] ?? '?') ?></td>
                        <td><?= $hasUpdate ? '<span class="b-upd">' . e($th['new_version'] ?: 'available') . '</span>' : '—' ?></td>
                        <td><?= requirement_cell($th['requires_wp'] ?? null, $th['requires_php'] ?? null, $p['wp_version'] ?? null, $p['php']['version'] ?? null) ?></td>
                        <td class="mono"><?= e($th['template'] ?? '') ?></td>
                        <?php $themeLicense = $licenseStatuses['theme:' . $slug] ?? 'n_a'; ?>
                        <td><?= $stateCell($inactive, $merged, $wporg['themes'][$slug] ?? null) ?></td>
                        <td><?= license_status_select($siteId, $extractionId, 'theme', $slug, $themeLicense, $csrf,
                            '/site/' . e($siteId) . '/extraction/' . e($extractionId)) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <?php
            $muPlugins     = is_array($p['mu_plugins'] ?? null) ? $p['mu_plugins'] : [];
            $dropinPlugins = is_array($p['dropin_plugins'] ?? null) ? $p['dropin_plugins'] : [];
            if ($muPlugins !== [] || $dropinPlugins !== []): ?>
                <h4 style="font-size:.9rem;margin:1rem 0 .3rem" class="muted">Must-use &amp; drop-in plugins</h4>
                <table><thead><tr><th>File</th><th>Type</th><th>Name / description</th><th>Version</th></tr></thead><tbody>
                <?php foreach ($muPlugins as $file => $mu): ?>
                    <tr><td class="mono"><?= e($file) ?></td><td><span class="badge badge-muted">Must-use</span></td>
                        <td><?= e($mu['Name'] ?? '?') ?></td><td class="mono"><?= e($mu['Version'] ?? '—') ?></td></tr>
                <?php endforeach; ?>
                <?php foreach ($dropinPlugins as $file => $dropin):
                    $desc = is_array($dropin) ? ($dropin[0] ?? '?') : $dropin; ?>
                    <tr><td class="mono"><?= e($file) ?></td><td><span class="badge badge-muted">Drop-in</span></td>
                        <td><?= e($desc) ?></td><td>—</td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <?php if ($allVulns !== []): ?>
                <h4 style="font-size:.9rem;margin:1rem 0 .3rem" class="muted">Vulnerabilities detected — <?= count($allVulns) ?></h4>
                <table><thead><tr><th>Component</th><th>CVE</th><th>Title</th><th>CVSS</th><th>Patched version</th><th>Source</th></tr></thead><tbody>
                <?php foreach ($allVulns as $v): ?>
                    <tr>
                        <td class="mono"><?= e($v['component']) ?></td>
                        <td class="mono"><?= $v['cve_id'] ? e($v['cve_id']) : '<span class="muted">—</span>' ?></td>
                        <td><?= e($v['title'] ?? '—') ?></td>
                        <td><?= cvss_badge($v['cvss_score'] ?? null, $v['cvss_rating'] ?? null) ?></td>
                        <td class="mono"><?= e($v['patched_version'] ?? '—') ?></td>
                        <td><?= vulnerability_source_badge($v['sources']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('content') ?><h3>Content &amp; languages</h3></div>
            <div class="cards">
                <?php echo section('Content',
                    field('Posts', wp_count($p['posts_count'] ?? null, 'publish'), null, 'payload.posts_count')
                    . field('Pages', wp_count($p['page_count'] ?? null, 'publish'), null, 'payload.page_count')
                    . field('Media', wp_count($p['media_count'] ?? null), null, 'payload.media_count')
                    . field('Comments', wp_count($p['comments_count'] ?? null, 'approved', 'total_comments'), null, 'payload.comments_count')
                ); ?>
                <?php
                $ml = \SatelliteWP\Manager\Web\Multilingual::fromPayload($p);
                if ($ml !== null) {
                    $src = 'payload.connectors.' . $ml['plugin'];
                    echo section('Languages (' . $ml['label'] . ')',
                        field('Default language', $ml['default_language'], null, $src . '.default_language')
                        . field('Current language', $ml['current_language'], null, $src . '.current_language')
                        . field_raw('Active languages', fmt_list($ml['active_languages']), null, $src . '.active_languages')
                        . field($ml['label'] . ' version', $ml['version'], null, $src . '.version')
                    );
                } else {
                    echo '<div class="card"><h3>Languages</h3><div style="padding:1rem 1.1rem"><div class="pending-note">No multilingual plugin (WPML, Polylang, TranslatePress) detected on this site.</div></div></div>';
                }
                ?>
                <?php
                $woo = $p['connectors']['woocommerce'] ?? null;
                if (is_array($woo)) {
                    echo section('Commerce (WooCommerce)',
                        field('Version', $woo['version'] ?? null, null, 'payload.connectors.woocommerce.version')
                        . field('DB version', $woo['db_version'] ?? null, null, 'payload.connectors.woocommerce.db_version')
                        . field('Products', $woo['product_count'] ?? null, null, 'payload.connectors.woocommerce.product_count')
                        . field('Orders', $woo['order_count'] ?? null, null, 'payload.connectors.woocommerce.order_count')
                        . field('HPOS enabled', $woo['hpos_enabled'] ?? null, null, 'payload.connectors.woocommerce.hpos_enabled')
                        . field_raw('Payment gateways', fmt_list($woo['active_gateways'] ?? []), null, 'payload.connectors.woocommerce.active_gateways')
                    );
                }
                ?>
            </div>
            <?php
            $postTypes = is_array($p['post_types'] ?? null) ? $p['post_types'] : [];
            $ptCounts  = is_array($p['post_type_count'] ?? null) ? $p['post_type_count'] : [];
            if ($postTypes !== []): ?>
                <h4 style="font-size:.9rem;margin:.9rem 0 .3rem" class="muted">Content types</h4>
                <table><thead><tr><th>Type</th><th>Total</th><th>Published</th><th>Draft</th><th>Trash</th></tr></thead><tbody>
                <?php foreach ($postTypes as $slug => $label):
                    $ptcounts = is_array($ptCounts[$slug] ?? null) ? $ptCounts[$slug] : [];
                    $total    = array_sum(array_map('intval', $ptcounts)); ?>
                    <tr><td class="mono"><?= e($label) ?></td><td class="num"><?= e($total) ?></td>
                        <td class="num"><?= e($ptcounts['publish'] ?? '0') ?></td>
                        <td class="num"><?= e($ptcounts['draft'] ?? '0') ?></td>
                        <td class="num"><?= e($ptcounts['trash'] ?? '0') ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('users') ?><h3>Users</h3></div>
            <div class="cards">
                <?php
                $admins = is_array($p['administrators'] ?? null) ? $p['administrators'] : [];
                $adminLabel = static function ($a): string {
                    if (!is_array($a)) {
                        return (string) $a;
                    }
                    $login = (string) ($a['login'] ?? '?');
                    $email = (string) ($a['email'] ?? '');

                    return $email !== '' ? "{$login} ({$email})" : $login;
                };
                echo section('Accounts',
                    field('Total users', wp_count($p['users_count'] ?? null, 'total_users'), null, 'payload.users_count')
                    . field('Administrators', count($admins), count($admins) > 5 ? 'warn' : 'ok', 'payload.administrators')
                    . field_raw('Admin logins', fmt_lines(array_map($adminLabel, $admins)), null, 'payload.administrators')
                    . field_raw('Super admins (network)', fmt_lines(array_map($adminLabel, (array) ($p['super_admins'] ?? []))), null, 'payload.super_admins')
                );
                ?>
            </div>
        </div>
    </section>

    <!-- ============================== QUALITY & SECURITY ============================== -->
    <!-- Performance · SEO & analytics · Security -->
    <section class="xt-group" id="quality-security" data-nav-target="quality-security" style="--group-color:var(--group-quality)">
        <header class="xt-group-head">
            <span class="xt-icon-badge"><?= report_icon('performance') ?></span>
            <div><h2>Quality &amp; Security</h2><p class="muted">Speed, discoverability, and the site's exposure to attack.</p></div>
            <?= $groupBadge('quality') ?>
        </header>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('performance') ?><h3>Performance</h3></div>
            <?php if ($ps !== []): ?>
                <table><thead><tr><th>Strategy</th>
                    <?php foreach (array_keys(($mobilePs['scores'] ?? [])) as $c): ?><th><?= e(ucfirst(str_replace('-', ' ', $c))) ?></th><?php endforeach; ?>
                    <th>LCP</th><th>CLS</th><th title="Real-world Chrome usage data (CrUX) for this page, not the simulated Lighthouse run in the columns to the left">Field data (CrUX)</th></tr></thead><tbody>
                <?php foreach ($ps as $strat => $r): if (!is_array($r)) { continue; } ?>
                    <tr><td><strong><?= e($strat) ?></strong></td>
                        <?php foreach (($mobilePs['scores'] ?? []) as $c => $_): $sc = $r['scores'][$c] ?? null; ?>
                            <td><?= $sc === null ? '—' : badge_score((int) $sc) ?></td>
                        <?php endforeach; ?>
                        <td class="num"><?= isset($r['lab']['lcp']['value']) ? e(round((float) $r['lab']['lcp']['value'])) . ' ms' : '—' ?></td>
                        <td class="num"><?= e($r['lab']['cls']['display'] ?? '—') ?></td>
                        <td><?php $fc = $r['field']['overall_category'] ?? null; ?><?= $fc ? e(ucfirst(strtolower((string) $fc))) : '<span class="val-muted">no field data</span>' ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <div class="cards" style="margin-top:1.1rem">
                <?php
                echo section('HTTP',
                    field('Status code', $http['status_code'] ?? null, null, 'probe.http.status_code')
                    . field('HTTP version', isset($http['http_version']) ? 'HTTP/' . $http['http_version'] : null, null, 'probe.http.http_version')
                    . field('Compression (HTML)', $http['content_encoding'] ?? 'none', ($http['content_encoding'] ?? null) ? 'ok' : 'warn', 'probe.http.content_encoding')
                    . field('Compression (asset)', ($http['asset']['content_encoding'] ?? null) ?? (($http['asset']['checked'] ?? false) ? 'none' : 'n/a'), null, 'probe.http.asset.content_encoding')
                    // One encoding offered at a time (B1/B2), unlike the preference shown above.
                    . field('Gzip capability', $http['compression']['gzip'] ?? null, ($http['compression']['gzip'] ?? null) === false ? 'warn' : (($http['compression']['gzip'] ?? null) === true ? 'ok' : null), 'probe.http.compression.gzip')
                    . field('Brotli capability', $http['compression']['brotli'] ?? null, ($http['compression']['brotli'] ?? null) === false ? 'warn' : (($http['compression']['brotli'] ?? null) === true ? 'ok' : null), 'probe.http.compression.brotli')
                    . field('HTTP/2 supported', $http['protocols']['http2'] ?? null, ($http['protocols']['http2'] ?? null) === false ? 'warn' : (($http['protocols']['http2'] ?? null) === true ? 'ok' : null), 'probe.http.protocols.http2')
                    . field('HTTP/1.1 supported', $http['protocols']['http1_1'] ?? null, ($http['protocols']['http1_1'] ?? null) === false ? 'warn' : (($http['protocols']['http1_1'] ?? null) === true ? 'ok' : null), 'probe.http.protocols.http1_1')
                    . field('HTTP/3 advertised', $http['protocols']['http3_advertised'] ?? null, null, 'probe.http.protocols.http3_advertised')
                    . field('HTTPS forced', $http['redirects']['forces_https'] ?? null, ($http['redirects']['forces_https'] ?? true) ? 'ok' : 'warn', 'probe.http.redirects.forces_https')
                    . field('Redirects', $http['redirects']['hops'] ?? null, null, 'probe.http.redirects.hops')
                    . field('CDN', $http['cdn'] ?? '—', null, 'probe.http.cdn')
                ); ?>
                <?php echo section('Cache',
                    field_raw('Autoload', fmt_bytes($p['autoload']['total_bytes'] ?? null) . ' <span class="val-muted">(' . e($p['autoload']['count'] ?? '?') . ' options)</span>', null, 'payload.autoload.total_bytes')
                    . field('Object cache', ($p['object_cache']['external'] ?? false) ? 'external' : 'none', ($p['object_cache']['external'] ?? false) ? 'ok' : 'warn', 'payload.object_cache.external')
                    . field('Page cache drop-in', ($p['object_cache']['page_cache'] ?? false) ? 'present' : 'absent', null, 'payload.object_cache.page_cache')
                ); ?>
            </div>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('seo') ?><h3>SEO &amp; analytics</h3></div>
            <div class="cards">
                <?php
                $robots = $http['robots'] ?? [];
                $sitemapSourceLabel = match ($robots['sitemap_source'] ?? null) {
                    'robots.txt' => 'Declared in robots.txt',
                    'convention' => 'Found at its default URL — not declared in robots.txt',
                    default      => null,
                };
                echo section('SEO',
                    field('robots.txt', ($robots['present'] ?? false) ? 'present' : 'absent', ($robots['present'] ?? false) ? 'ok' : 'warn', 'probe.http.robots.present')
                    . field('Blocks whole site', ($robots['disallow_all'] ?? false) ? 'yes' : 'no', ($robots['disallow_all'] ?? false) ? 'error' : 'ok', 'probe.http.robots.disallow_all')
                    . field_raw('Sitemaps', fmt_list($robots['sitemaps'] ?? []), null, 'probe.http.robots.sitemaps')
                    . field('Sitemap reachable', isset($robots['sitemap_reachable']) ? (($robots['sitemap_reachable']) ? 'yes' : 'no') : '—', null, 'probe.http.robots.sitemap_reachable')
                    . ($sitemapSourceLabel !== null ? field('Sitemap source', $sitemapSourceLabel, null, 'probe.http.robots.sitemap_source') : '')
                );

                $audit       = $probes['seranking'] ?? null;
                $auditData   = (array) ($audit['data'] ?? []);
                $auditStatus = (string) ($audit['status'] ?? '');
                if ($audit === null) {
                    $auditNote = '<div class="pending-note">No site audit for this extraction — run the <code>seranking</code> probe to start one.</div>';
                } elseif ($auditStatus === 'pending') {
                    $auditNote = '<div class="pending-note">Audit ' . e($auditData['audit_id'] ?? '') . ' in progress on SE Ranking ('
                        . e($auditData['state'] ?? 'queued') . (isset($auditData['pages_crawled']) ? ', ' . e($auditData['pages_crawled']) . ' pages crawled so far' : '')
                        . '). The report is fetched automatically once it is finished.</div>';
                } elseif ($auditStatus === 'error') {
                    $auditNote = '<div class="pending-note" style="border-color:var(--warn);background:var(--bg-warn)">Site audit failed: ' . e(implode(' ', (array) ($audit['errors'] ?? []))) . '</div>';
                } else {
                    $totals    = (array) ($auditData['totals'] ?? []);
                    $auditRows = field_raw('Health score', isset($auditData['score']) ? badge_score((int) $auditData['score']) : '—', null, 'probe.seranking.score')
                        . field('Pages crawled', $totals['pages'] ?? null, null, 'probe.seranking.totals.pages')
                        . field('Errors', $totals['errors'] ?? null, ($totals['errors'] ?? 0) > 0 ? 'error' : 'ok', 'probe.seranking.totals.errors')
                        . field('Warnings', $totals['warnings'] ?? null, ($totals['warnings'] ?? 0) > 0 ? 'warn' : 'ok', 'probe.seranking.totals.warnings')
                        . field('Notices', $totals['notices'] ?? null, null, 'probe.seranking.totals.notices')
                        . field('Finished', $auditData['finished_at'] ?? null, null, 'probe.seranking.finished_at');

                    // Failing checks only, most severe first, then by pages affected.
                    $rank   = ['error' => 0, 'warning' => 1, 'notice' => 2];
                    $issues = array_values(array_filter((array) ($auditData['checks'] ?? []),
                        static fn ($c) => isset($rank[$c['status'] ?? '']) && (int) ($c['pages'] ?? 0) > 0));
                    usort($issues, static fn ($a, $b) => [$rank[$a['status']], -$a['pages']] <=> [$rank[$b['status']], -$b['pages']]);
                    if ($issues !== []) {
                        $auditTable = '<table><thead><tr><th>Severity</th><th>Check</th><th>Section</th><th class="num">Pages</th></tr></thead><tbody>';
                        foreach ($issues as $c) {
                            $auditTable .= '<tr><td>' . '<span class="status-dot ' . ['error' => 'status-dot-error', 'warning' => 'status-dot-warn', 'notice' => 'status-dot-muted'][$c['status']] . '">' . e(ucfirst($c['status'])) . '</span>' . '</td>'
                                . '<td>' . e($c['name']) . '</td><td>' . e($c['section_name']) . '</td><td class="num">' . e($c['pages']) . '</td></tr>';
                        }
                        $auditTable .= '</tbody></table>';
                    }
                }
                echo '<div class="card card-full"><h3>Site audit (SE Ranking)</h3>'
                    . (isset($auditRows) ? '<table class="kv"><tbody>' . $auditRows . '</tbody></table>' . ($auditTable ?? '') : '<div style="padding:1rem 1.1rem">' . $auditNote . '</div>')
                    . '</div>';
                ?>
            </div>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('security') ?><h3>Security</h3></div>
            <div class="cards">
                <?php
                $constRows = '';
                foreach (HardeningConstants::rows(is_array($p['constants'] ?? null) ? $p['constants'] : []) as $row) {
                    $constRows .= field_raw($row['name'], e($row['display']), $row['flagged'] ? 'error' : null, 'payload.constants.' . $row['name']);
                }
                echo section('Hardening (constants)', $constRows ?: field('constants', null), class: 'card-full');
                ?>
                <?php
                $sec = $http['security_headers'] ?? [];
                echo section('Security headers',
                    field('X-Content-Type-Options', $sec['x-content-type-options'] ?? 'missing', ($sec['x-content-type-options'] ?? null) ? 'ok' : 'warn', 'probe.http.security_headers.x-content-type-options')
                    . field('X-Frame-Options', $sec['x-frame-options'] ?? 'missing', ($sec['x-frame-options'] ?? null) ? 'ok' : 'warn', 'probe.http.security_headers.x-frame-options')
                    . field('Content-Security-Policy', $sec['content-security-policy'] ?? 'missing', ($sec['content-security-policy'] ?? null) ? 'ok' : 'warn', 'probe.http.security_headers.content-security-policy')
                    . field('Referrer-Policy', $sec['referrer-policy'] ?? 'missing', ($sec['referrer-policy'] ?? null) ? 'ok' : 'warn', 'probe.http.security_headers.referrer-policy')
                    . field('Permissions-Policy', $sec['permissions-policy'] ?? 'missing', ($sec['permissions-policy'] ?? null) ? 'ok' : 'warn', 'probe.http.security_headers.permissions-policy')
                    . field('HSTS', $sec['strict-transport-security'] ?? 'missing', ($sec['strict-transport-security'] ?? null) ? 'ok' : 'warn', 'probe.http.security_headers.strict-transport-security')
                ); ?>
                <?php
                $fs = $p['filesystem'] ?? [];
                echo section('Filesystem',
                    field_raw('Free disk', fmt_bytes($fs['disk_free_bytes'] ?? null) . ' / ' . fmt_bytes($fs['disk_total_bytes'] ?? null), null, 'payload.filesystem.disk_free_bytes')
                    . field('Core writable', $fs['core_writable'] ?? null, ($fs['core_writable'] ?? false) ? 'warn' : 'ok', 'payload.filesystem.core_writable')
                    . field('Uploads writable', $fs['uploads_writable'] ?? null, null, 'payload.filesystem.uploads_writable')
                ); ?>
                <?php
                $bvFiles = $bv['backups']['files'] ?? [];
                $bvDb    = $bv['backups']['database'] ?? [];
                if ($bvFiles !== [] || $bvDb !== []) {
                    echo section('Backup size (BlogVault)',
                        field_raw('Database size', fmt_bytes($bvDb['size']['total'] ?? null), null, 'probe.blogvault.backups.database')
                        . field_raw('Files size', fmt_bytes($bvFiles['size']['total'] ?? null), null, 'probe.blogvault.backups.files')
                        . field('Files — total', $bvFiles['count']['total'] ?? null, null, 'probe.blogvault.backups.files')
                        . field('Files — synced', $bvFiles['count']['synced'] ?? null, null, 'probe.blogvault.backups.files')
                        . field('Files — ignored', $bvFiles['count']['ignored'] ?? null, null, 'probe.blogvault.backups.files')
                    );
                }
                ?>
                <?php
                // Passive checks an anonymous visitor could run; each row shows its evidence (URL + status).
                $exp      = $http['exposure'] ?? [];
                $evidence = $exp['evidence'] ?? [];
                $evNote   = static function (?array $ev, ?string $extra = null): string {
                    if (!is_array($ev) || ($ev['url'] ?? null) === null) {
                        return '';
                    }
                    $line = e($ev['url']) . ($ev['status'] !== null ? ' → HTTP ' . e($ev['status']) : ' → no response');

                    return '<br><span class="mono val-muted" style="font-size:.78em">' . $line . ($extra !== null ? ' — ' . $extra : '') . '</span>';
                };
                $exposureRow = static function (string $label, ?bool $exposed, string $exposedWord, string $safeWord, string $note): string {
                    if ($exposed === null) {
                        return field_raw($label, '<span class="val-muted">not checked</span>' . $note);
                    }

                    return field_raw($label, '<span class="' . ($exposed ? 'val-warn' : 'val-ok') . '">' . e($exposed ? $exposedWord : $safeWord) . '</span>' . $note);
                };
                $restUsernames  = $evidence['rest_users']['usernames'] ?? [];
                $sensitiveFiles = $exp['sensitive_files'] ?? null;
                $sensitiveEv    = $evidence['sensitive_files'] ?? null;
                if (($exp['auth_required'] ?? false) === true) {
                    echo '<div class="card card-full"><h3>Exposure</h3><div style="padding:1rem 1.1rem">'
                        . '<div class="pending-note" style="border-color:var(--warn);background:var(--bg-warn)">'
                        . '<b>Site not public — checks below could not run.</b> The homepage itself answered '
                        . '<span class="mono">HTTP 401</span> (HTTP Basic Auth required), so every request below '
                        . 'would also 401 regardless of what it is testing for — that is not the same thing as '
                        . '"nothing exposed". Add this site\'s Basic Auth credentials under "⚙ Site settings" on '
                        . 'its page to let these checks actually run.</div></div></div>';
                } else {
                echo section('Exposure',
                    $exposureRow('xmlrpc.php', $exp['xmlrpc_enabled'] ?? null, 'exposed', 'blocked', $evNote($evidence['xmlrpc'] ?? null))
                    . $exposureRow('REST user enumeration', $exp['rest_user_enumeration'] ?? null, 'exposed', 'blocked',
                        $evNote($evidence['rest_users'] ?? null, $restUsernames !== [] ? 'usernames: ' . implode(', ', $restUsernames) : null))
                    . $exposureRow('Author enumeration (?author=1)', $exp['author_enumeration'] ?? null, 'exposed', 'blocked',
                        $evNote($evidence['author'] ?? null, !empty($evidence['author']['location']) ? 'redirects to ' . $evidence['author']['location'] : null))
                    . field_raw('Sensitive files', match (true) {
                        !is_array($sensitiveFiles) => '<span class="val-muted">not checked</span>',
                        $sensitiveFiles === []     => '<span class="val-ok">none</span>' . (is_array($sensitiveEv)
                            ? '<br><span class="mono val-muted" style="font-size:.78em">checked: ' . e(implode(', ', $sensitiveEv['checked'])) . '</span>' : ''),
                        default                    => '<span class="val-error">' . fmt_list($sensitiveFiles) . '</span>',
                    })
                    . $exposureRow('Uploads directory listing', $exp['directory_listing'] ?? null, 'browsable', 'not browsable', $evNote($evidence['directory_listing'] ?? null))
                    . $exposureRow('HTTP TRACE method', $exp['trace_enabled'] ?? null, 'enabled', 'disabled', $evNote($evidence['trace'] ?? null))
                );
                }
                ?>
            </div>
            <?= json_details('Raw request & response headers — same request this probe made', [
                'request'  => $http['request'] ?? null,
                'response' => [
                    'status_code' => $http['status_code'] ?? null,
                    'headers'     => $http['headers'] ?? null,
                ],
            ]) ?>
            <?php
            $perms = is_array($fs['permissions'] ?? null) ? $fs['permissions'] : [];
            if ($perms !== []):
                $permLabels = [
                    'wp_config'   => 'wp-config.php',
                    'root'        => 'Site root',
                    'index'       => 'index.php',
                    'content_dir' => 'wp-content',
                    'plugins_dir' => 'wp-content/plugins',
                    'themes_dir'  => 'wp-content/themes',
                    'uploads_dir' => 'wp-content/uploads',
                ]; ?>
                <h4 style="font-size:.9rem;margin:.9rem 0 .3rem" class="muted">File permissions</h4>
                <table><thead><tr><th>Path</th><th>Mode</th><th>Writable</th><th>Readable</th></tr></thead><tbody>
                <?php foreach ($perms as $key => $perm): ?>
                    <tr><td><?= e($permLabels[$key] ?? $key) ?></td>
                        <td class="mono"><?= e($perm['mode'] ?? '?') ?></td>
                        <?php // uploads/ must be writable for media; anywhere else, writable is worth flagging. ?>
                        <td><?= !empty($perm['writable'])
                            ? '<span class="badge ' . ($key === 'uploads_dir' ? 'badge-ok' : 'badge-warn') . '">Yes</span>'
                            : '<span class="badge badge-ok">No</span>' ?></td>
                        <td><?= !empty($perm['readable']) ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-error">No</span>' ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($findings !== null): ?>
    <!-- ======================== MANUAL OBSERVATIONS ======================== -->
    <section class="xt-group" id="observations" data-nav-target="observations" style="--group-color:var(--accent)">
        <div class="xt-group-head">
            <span class="xt-icon-badge"><?= report_icon('raw') ?></span>
            <h2>Observations</h2>
        </div>
        <div class="xt-subsection">
            <p class="muted" style="margin-top:0">
                Analyst-authored lines for the Google Docs report — each one targets one
                <span class="mono">{{…_observations}}</span> spot in the template. An omitted one
                stays saved, just left out of the report until re-included.
            </p>
            <?php if ($observationsImport !== null && $observationsImport['errors'] === []): ?>
                <div class="pending-note" style="border-color:var(--ok);background:var(--bg-ok)"><?= e($observationsImport['imported']) ?> observation<?= $observationsImport['imported'] > 1 ? 's' : '' ?> imported from the CSV.</div>
            <?php elseif ($observationsImport !== null): ?>
                <div class="pending-note" style="border-color:var(--warn);background:var(--bg-warn)">
                    Nothing was imported — fix the file and import it again:
                    <ul style="margin:.4rem 0 0"><?php foreach ($observationsImport['errors'] as $importError): ?><li><?= e($importError) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <?php if ($observations === []): ?>
                <p class="empty">No manual observation yet.</p>
            <?php else: ?>
            <table>
                <thead><tr><th>Pastille</th><th>Section</th><th>Title</th><th>Description</th><th>In report</th><?php if ($canEditObservations): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($observations as $i => $rec): ?>
                    <?php $rowId = 'rec-' . e($i); ?>
                    <tr class="row-display" data-row-id="<?= $rowId ?>">
                        <td><span class="dot dot-<?= e($rec['color'] ?? 'grey') ?>"></span> <?= e($t->pastille((string) ($rec['color'] ?? 'grey'))) ?></td>
                        <td class="mono"><?= e($rec['section'] ?? '') ?></td>
                        <td><?= e($rec['title'] ?? '') ?></td>
                        <td><?= format_observation_text((string) ($rec['description'] ?? '')) ?></td>
                        <td><?= !empty($rec['include']) ? '<span class="badge badge-ok">Included</span>' : '<span class="badge badge-muted">Omitted</span>' ?></td>
                        <?php if ($canEditObservations): ?>
                        <td>
                            <button type="button" class="row-edit-btn" data-row-id="<?= $rowId ?>" title="Edit"><?= icon_edit() ?></button>
                            <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/observations" style="display:inline;margin:0"
                                  onsubmit="return confirm('Remove this observation?')">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="id" value="<?= e($rec['id'] ?? '') ?>">
                                <button type="submit" class="btn btn-danger" style="padding:.2rem .5rem;font-size:.8rem">Remove</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php if ($canEditObservations): ?>
                    <tr class="row-edit-form" data-row-id="<?= $rowId ?>" style="display:none">
                        <td colspan="6">
                            <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/observations" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin:0">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="id" value="<?= e($rec['id'] ?? '') ?>">
                                <select name="section" style="padding:.35rem .5rem;font:inherit">
                                    <?php foreach ($observationSections as $s): ?>
                                        <option value="<?= e($s) ?>" <?= $s === ($rec['section'] ?? null) ? 'selected' : '' ?>><?= e($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="color" style="padding:.35rem .5rem;font:inherit">
                                    <?php foreach (Pastille::values() as $c): ?>
                                        <option value="<?= e($c) ?>" <?= $c === ($rec['color'] ?? null) ? 'selected' : '' ?>><?= e($t->pastille($c)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="title" value="<?= e($rec['title'] ?? '') ?>" placeholder="Title" required style="padding:.35rem .5rem;font:inherit;min-width:12rem">
                                <textarea name="description" placeholder="Description" rows="2"
                                          title="Supports **bold**, _italic_, [link text](https://…) — resolved into real formatting both here and in the Google Docs report."
                                          style="padding:.35rem .5rem;font:inherit;min-width:20rem;flex:1;resize:vertical"><?= e($rec['description'] ?? '') ?></textarea>
                                <label style="display:flex;align-items:center;gap:.3rem;font-size:.85rem">
                                    <input type="checkbox" name="include" <?= !empty($rec['include']) ? 'checked' : '' ?>> In report
                                </label>
                                <button type="submit" class="btn" style="padding:.35rem .7rem">Save</button>
                                <button type="button" class="btn btn-muted row-cancel-btn" style="padding:.35rem .7rem">Cancel</button>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ($canEditObservations): ?>
            <h4 style="font-size:.9rem;margin:1rem 0 .3rem" class="muted">Add an observation</h4>
            <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/observations" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="add">
                <select name="section" required style="padding:.35rem .5rem;font:inherit">
                    <option value="">Section…</option>
                    <?php foreach ($observationSections as $s): ?>
                        <option value="<?= e($s) ?>"><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="color" style="padding:.35rem .5rem;font:inherit">
                    <?php foreach (Pastille::values() as $c): ?>
                        <option value="<?= e($c) ?>" <?= $c === Pastille::Blue->value ? 'selected' : '' ?>><?= e($t->pastille($c)) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="title" placeholder="Title" required style="padding:.35rem .5rem;font:inherit;min-width:12rem">
                <textarea name="description" placeholder="Description" rows="2"
                          title="Supports **bold**, _italic_, [link text](https://…) — resolved into real formatting both here and in the Google Docs report."
                          style="padding:.35rem .5rem;font:inherit;min-width:20rem;flex:1;resize:vertical"></textarea>
                <label style="display:flex;align-items:center;gap:.3rem;font-size:.85rem">
                    <input type="checkbox" name="include" checked> In report
                </label>
                <button type="submit" class="btn">Add</button>
            </form>
            <p class="muted" style="font-size:.78rem;margin:.4rem 0 0">
                Description supports <span class="mono">**bold**</span>, <span class="mono">_italic_</span>, and
                <span class="mono">[link text](https://…)</span> — same formatting in this list and in the Google Docs report.
            </p>

            <h4 style="font-size:.9rem;margin:1rem 0 .3rem" class="muted">Import a CSV</h4>
            <form method="post" enctype="multipart/form-data" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/observations-import" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="file" name="csv" accept=".csv,text/csv" required>
                <button type="submit" class="btn">Import</button>
                <?php $csvTemplate = "section,color,title,description,include\n" . ($observationSections[0] ?? '') . ",blue,Example title,\"Description, with **bold** if needed\",1\n"; ?>
                <a href="data:text/csv;charset=utf-8,<?= e(rawurlencode($csvTemplate)) ?>" download="observations.csv" style="font-size:.85rem">Download a template</a>
            </form>
            <p class="muted" style="font-size:.78rem;margin:.4rem 0 0">
                Columns <span class="mono">section</span>, <span class="mono">title</span> (required), <span class="mono">color</span>
                (<?= e(implode(', ', Pastille::values())) ?>; blue when empty), <span class="mono">description</span>,
                <span class="mono">include</span> (1/0, empty = 1). Comma or semicolon, UTF-8. Rows are added to the list above;
                one invalid row and nothing is imported.
            </p>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============================== RAW DATA ============================== -->
    <section class="xt-group" id="raw-data" data-nav-target="raw-data" style="--group-color:var(--muted)">
        <header class="xt-group-head">
            <span class="xt-icon-badge"><?= report_icon('raw') ?></span>
            <div><h2>Raw data</h2><p class="muted">Every probe result and the extraction payload, unedited.</p></div>
        </header>
        <details>
            <summary>Probe results &amp; payload</summary>
            <p style="margin-top:.7rem">
                <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/payload">payload.json</a> ·
                <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/meta">meta.json</a> ·
                <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/findings">findings.json</a>
                <?php foreach ($probes as $name => $_): ?>
                    · <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/<?= e($name) ?>"><?= e($name) ?>.json</a>
                <?php endforeach; ?>
            </p>
            <?= json_details('Full payload (' . count($payload) . ' keys)', $payload) ?>
        </details>
    </section>

</div>
<?php endif; // status === 'done' ?>

<?php if ($debuggingTools): ?>
    <details class="section" style="margin-top:1.5rem">
        <summary style="padding:.8rem 1.1rem;cursor:pointer"><b>Debugging tools</b> <span class="muted">(debugging_tools is on)</span></summary>
        <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/rerun"
              style="padding:0 1.1rem 1.1rem;display:flex;flex-direction:column;gap:.6rem"
              onsubmit="return confirm('Re-run the selected probes now? This overwrites their stored results and re-evaluates every rule.')">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="muted" style="margin:0">Runs synchronously in this request, whatever the extraction's status — this bypasses the frozen-snapshot rule.</p>
            <div style="display:flex;gap:1rem;flex-wrap:wrap">
                <?php foreach ($rerunProbes as $probeName): ?>
                    <label style="display:flex;gap:.3rem;align-items:center" class="mono">
                        <input type="checkbox" name="probes[]" value="<?= e($probeName) ?>"> <?= e($probeName) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div><button type="submit" class="btn btn-secondary">Re-run selected probes</button></div>
        </form>
    </details>
<?php endif; ?>
