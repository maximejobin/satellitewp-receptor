<?php
/**
 * Extraction report — visual/structural redesign (2026-08-29). Same data,
 * same helpers (field()/field_raw()/section()/etc. from helpers.php), all
 * wrapped in a grouped, icon-led, sticky-nav layout instead of ten flat
 * stacked sections. The previous flat-cards layout (`extraction-legacy.php`,
 * kept for a time as a rollback/comparison reference) was removed 2026-08-31
 * — parity had already been verified and the redesign had been live long
 * enough that the comparison copy no longer earned its keep.
 *
 * @var \SatelliteWP\Xtractor\Rules\Translator $t
 */
use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use SatelliteWP\Xtractor\Rules\Category;

$p    = $payload;
$dns  = $probes['dns']['data'] ?? [];
$tls  = $probes['tls']['data'] ?? [];
$rdap = $probes['rdap']['data'] ?? [];
$http = $probes['http']['data'] ?? [];
$ps   = $probes['pagespeed']['data'] ?? [];
$bv   = $probes['blogvault']['data'] ?? [];
$wf   = $probes['wordfence']['data'] ?? [];

// Plugin/theme slug -> that component's probe record, for the vulnerability
// columns below. Both probes already key their items by normalized slug.
$bvPluginsBySlug = array_column($bv['plugins']['items'] ?? [], null, 'slug');
$wfPluginsBySlug = array_column($wf['plugins']['items'] ?? [], null, 'slug');
$bvThemesBySlug  = array_column($bv['themes']['items'] ?? [], null, 'slug');
$wfThemesBySlug  = array_column($wf['themes']['items'] ?? [], null, 'slug');

// Core is merged once here and reused by both §WordPress (the count) and
// §Plugins & themes (the detailed table), so the two can never disagree.
$coreVulns = merge_vulnerabilities($bv['core']['vulnerabilities'] ?? [], $wf['core']['vulnerabilities'] ?? [], $p['wp_version'] ?? null);

/** "N CVE" cell for a table row, from an already-merged list — empty (not a "—") when there's nothing to report. */
$vulnCell = static function (array $merged): string {
    if ($merged === []) {
        return '';
    }

    return '<span class="badge badge-error">' . count($merged) . ' CVE</span>';
};

$eolPhp = $eol->eolStatus('php', (string) ($p['php']['version'] ?? ''));
$eolWp  = $eol->eolStatus('wordpress', (string) ($p['wp_version'] ?? ''));
$dbType = str_contains(strtolower((string) ($p['database_type'] ?? '')), 'maria') ? 'mariadb'
    : (str_contains(strtolower((string) ($p['database_type'] ?? '')), 'mysql') ? 'mysql' : null);
$eolDb  = $dbType !== null ? $eol->eolStatus($dbType, (string) ($p['database_version'] ?? '')) : null;

$all      = $findings['findings'] ?? [];
$counts   = $findings['counts'] ?? ['by_pastille' => [], 'total' => 0];
$byPast   = $counts['by_pastille'] ?? [];
$score    = health_score($counts);

// category → observation count, for the filter bar
$catCount = [];
foreach ($all as $f) { $catCount[$f['category']] = ($catCount[$f['category']] ?? 0) + 1; }

// pastille → severity stripe class
$stripe = static fn (string $c): string => in_array($c, ['red', 'orange', 'blue'], true) ? "sev-{$c}" : '';
$mobilePs = $ps['mobile'] ?? (is_array(reset($ps)) ? reset($ps) : []);

// Which rule categories live under which of the three groups below — purely
// presentational (drives each group header's own pass-rate bar), not a rule
// engine concept. Grouped to match where each topic actually sits on this
// page: e.g. Category::HTTP's compression/redirect checks render inside
// §Performance (Quality & Security), not §Hosting.
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
        if ($f['pastille'] === 'green') {
            $pass++;
        } elseif (in_array($f['pastille'], ['red', 'orange'], true)) {
            $fail++;
        }
    }
    $applicable = $pass + $fail;

    return $applicable > 0 ? ['pass' => $pass, 'fail' => $fail, 'rate' => (int) round($pass / $applicable * 100)] : null;
};
/** Small pass-rate bar + caption for a group header, or nothing when $findings is null (not-yet-evaluated). */
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
if ($status !== 'done'):
    $bvFound = ($blogVault['found'] ?? false) === true;
