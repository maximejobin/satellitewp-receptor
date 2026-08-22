<?php
/** @var \SatelliteWP\Xtractor\Rules\Translator $t */
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
$coreVulns = merge_vulnerabilities($bv['core']['vulnerabilities'] ?? [], $wf['core']['vulnerabilities'] ?? []);

/** Compact "N CVE" / "—" cell for a table row, from an already-merged list. */
$vulnCell = static function (array $merged): string {
    if ($merged === []) {
        return '<span class="badge badge-ok">—</span>';
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
?>

<h1><?= e($site['name'] ?? $siteId) ?></h1>
<p class="muted">
    <a href="<?= e($site['site_url'] ?? '#') ?>"><?= e($site['site_url'] ?? '') ?></a>
    · <?= e($t->ui('received')) ?> <?= e($meta['received_at'] ?? '?') ?>
    · signature <?= !empty($meta['signature_valid']) ? 'valid' : 'absent/unverified' ?>
    · schema <?= e($meta['schema_version'] ?? '?') ?>
    · <?= badge($row['status'] ?? null) ?>
</p>

<?php
$status   = (string) ($row['status'] ?? '');
$awaiting = in_array($status, ['pending', 'queued'], true);
if ($awaiting):
    $bvFound = ($blogVault['found'] ?? false) === true;
?>
    <!-- Analysis not run yet: pre-flight + the manual trigger -->
    <section class="section">
        <h2>Analyse</h2>
        <div style="padding:0 1.1rem 1.1rem">
        <?php if ($status === 'queued'): ?>
            <p><span class="badge badge-ok">En file</span>
               L'analyse est en attente du worker (cron <span class="mono">ingest:process</span>,
               chaque minute). Rafraîchis la page dans un moment.</p>
        <?php else: ?>
            <p class="muted">Cette extraction a été reçue mais <b>pas encore analysée</b>.
               Aucune sonde n'a tourné, aucun quota n'a été consommé.</p>

            <?php if (($blogVault['configured'] ?? false) !== true): ?>
                <p><span class="badge badge-muted">BlogVault non configuré</span>
                   Impossible de vérifier si le site y est géré.</p>
            <?php elseif (!empty($blogVault['error'])): ?>
                <p><span class="badge badge-warn">BlogVault injoignable</span>
                   <span class="mono"><?= e($blogVault['error']) ?></span></p>
            <?php elseif ($bvFound): ?>
                <p><span class="badge badge-ok">Sur BlogVault</span>
                   <?= e($blogVault['name'] ?? '') ?>
                   <span class="mono muted"><?= e($blogVault['id'] ?? '') ?></span></p>
            <?php else: ?>
                <p><span class="badge badge-error">Absent de BlogVault</span>
                   <span class="mono"><?= e($blogVault['host'] ?? '') ?></span> n'est pas dans le compte —
                   le site n'est probablement pas sous plan de maintenance.
                   Les règles <span class="mono">BV1</span>–<span class="mono">BV6</span> resteront indéterminées.</p>
            <?php endif; ?>

            <form method="post" action="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/run"
                  <?= $bvFound ? '' : 'onsubmit="return confirm(\'Ce site n\\\'est pas sur BlogVault. Lancer l\\\'analyse quand même ?\')"' ?>>
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="return" value="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>">
                <button type="submit" class="btn"><?= $bvFound ? 'Lancer l\'analyse' : 'Lancer l\'analyse quand même' ?></button>
            </form>
        <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($findings !== null): ?>
    <!-- Overview -->
    <section class="overview">
        <div class="ring" style="--p:<?= e($score) ?>;--c:<?= health_color($score) ?>">
            <div class="inner"><div class="score"><?= e($score) ?></div><div class="cap">health</div></div>
        </div>
        <div class="tally">
            <div class="lede"><b><?= e($counts['fail'] ?? 0) ?></b> of <?= e($counts['total'] ?? 0) ?> checks need attention.</div>
            <div class="tally-row">
                <?php foreach (['red', 'orange', 'blue', 'green', 'grey'] as $c): ?>
                    <span class="chip"><span class="dot dot-<?= $c ?>"></span><?= e($t->pastille($c)) ?> <b><?= e($byPast[$c] ?? 0) ?></b></span>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- KPI tiles -->
<section class="tiles">
    <div class="tile"><div class="k">WordPress</div><div class="v"><?= e($p['wp_version'] ?? '—') ?></div>
        <div class="s<?= ($eolWp[0] ?? false) ? ' crit' : '' ?>"><?= ($eolWp[0] ?? false) ? 'end of life' : 'core' ?></div></div>
    <div class="tile"><div class="k">PHP</div><div class="v"><?= e($p['php']['version'] ?? '—') ?></div>
        <div class="s<?= ($eolPhp[0] ?? false) ? ' crit' : '' ?>"><?= isset($eolPhp[1]) ? 'until ' . e(substr((string) $eolPhp[1], 0, 4)) : '' ?></div></div>
    <div class="tile"><div class="k">Database</div><div class="v"><?= e(($p['database_type'] ?? '') . ' ' . implode('.', array_slice(explode('.', (string) ($p['database_version'] ?? '')), 0, 2))) ?></div>
        <div class="s<?= ($eolDb[0] ?? false) ? ' crit' : '' ?>"><?= ($eolDb[0] ?? false) ? ('EOL ' . e(substr((string) ($eolDb[1] ?? ''), 0, 7))) : '' ?></div></div>
    <div class="tile"><div class="k">SSL expiry</div><div class="v"><?= isset($tls['days_to_expiry']) ? e($tls['days_to_expiry']) . ' d' : '—' ?></div><div class="s"><?= e($tls['issuer'] ?? '') ?></div></div>
    <div class="tile"><div class="k">Domain expiry</div><div class="v"><?= isset($rdap['days_to_expiry']) ? e($rdap['days_to_expiry']) . ' d' : '—' ?></div>
        <div class="s<?= (($rdap['days_to_expiry'] ?? 999) < 30) ? ' warn' : '' ?>"><?= (($rdap['days_to_expiry'] ?? 999) < 30) ? 'renew soon' : '' ?></div></div>
    <div class="tile"><div class="k">TTFB</div><div class="v"><?= isset($http['ttfb_ms']) ? e($http['ttfb_ms']) . ' ms' : '—' ?></div>
        <div class="s">HTTP/<?= e($http['http_version'] ?? '?') ?><?= isset($http['content_encoding']) ? ' · ' . e($http['content_encoding']) : '' ?></div></div>
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

<h2 class="eyebrow" style="margin-top:.8rem"><?= e($t->ui('information')) ?></h2>

<!-- §1 Account & plan (pending) -->
<section class="section"><h2>Account &amp; plan</h2>
    <div class="pending-note">Client, care plan and next renewal — sourced from the WooCommerce platform (not collected by the extraction). Coming soon.</div>
</section>

<!-- §2 Domain & email -->
<section class="section"><h2>Domain &amp; email</h2>
    <div class="cards">
        <?php echo section('Domain',
            field('Registrar', $rdap['registrar'] ?? null)
            . field('Created', $rdap['created_at'] ?? null)
            . field_raw('Expires', e($rdap['expires_at'] ?? '—') . (isset($rdap['days_to_expiry'])
                ? ' <span class="' . ($rdap['days_to_expiry'] < 30 ? 'val-warn' : 'val-muted') . '">(' . e($rdap['days_to_expiry']) . ' d)</span>' : ''))
            . field('Statuses', is_array($rdap['statuses'] ?? null) ? implode(', ', $rdap['statuses']) : null)
            . field_raw('Nameservers', fmt_list($rdap['nameservers'] ?? ($dns['nameservers'] ?? [])))
            . field('Source', $rdap['source'] ?? null)
        ); ?>
        <?php echo section('Email (DNS)',
            field('SPF', ($dns['spf']['present'] ?? false) ? 'present' : 'absent', ($dns['spf']['present'] ?? false) ? 'ok' : 'warn')
            . field_raw('SPF record', '<span class="mono">' . e($dns['spf']['record'] ?? '—') . '</span>')
            . field('DMARC', ($dns['dmarc']['present'] ?? false) ? ('p=' . ($dns['dmarc']['policy'] ?? 'none')) : 'absent', ($dns['dmarc']['present'] ?? false) ? null : 'warn')
            . field('DKIM', 'requires validation email', 'warn')
            . field_raw('MX', fmt_list(array_map(static fn ($m) => $m['host'] ?? '', $dns['mx'] ?? [])))
            . field_raw('CAA', fmt_list(array_map(static fn ($c) => $c['value'] ?? '', $dns['caa'] ?? [])))
            . field_raw('A / AAAA', fmt_list($dns['a'] ?? []) . ' · ' . (($dns['aaaa'] ?? []) ? fmt_list($dns['aaaa']) : '<span class="val-muted">no IPv6</span>'))
        ); ?>
    </div>
</section>

<!-- §3 Hosting -->
<section class="section"><h2>Hosting</h2>
    <div class="cards">
        <?php echo section('Server',
            field('Web server', $p['web_server'] ?? null)
            . field('IP address', $dns['a'][0] ?? null)
            . field('Hosting provider', 'from ASN lookup — coming soon', 'muted')
            . field('Document root', $p['document_root'] ?? null)
            . field('Max execution time', ($p['php']['max_execution_time'] ?? null) !== null ? $p['php']['max_execution_time'] . ' s' : null)
            . field('Post / upload max', ($p['php']['post_max_size'] ?? '?') . ' / ' . ($p['php']['upload_max_filesize'] ?? '?'))
            . field('Max input vars', $p['php']['max_input_vars'] ?? null)
        ); ?>
        <?php
        // SSL — TLS protocols shown independently, plus the cert facts.
        $proto = $tls['protocols'] ?? [];
        $protoRow = static function (string $label, ?bool $on, bool $legacy) {
            if ($on === null) { return field($label, null); }
            return field($label, $on ? 'accepted' : 'no', $on && $legacy ? 'warn' : ($on ? 'ok' : 'muted'));
        };
        echo section('SSL / TLS',
            field('Issuer', $tls['issuer'] ?? null)
            . field('Subject (CN)', $tls['subject_cn'] ?? null)
            . field_raw('Expires', e($tls['not_after'] ?? '—') . (isset($tls['days_to_expiry']) ? ' (' . e($tls['days_to_expiry']) . ' d)' : ''))
            . field_raw('SAN', fmt_list($tls['san'] ?? []))
            . field('Chain valid', $tls['chain_valid'] ?? null, ($tls['chain_valid'] ?? true) ? 'ok' : 'error')
            . field('Hostname covered', $tls['hostname_covered'] ?? null, ($tls['hostname_covered'] ?? true) ? 'ok' : 'error')
            . field('Self-signed', $tls['self_signed'] ?? null, ($tls['self_signed'] ?? false) ? 'error' : 'ok')
            . $protoRow('TLS 1.0', $proto['tls1_0'] ?? null, true)
            . $protoRow('TLS 1.1', $proto['tls1_1'] ?? null, true)
            . $protoRow('TLS 1.2', $proto['tls1_2'] ?? null, false)
            . $protoRow('TLS 1.3', $proto['tls1_3'] ?? null, false)
        );
        ?>
        <?php echo section('PHP',
            field_raw('Version', e($p['php']['version'] ?? '—') . eol_annotation($eolPhp, $t))
            . field('Memory limit', $p['php']['memory_limit'] ?? null)
            . field_raw('Extensions (' . count($p['php']['extensions'] ?? []) . ')', fmt_list($p['php']['extensions'] ?? [], 40))
            . field_raw('Disabled functions', fmt_list($p['php']['disable_functions'] ?? []))
        ); ?>
        <?php echo section('Database',
            field('Type', $p['database_type'] ?? null)
            . field_raw('Version', e($p['database_version'] ?? '—') . eol_annotation($eolDb, $t))
            . field('Prefix', $p['db_table_prefix'] ?? null, ($p['db_table_prefix'] ?? '') === 'wp_' ? 'warn' : null)
            . field_raw('Size', fmt_bytes($p['database']['total_bytes'] ?? null))
            . field_raw('Transients', e($p['database']['transients']['total'] ?? '?') . ' (' . e($p['database']['transients']['expired'] ?? '?') . ' expired)')
        ); ?>
    </div>
    <?php
    $tables = $p['database']['tables'] ?? [];
    if (is_array($tables) && $tables !== []): ?>
        <h3 style="font-size:.9rem;margin:.9rem 0 .3rem" class="muted">Largest tables</h3>
        <table><thead><tr><th>Table</th><th>Rows</th><th>Size</th><th>Overhead</th></tr></thead><tbody>
        <?php usort($tables, static fn ($a, $b) => ($b['size_bytes'] ?? 0) <=> ($a['size_bytes'] ?? 0));
        foreach (array_slice($tables, 0, 10) as $tb): ?>
            <tr><td class="mono"><?= e($tb['name'] ?? '?') ?></td><td class="num"><?= e($tb['row_count'] ?? '—') ?></td>
                <td class="num"><?= fmt_bytes($tb['size_bytes'] ?? null) ?></td><td class="num"><?= fmt_bytes($tb['overhead_bytes'] ?? 0) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</section>

<!-- §4 Performance -->
<section class="section"><h2>Performance</h2>
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
        $sec = $http['security_headers'] ?? [];
        echo section('HTTP',
            field('Status code', $http['status_code'] ?? null)
            . field('HTTP version', isset($http['http_version']) ? 'HTTP/' . $http['http_version'] : null)
            . field('Compression (HTML)', $http['content_encoding'] ?? 'none', ($http['content_encoding'] ?? null) ? 'ok' : 'warn')
            . field('Compression (asset)', ($http['asset']['content_encoding'] ?? null) ?? (($http['asset']['checked'] ?? false) ? 'none' : 'n/a'))
            . field_raw('TTFB', ($http['ttfb_ms'] ?? null) !== null ? '<span class="' . ($http['ttfb_ms'] > 600 ? 'val-warn' : '') . '">' . e($http['ttfb_ms']) . ' ms</span>' : '—')
            . field('HTTPS forced', $http['redirects']['forces_https'] ?? null, ($http['redirects']['forces_https'] ?? true) ? 'ok' : 'warn')
            . field('Redirects', $http['redirects']['hops'] ?? null)
            . field('CDN', $http['cdn'] ?? '—')
        ); ?>
        <?php echo section('Security headers',
            field('X-Content-Type-Options', $sec['x-content-type-options'] ?? 'missing', ($sec['x-content-type-options'] ?? null) ? 'ok' : 'warn')
            . field('X-Frame-Options', $sec['x-frame-options'] ?? 'missing')
            . field('Content-Security-Policy', ($sec['content-security-policy'] ?? null) ? 'present' : 'missing', ($sec['content-security-policy'] ?? null) ? 'ok' : 'warn')
            . field('Referrer-Policy', ($sec['referrer-policy'] ?? null) ? 'present' : 'missing')
            . field('HSTS', ($sec['strict-transport-security'] ?? null) ? 'present' : 'missing', ($sec['strict-transport-security'] ?? null) ? 'ok' : 'warn')
        ); ?>
        <?php echo section('Cache & cron',
            field_raw('Autoload', fmt_bytes($p['autoload']['total_bytes'] ?? null) . ' <span class="val-muted">(' . e($p['autoload']['count'] ?? '?') . ' options)</span>')
            . field('Object cache', ($p['object_cache']['external'] ?? false) ? 'external' : 'none', ($p['object_cache']['external'] ?? false) ? 'ok' : 'warn')
            . field('Page cache', ($p['object_cache']['page_cache'] ?? false) ? 'yes' : 'no')
            . field('WP-Cron', ($p['cron']['disabled'] ?? false) ? 'disabled' : 'enabled')
            . field('Cron overdue', $p['cron']['overdue_events'] ?? null, ($p['cron']['overdue_events'] ?? 0) > 0 ? 'warn' : 'ok')
            . field('Scheduled events', $p['cron']['scheduled_events'] ?? null)
        ); ?>
    </div>
</section>

<!-- §5 SEO & analytics -->
<section class="section"><h2>SEO &amp; analytics</h2>
    <div class="cards">
        <?php
        $robots = $http['robots'] ?? [];
        echo section('SEO',
            field('robots.txt', ($robots['present'] ?? false) ? 'present' : 'absent', ($robots['present'] ?? false) ? 'ok' : 'warn')
            . field('Blocks whole site', ($robots['disallow_all'] ?? false) ? 'yes' : 'no', ($robots['disallow_all'] ?? false) ? 'error' : 'ok')
            . field_raw('Sitemaps', fmt_list($robots['sitemaps'] ?? []))
            . field('Sitemap reachable', isset($robots['sitemap_reachable']) ? (($robots['sitemap_reachable']) ? 'yes' : 'no') : '—')
            . field('SEO score (mobile)', $mobilePs['scores']['seo'] ?? null)
        );
        ?>
        <div class="card"><h3>Analytics &amp; tracking</h3>
            <div style="padding:1rem 1.1rem"><div class="pending-note">Google Analytics, Ads remarketing, Facebook pixel and Google tag detection — needs a new extractor field. Coming soon.</div></div>
        </div>
    </div>
</section>

<!-- §6 WordPress -->
<section class="section"><h2>WordPress</h2>
    <div class="cards">
        <?php
        echo section('Core & settings',
            field_raw('Version', e($p['wp_version'] ?? '—') . eol_annotation($eolWp, $t))
            . field_raw('Vulnérabilités connues', $coreVulns !== []
                ? '<span class="badge badge-error">' . count($coreVulns) . ' CVE</span> — voir §Plugins &amp; thèmes'
                : '<span class="badge badge-ok">—</span>')
            . field('Core update', $p['core_update']['available_version'] ?? ($p['core_update']['status'] ?? null), !empty($p['core_update']['available_version']) ? 'warn' : 'ok')
            . field('Auto-update core', $p['core_update']['auto_update_core'] ?? null)
            . field('Multisite', $p['is_multisite'] ?? null)
            . field('Active theme', $p['active_theme'] ?? null)
            . field('Language', $p['site_locale'] ?? null)
            . field('Timezone', $p['timezone_string'] ?? null)
            . field('Admin email', $p['website_administrator_email'] ?? null)
            . field('Permalinks', $p['permalink_structure'] ?? null)
        ); ?>
    </div>
</section>

<!-- §7 Plugins & themes -->
<section class="section"><h2>Plugins &amp; themes</h2>
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
    if ($plugins !== []): ?>
        <h3 style="font-size:.9rem;margin:0 0 .3rem" class="muted">Plugins — <?= count($plugins) ?> installed,
            <?= count(array_filter($plugins, static fn ($x) => !empty($x['active']))) ?> active,
            <?= count(array_filter($plugins, static fn ($x) => !empty($x['new_version']))) ?> with update</h3>
        <table><thead><tr><th>Name</th><th>Slug</th><th>Version</th><th>Update</th><th>Requires</th><th>Status</th><th>Vulnérabilités</th><th>Licence</th></tr></thead><tbody>
        <?php foreach ($plugins as $pl):
            $slug   = SoftwareCatalog::normalizeSlug('plugin', (string) ($pl['slug'] ?? ''));
            $entry  = $catalog->get('plugin', (string) ($pl['slug'] ?? ''));
            $merged = merge_vulnerabilities(
                $bvPluginsBySlug[$slug]['vulnerabilities'] ?? [],
                $wfPluginsBySlug[$slug]['vulnerabilities'] ?? []
            );
            foreach ($merged as $v) { $allVulns[] = $v + ['component' => $pl['name'] ?? $slug, 'slug' => $slug]; } ?>
            <tr>
                <td><?= e($pl['name'] ?? '?') ?></td>
                <td class="mono"><?= e($pl['slug'] ?? '') ?></td>
                <td class="mono"><?= e($pl['version'] ?? '?') ?></td>
                <td><?= !empty($pl['new_version']) ? '<span class="b-upd">' . e($pl['new_version']) . '</span>' : '—' ?></td>
                <td class="muted">WP <?= e($pl['requires_wp'] ?? '—') ?> · PHP <?= e($pl['requires_php'] ?? '—') ?></td>
                <td><?= !empty($pl['active']) ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                <td><?= $vulnCell($merged) ?></td>
                <td><?php echo $entry ? license_select('plugin', (string) $entry['slug'], (string) ($entry['license'] ?? 'unknown'), $csrf,
                    '/site/' . e($siteId) . '/extraction/' . e($extractionId), $entry['suggested'] ?? null) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
    <?php if ($themes !== []): ?>
        <h3 style="font-size:.9rem;margin:1rem 0 .3rem" class="muted">Themes — <?= count($themes) ?> installed</h3>
        <table><thead><tr><th>Name</th><th>Slug</th><th>Version</th><th>Update</th><th>Template</th><th>Status</th><th>Vulnérabilités</th></tr></thead><tbody>
        <?php foreach ($themes as $th):
            $slug   = SoftwareCatalog::normalizeSlug('theme', (string) ($th['slug'] ?? ''));
            $merged = merge_vulnerabilities(
                $bvThemesBySlug[$slug]['vulnerabilities'] ?? [],
                $wfThemesBySlug[$slug]['vulnerabilities'] ?? []
            );
            foreach ($merged as $v) { $allVulns[] = $v + ['component' => $th['name'] ?? $slug, 'slug' => $slug]; } ?>
            <tr><td><?= e($th['name'] ?? '?') ?></td><td class="mono"><?= e($th['slug'] ?? '') ?></td>
                <td class="mono"><?= e($th['version'] ?? '?') ?></td>
                <td><?= !empty($th['new_version']) ? '<span class="b-upd">' . e($th['new_version']) . '</span>' : '—' ?></td>
                <td class="mono"><?= e($th['template'] ?? '') ?></td>
                <td><?= !empty($th['active']) ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                <td><?= $vulnCell($merged) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
    <?php if ($allVulns !== []): ?>
        <h3 style="font-size:.9rem;margin:1rem 0 .3rem" class="muted">Vulnérabilités détectées — <?= count($allVulns) ?></h3>
        <table><thead><tr><th>Composant</th><th>CVE</th><th>Titre</th><th>CVSS</th><th>Version corrigée</th><th>Source</th></tr></thead><tbody>
        <?php foreach ($allVulns as $v): ?>
            <tr>
                <td class="mono"><?= e($v['component']) ?></td>
                <td class="mono"><?= $v['cve_id'] ? e($v['cve_id']) : '<span class="muted">—</span>' ?></td>
                <td><?= e($v['title'] ?? '—') ?></td>
                <td><?= $v['cvss_score'] !== null ? e($v['cvss_score']) . ' (' . e($v['cvss_rating'] ?? '?') . ')' : '—' ?></td>
                <td class="mono"><?= e($v['patched_version'] ?? '—') ?></td>
                <td><?= vulnerability_source_badge($v['sources']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</section>

<!-- §8 Content & languages -->
<section class="section"><h2>Content &amp; languages</h2>
    <div class="cards">
        <?php echo section('Content',
            field('Posts', $p['posts_count'] ?? null)
            . field('Pages', $p['page_count'] ?? null)
            . field('Media', $p['media_count'] ?? null)
            . field('Comments', $p['comments_count'] ?? null)
        ); ?>
        <?php
        $wpml = $p['connectors']['wpml'] ?? null;
        if (is_array($wpml)) {
            echo section('Languages (WPML)',
                field('Default language', $wpml['default_language'] ?? null)
                . field('Current language', $wpml['current_language'] ?? null)
                . field_raw('Active languages', fmt_list($wpml['active_languages'] ?? []))
                . field('WPML version', $wpml['version'] ?? null)
            );
        } else {
            echo '<div class="card"><h3>Languages</h3><div style="padding:1rem 1.1rem"><div class="pending-note">No multilingual connector (WPML) detected on this site.</div></div></div>';
        }
        ?>
    </div>
</section>

<!-- §9 Users -->
<section class="section"><h2>Users</h2>
    <div class="cards">
        <?php
        $admins = is_array($p['administrators'] ?? null) ? $p['administrators'] : [];
        echo section('Accounts',
            field('Total users', $p['users_count'] ?? null)
            . field('Administrators', count($admins), count($admins) > 5 ? 'warn' : 'ok')
            . field_raw('Admin logins', fmt_list(array_map(static fn ($a) => $a['login'] ?? '?', $admins)))
        );
        ?>
    </div>
</section>

<!-- §10 Security & backup -->
<section class="section"><h2>Security &amp; backup</h2>
    <div class="cards">
        <?php
        $const = $p['constants'] ?? [];
        $constRows = '';
        foreach ($const as $name => $value) {
            $bad = in_array($name, ['WP_DEBUG', 'WP_DEBUG_DISPLAY'], true) && $value === true;
            $constRows .= field($name, $value, $bad ? 'error' : null);
        }
        echo section('Hardening (constants)', $constRows ?: field('constants', null));
        ?>
        <?php
        $fs = $p['filesystem'] ?? [];
        echo section('Filesystem',
            field_raw('Free disk', fmt_bytes($fs['disk_free_bytes'] ?? null) . ' / ' . fmt_bytes($fs['disk_total_bytes'] ?? null))
            . field('Core writable', $fs['core_writable'] ?? null, ($fs['core_writable'] ?? false) ? 'warn' : 'ok')
            . field('Uploads writable', $fs['uploads_writable'] ?? null)
        ); ?>
        <div class="card"><h3>Integrity, malware &amp; backup</h3>
            <div style="padding:1rem 1.1rem"><div class="pending-note">Core file integrity, malware/vulnerability scan and backup status — from BlogVault (integration pending).</div></div>
        </div>
    </div>
</section>

<!-- Probes + raw -->
<h2 class="eyebrow" style="margin-top:1rem">Raw</h2>
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