?>
    <h1><?= e($site['name'] ?? $siteId) ?></h1>
    <p class="muted">
        <a href="<?= e($site['site_url'] ?? '#') ?>"><?= e($site['site_url'] ?? '') ?></a>
        · <?= e($t->ui('received')) ?> <?= e($meta['received_at'] ?? '?') ?>
        · <?= badge($row['status'] ?? null) ?>
    </p>
    <!-- Analysis not done: pre-flight + the manual trigger, or a status message.
         No data section below renders until status is "done" — an analyst
         should never read partial/incomplete data as if it were final. -->
    <section class="section">
        <h2>Analysis</h2>
        <div style="padding:0 1.1rem 1.1rem">
        <?php if ($status === 'running'): ?>
            <p><span class="badge badge-ok">Running</span>
               The analysis is running. Refresh the page in a moment.</p>
        <?php elseif ($status === 'error'): ?>
            <p><span class="badge badge-error">Error</span>
               The last analysis failed. Check the worker logs if needed, then retry.</p>
            <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/run">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="return" value="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>">
                <button type="submit" class="btn">Retry analysis</button>
            </form>
        <?php elseif ($status === 'queued'): ?>
            <p><span class="badge badge-ok">Queued</span>
               Waiting for the worker (cron <span class="mono">ingest:process</span>,
               every minute). Refresh the page in a moment.</p>
        <?php else: ?>
            <p class="muted">This extraction was received but <b>not analysed yet</b>.
               No probe has run, no quota has been spent.</p>

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

            <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/run"
                  <?= $bvFound ? '' : 'onsubmit="return confirm(\'This site is not on BlogVault. Run the analysis anyway?\')"' ?>>
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="return" value="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>">
                <button type="submit" class="btn"><?= $bvFound ? 'Run analysis' : 'Run analysis anyway' ?></button>
            </form>
        <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($status === 'done'): ?>
<div class="xt-report">

    <!-- Hero -->
    <div class="xt-hero" id="overview">
        <div class="xt-hero-top">
            <div class="xt-hero-main">
                <?php if ($findings !== null):
                    $grade = health_grade($score); ?>
                    <div class="xt-hero-grade" style="--c:<?= health_color($score) ?>">
                        <div class="xt-grade-letter"><?= e($grade) ?></div>
                        <div class="xt-hero-ring" data-score="<?= e($score) ?>" style="--p:0;--c:<?= health_color($score) ?>">
                            <div class="xt-hero-ring-inner">
                                <div class="score"><?= e($score) ?></div>
                                <div class="cap">/ 100</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="xt-hero-title">
                    <div class="xt-hero-eyebrow">Health &amp; Security Report</div>
                    <h1><?= e($site['name'] ?? $siteId) ?></h1>
                    <div class="xt-hero-url">
                        <a href="<?= e($site['site_url'] ?? '#') ?>"><?= e($site['site_url'] ?? '') ?></a>
                        · <?= e($t->ui('received')) ?> <?= e($meta['received_at'] ?? '?') ?>
                        · signature <?= !empty($meta['signature_valid']) ? 'valid' : 'absent/unverified' ?>
                        · <?= badge($row['status'] ?? null) ?>
                    </div>
                    <?php if ($findings !== null): ?>
                        <details class="xt-hero-explain">
                            <summary>How is this score calculated?</summary>
                            <?= health_score_breakdown($counts) ?>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
            <div class="xt-hero-actions">
                <button type="button" class="btn-ghost" data-print><?= report_icon('printer') ?> Print report</button>
                <?php if ($findings !== null): ?>
                    <a class="btn-ghost mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/findings"><?= report_icon('raw') ?> findings.json</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($findings !== null): ?>
            <div class="xt-sevbar" role="img" aria-label="<?= e($counts['total'] ?? 0) ?> checks total">
                <?php $sevTotal = max(1, (int) ($counts['total'] ?? 0));
                foreach (['red', 'orange', 'blue', 'green', 'grey'] as $c):
                    $n = (int) ($byPast[$c] ?? 0);
                    if ($n === 0) { continue; } ?>
                    <div class="xt-sevbar-seg dot-<?= $c ?>" style="width:<?= round($n / $sevTotal * 100, 2) ?>%" title="<?= e($t->pastille($c)) ?>: <?= e($n) ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="xt-hero-tally">
                <?php foreach (['red', 'orange', 'blue', 'green', 'grey'] as $c): ?>
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
                    <button data-filter="attn">Needs attention <b><?= e(($byPast['red'] ?? 0) + ($byPast['orange'] ?? 0)) ?></b></button>
                    <span class="filt-sep"></span>
                    <?php foreach ($catCount as $cat => $n): ?>
                        <button data-filter="<?= e($cat) ?>"><?= e($t->category($cat)) ?> <b><?= e($n) ?></b></button>
                    <?php endforeach; ?>
                </div>
                <table class="ftable"><tbody>
                <?php foreach ($all as $f): ?>
                    <?php $col = $f['pastille']; ?>
                    <tr class="frow <?= $stripe($col) ?>" data-cat="<?= e($f['category']) ?>" data-attn="<?= in_array($col, ['red', 'orange'], true) ? '1' : '0' ?>">
                        <td><?= pastille($col, $t->pastille($col)) ?></td>
                        <td class="id"><?= e($f['id']) ?></td>
                        <td class="tag"><?= e($t->category($f['category'])) ?></td>
                        <td class="rule"><?= e($t->title($f['id'])) ?></td>
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
            <div class="pending-note">Client, care plan and next renewal — sourced from the WooCommerce platform (not collected by the extraction). Coming soon.</div>
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
                <?php // Mail-delivery/authentication records only — CAA and A/AAAA are
                // general DNS, not email, and live in §Hosting next to SSL/TLS and
                // the server's IP address instead (2026-08-30, user: "Email et DNS,
                // ça ne devrait pas être dans la même section... ça n'a rien à voir").
                echo section('Email',
                    field('SPF', ($dns['spf']['present'] ?? false) ? 'present' : 'absent', ($dns['spf']['present'] ?? false) ? 'ok' : 'warn', 'probe.dns.spf.present')
                    . field_raw('SPF record', '<span class="mono">' . e($dns['spf']['record'] ?? '—') . '</span>', null, 'probe.dns.spf.record')
                    . field('DMARC', ($dns['dmarc']['present'] ?? false) ? ('p=' . ($dns['dmarc']['policy'] ?? 'none')) : 'absent', ($dns['dmarc']['present'] ?? false) ? null : 'warn', 'probe.dns.dmarc')
                    . field('DKIM', 'requires validation email', 'warn')
                    . field_raw('MX', fmt_list(array_map(static fn ($m) => $m['host'] ?? '', $dns['mx'] ?? [])), null, 'probe.dns.mx')
                ); ?>
            </div>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('hosting') ?><h3>Hosting</h3></div>
            <div class="cards cards-full">
                <?php echo section('Server',
                    field('Web server', $p['web_server'] ?? null, null, 'payload.web_server')
                    . field_raw('IP address (A / AAAA)', fmt_list($dns['a'] ?? []) . ' · ' . (($dns['aaaa'] ?? []) ? fmt_list($dns['aaaa']) : '<span class="val-muted">no IPv6</span>'), null, 'probe.dns.a')
                    . field('Hosting provider', 'from ASN lookup — coming soon', 'muted')
                    . field('Document root', $p['document_root'] ?? null, null, 'payload.document_root')
                    . field('Max execution time', ($p['php']['max_execution_time'] ?? null) !== null ? $p['php']['max_execution_time'] . ' s' : null, null, 'payload.php.max_execution_time')
                    . field('Post / upload max', ($p['php']['post_max_size'] ?? '?') . ' / ' . ($p['php']['upload_max_filesize'] ?? '?'), null, 'payload.php.post_max_size')
                    . field('Max input vars', $p['php']['max_input_vars'] ?? null, null, 'payload.php.max_input_vars')
                ); ?>
                <?php
                // SSL — TLS protocols shown independently, plus the cert facts.
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
                    // CAA is a DNS record, not an email one — it restricts which CAs may
                    // issue a certificate for this domain, so it belongs next to the
                    // certificate facts, not in §Domain & email's Email card.
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
                    // Full list, never truncated — a "+N" here hid exactly the
                    // extensions/functions an analyst most needs to check.
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
            // Every merged vulnerability across core/plugins/themes, for the detailed
            // CVE table further down. Core leads, then plugins and themes are appended
            // as their tables render.
            $allVulns = array_map(
                static fn (array $v): array => $v + ['component' => 'WordPress', 'slug' => 'wordpress'],
                $coreVulns
            );
            // Plugin-file keys (e.g. "akismet/akismet.php"), not normalized slugs — the
            // shape the plugin's own collector reports these two lists in.
            $autoUpdatePlugins = is_array($p['auto_update_plugins'] ?? null) ? $p['auto_update_plugins'] : [];
            $pluginUpdates     = is_array($p['plugin_updates'] ?? null) ? $p['plugin_updates'] : [];
            $themeUpdates      = is_array($p['theme_updates'] ?? null) ? $p['theme_updates'] : [];
            if ($plugins !== []): ?>
                <h4 style="font-size:.9rem;margin:0 0 .3rem" class="muted">Plugins — <?= count($plugins) ?> installed,
                    <?= count(array_filter($plugins, static fn ($x) => !empty($x['active']))) ?> active,
                    <?= count(array_filter($plugins, static fn ($x) => !empty($x['new_version']))) ?> with update</h4>
                <table><thead><tr><th>Name</th><th>Slug</th><th>Version</th><th>Update</th><th>Auto-update</th><th>Requires</th><th>Status</th><th>Vulnerabilities</th><th>Licence</th></tr></thead><tbody>
                <?php foreach ($plugins as $file => $pl):
                    $slug   = SoftwareCatalog::normalizeSlug('plugin', (string) ($pl['slug'] ?? ''));
                    $entry  = $catalog->get('plugin', (string) ($pl['slug'] ?? ''));
                    $merged = merge_vulnerabilities(
                        $bvPluginsBySlug[$slug]['vulnerabilities'] ?? [],
                        $wfPluginsBySlug[$slug]['vulnerabilities'] ?? [],
                        $pl['version'] ?? null
                    );
                    $hasUpdate = !empty($pl['new_version']) || in_array($file, $pluginUpdates, true);
                    $inactive  = empty($pl['active']);
                    foreach ($merged as $v) { $allVulns[] = $v + ['component' => $pl['name'] ?? $slug, 'slug' => $slug]; } ?>
                    <tr<?= $inactive ? ' class="row-inactive"' : '' ?>>
                        <td><?= e($pl['name'] ?? '?') ?></td>
                        <td class="mono"><?= e($pl['slug'] ?? '') ?></td>
                        <td class="mono"><?= e($pl['version'] ?? '?') ?></td>
                        <td><?= $hasUpdate ? '<span class="b-upd">' . e($pl['new_version'] ?: 'available') . '</span>' : '—' ?></td>
                        <td><?= in_array($file, $autoUpdatePlugins, true) ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-muted">No</span>' ?></td>
                        <td><?= requirement_cell($pl['requires_wp'] ?? null, $pl['requires_php'] ?? null, $p['wp_version'] ?? null, $p['php']['version'] ?? null) ?></td>
                        <td><?= !$inactive ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                        <td><?= $vulnCell($merged) ?></td>
                        <td><?php echo $entry ? license_select('plugin', (string) $entry['slug'], (string) ($entry['license'] ?? 'unknown'), $csrf,
                            '/site/' . e($siteId) . '/extraction/' . e($extractionId), $entry['suggested'] ?? null) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <?php if ($themes !== []): ?>
                <h4 style="font-size:.9rem;margin:1rem 0 .3rem" class="muted">Themes — <?= count($themes) ?> installed</h4>
                <table><thead><tr><th>Name</th><th>Slug</th><th>Version</th><th>Update</th><th>Requires</th><th>Template</th><th>Status</th><th>Vulnerabilities</th></tr></thead><tbody>
                <?php foreach ($themes as $file => $th):
                    $slug   = SoftwareCatalog::normalizeSlug('theme', (string) ($th['slug'] ?? ''));
                    $merged = merge_vulnerabilities(
                        $bvThemesBySlug[$slug]['vulnerabilities'] ?? [],
                        $wfThemesBySlug[$slug]['vulnerabilities'] ?? [],
                        $th['version'] ?? null
                    );
                    $hasUpdate = !empty($th['new_version']) || in_array($file, $themeUpdates, true);
                    $inactive  = empty($th['active']);
                    foreach ($merged as $v) { $allVulns[] = $v + ['component' => $th['name'] ?? $slug, 'slug' => $slug]; } ?>
                    <tr<?= $inactive ? ' class="row-inactive"' : '' ?>><td><?= e($th['name'] ?? '?') ?></td><td class="mono"><?= e($th['slug'] ?? '') ?></td>
                        <td class="mono"><?= e($th['version'] ?? '?') ?></td>
                        <td><?= $hasUpdate ? '<span class="b-upd">' . e($th['new_version'] ?: 'available') . '</span>' : '—' ?></td>
                        <td><?= requirement_cell($th['requires_wp'] ?? null, $th['requires_php'] ?? null, $p['wp_version'] ?? null, $p['php']['version'] ?? null) ?></td>
                        <td class="mono"><?= e($th['template'] ?? '') ?></td>
                        <td><?= !$inactive ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                        <td><?= $vulnCell($merged) ?></td></tr>
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
                $wpml = $p['connectors']['wpml'] ?? null;
                if (is_array($wpml)) {
                    echo section('Languages (WPML)',
                        field('Default language', $wpml['default_language'] ?? null, null, 'payload.connectors.wpml.default_language')
                        . field('Current language', $wpml['current_language'] ?? null, null, 'payload.connectors.wpml.current_language')
                        . field_raw('Active languages', fmt_list($wpml['active_languages'] ?? []), null, 'payload.connectors.wpml.active_languages')
                        . field('WPML version', $wpml['version'] ?? null, null, 'payload.connectors.wpml.version')
                    );
                } else {
                    echo '<div class="card"><h3>Languages</h3><div style="padding:1rem 1.1rem"><div class="pending-note">No multilingual connector (WPML) detected on this site.</div></div></div>';
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
                // "login (email)" for manual review — added 2026-08-31, reframed from
                // an earlier "flag a login literally named admin" idea: the raw list
                // lets an analyst judge every account, rather than trust one heuristic.
                // Falls back to a bare string for an older stored extraction whose
                // super_admins predates the email field (a plain login-string list
                // then) — already-completed extractions keep their frozen shape.
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
                    . field_raw('Admin logins', fmt_list(array_map($adminLabel, $admins)), null, 'payload.administrators')
                    . field_raw('Super admins (network)', fmt_list(array_map($adminLabel, (array) ($p['super_admins'] ?? []))), null, 'payload.super_admins')
                );
                ?>
            </div>
        </div>
    </section>

    <!-- ============================== QUALITY & SECURITY ============================== -->
    <!-- Performance · SEO & analytics · Security & backup -->
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
                    <th>LCP</th><th>CLS</th><th>Field</th></tr></thead><tbody>
                <?php foreach ($ps as $strat => $r): if (!is_array($r)) { continue; } ?>
                    <tr><td><strong><?= e($strat) ?></strong></td>
                        <?php foreach (($mobilePs['scores'] ?? []) as $c => $_): $sc = $r['scores'][$c] ?? null; ?>
                            <td><?= $sc === null ? '—' : badge_score((int) $sc) ?></td>
                        <?php endforeach; ?>
                        <td class="num"><?= isset($r['lab']['lcp']['value']) ? e(round((float) $r['lab']['lcp']['value'])) . ' ms' : '—' ?></td>
                        <td class="num"><?= e($r['lab']['cls']['display'] ?? '—') ?></td>
                        <td><?= e($r['field']['overall_category'] ?? '—') ?></td></tr>
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
                    . field('HTTPS forced', $http['redirects']['forces_https'] ?? null, ($http['redirects']['forces_https'] ?? true) ? 'ok' : 'warn', 'probe.http.redirects.forces_https')
                    . field('Redirects', $http['redirects']['hops'] ?? null, null, 'probe.http.redirects.hops')
                    . field('CDN', $http['cdn'] ?? '—', null, 'probe.http.cdn')
                ); ?>
                <?php echo section('Cache',
                    field_raw('Autoload', fmt_bytes($p['autoload']['total_bytes'] ?? null) . ' <span class="val-muted">(' . e($p['autoload']['count'] ?? '?') . ' options)</span>', null, 'payload.autoload.total_bytes')
                    . field('Object cache', ($p['object_cache']['external'] ?? false) ? 'external' : 'none', ($p['object_cache']['external'] ?? false) ? 'ok' : 'warn', 'payload.object_cache.external')
                    // Confirmed against the plugin's actual collector source
                    // (2026-08-30): this is isset($dropins['advanced-cache.php'])
                    // — file presence only, via WordPress's own get_dropins(),
                    // never a check of the WP_CACHE constant. A stale drop-in
                    // left behind by a deactivated caching plugin reads
                    // "present" exactly the same as one actually in use — the
                    // label says what was actually measured, not "is caching
                    // active right now".
                    . field('Page cache drop-in', ($p['object_cache']['page_cache'] ?? false) ? 'present' : 'absent', null, 'payload.object_cache.page_cache')
                ); ?>
            </div>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('seo') ?><h3>SEO &amp; analytics</h3></div>
            <div class="cards">
                <?php
                $robots = $http['robots'] ?? [];
                echo section('SEO',
                    field('robots.txt', ($robots['present'] ?? false) ? 'present' : 'absent', ($robots['present'] ?? false) ? 'ok' : 'warn', 'probe.http.robots.present')
                    . field('Blocks whole site', ($robots['disallow_all'] ?? false) ? 'yes' : 'no', ($robots['disallow_all'] ?? false) ? 'error' : 'ok', 'probe.http.robots.disallow_all')
                    . field_raw('Sitemaps', fmt_list($robots['sitemaps'] ?? []), null, 'probe.http.robots.sitemaps')
                    . field('Sitemap reachable', isset($robots['sitemap_reachable']) ? (($robots['sitemap_reachable']) ? 'yes' : 'no') : '—', null, 'probe.http.robots.sitemap_reachable')
                    . field('SEO score (mobile)', $mobilePs['scores']['seo'] ?? null, null, 'probe.pagespeed.scores.seo')
                );
                ?>
            </div>
        </div>

        <div class="xt-subsection">
            <div class="xt-subsection-head"><?= report_icon('security') ?><h3>Security &amp; backup</h3></div>
            <div class="cards">
                <?php
                $const = $p['constants'] ?? [];
                $constRows = '';
                foreach ($const as $name => $value) {
                    $bad = in_array($name, ['WP_DEBUG', 'WP_DEBUG_DISPLAY'], true) && $value === true;
                    $constRows .= field($name, $value, $bad ? 'error' : null, 'payload.constants.' . $name);
                }
                echo section('Hardening (constants)', $constRows ?: field('constants', null), class: 'card-full');
                ?>
                <?php
                $sec = $http['security_headers'] ?? [];
                echo section('Security headers',
                    field('X-Content-Type-Options', $sec['x-content-type-options'] ?? 'missing', ($sec['x-content-type-options'] ?? null) ? 'ok' : 'warn', 'probe.http.security_headers.x-content-type-options')
                    . field('X-Frame-Options', $sec['x-frame-options'] ?? 'missing', null, 'probe.http.security_headers.x-frame-options')
                    . field('Content-Security-Policy', ($sec['content-security-policy'] ?? null) ? 'present' : 'missing', ($sec['content-security-policy'] ?? null) ? 'ok' : 'warn', 'probe.http.security_headers.content-security-policy')
                    . field('Referrer-Policy', ($sec['referrer-policy'] ?? null) ? 'present' : 'missing', null, 'probe.http.security_headers.referrer-policy')
                    . field('Permissions-Policy', ($sec['permissions-policy'] ?? null) ? 'present' : 'missing', null, 'probe.http.security_headers.permissions-policy')
                    . field('HSTS', ($sec['strict-transport-security'] ?? null) ? 'present' : 'missing', ($sec['strict-transport-security'] ?? null) ? 'ok' : 'warn', 'probe.http.security_headers.strict-transport-security')
                ); ?>
                <?php
                $fs = $p['filesystem'] ?? [];
                echo section('Filesystem',
                    field_raw('Free disk', fmt_bytes($fs['disk_free_bytes'] ?? null) . ' / ' . fmt_bytes($fs['disk_total_bytes'] ?? null), null, 'payload.filesystem.disk_free_bytes')
                    . field('Core writable', $fs['core_writable'] ?? null, ($fs['core_writable'] ?? false) ? 'warn' : 'ok', 'payload.filesystem.core_writable')
                    . field('Uploads writable', $fs['uploads_writable'] ?? null, null, 'payload.filesystem.uploads_writable')
                ); ?>
                <?php
                // Passive attack-surface checks (HttpProbe::exposureCheck()) — every
                // one of these is a request an anonymous visitor could already make.
                // Every row carries its own evidence (exact URL + status an analyst
                // could re-run by hand with curl) rather than asking to be trusted.
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
                // A 401 on the homepage itself is not the same fact as "checked,
                // nothing found" — every row below would also 401 regardless of
                // what it tests for, so HttpProbe skips them all rather than
                // report a false "clean" (2026-08-30, "ce n'est pas ce que c'est
                // ok... c'est que le site n'est pas public"). Say so plainly
                // instead of letting six identical "not checked" rows imply an
                // unrelated (and less alarming) reason, like the soft-404 skip.
                if (($exp['auth_required'] ?? false) === true) {
                    echo '<div class="card card-full"><h3>Exposure</h3><div style="padding:1rem 1.1rem">'
                        . '<div class="pending-note" style="border-color:var(--warn);background:var(--warn-bg)">'
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
                // No "Integrity, malware & backup" card here (removed
                // 2026-08-31): BlogVault's scanner status, firewall mode,
                // backup state and remediation detail are all *current
                // operational* facts, not a fact about the site's
                // configuration at the moment of this extraction — out of
                // scope for a snapshot-in-time tool by design, not merely
                // unbuilt. See the golden rules in CLAUDE.md.
                ?>
            </div>
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
                        <td><?= !empty($perm['writable']) ? '<span class="badge badge-warn">Yes</span>' : '<span class="badge badge-ok">No</span>' ?></td>
                        <td><?= !empty($perm['readable']) ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-error">No</span>' ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
    </section>

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
