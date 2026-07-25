<?php
/** @var \SatelliteWP\Xtractor\Rules\Translator $t */
$qs = static fn (): string => $lang === 'en' ? '' : '?lang=' . e($lang);
?>
<nav class="breadcrumb">
    <a href="/<?= $qs() ?>"><?= e($t->ui('sites')) ?></a> ›
    <a href="/site/<?= e($siteId) . $qs() ?>"><?= e($site['name'] ?? $siteId) ?></a> ›
    <span class="mono"><?= e($extractionId) ?></span>
</nav>

<h1><?= e($t->ui('extraction')) ?> <span class="mono"><?= e($extractionId) ?></span></h1>
<p class="muted">
    <?= e($t->ui('received')) ?> <?= e($meta['received_at'] ?? '?') ?>
    · signature <?= !empty($meta['signature_valid']) ? 'valid' : 'absent/unverified' ?>
    · schema <?= e($meta['schema_version'] ?? '?') ?>
    · <?= fmt_bytes($meta['body_bytes'] ?? null) ?>
    · <?= badge($row['status'] ?? null) ?>
    <span class="lang-switch">· <a href="?lang=en">EN</a> / <a href="?lang=fr">FR</a></span>
</p>

<?php
$p    = $payload;
$dns  = $probes['dns']['data'] ?? [];
$tls  = $probes['tls']['data'] ?? [];
$rdap = $probes['rdap']['data'] ?? [];
$http = $probes['http']['data'] ?? [];

$eolPhp = $eol->eolStatus('php', (string) ($p['php']['version'] ?? ''));
$eolWp  = $eol->eolStatus('wordpress', (string) ($p['wp_version'] ?? ''));
$dbType = str_contains(strtolower((string) ($p['database_type'] ?? '')), 'maria') ? 'mariadb'
    : (str_contains(strtolower((string) ($p['database_type'] ?? '')), 'mysql') ? 'mysql' : null);
$eolDb  = $dbType !== null ? $eol->eolStatus($dbType, (string) ($p['database_version'] ?? '')) : null;
?>

<h2><?= e($t->ui('information')) ?></h2>
<div class="cards info-cards">

    <?php
    // --- Domain (RDAP) ---
    if ($rdap !== []) {
        $days = $rdap['days_to_expiry'] ?? null;
        echo section('Domain',
            field('Target', $probes['rdap']['target'] ?? null)
            . field('Registrar', $rdap['registrar'] ?? null)
            . field('Created', $rdap['created_at'] ?? null)
            . field_raw('Expires', e($rdap['expires_at'] ?? '—')
                . ($days !== null ? ' <span class="' . ($days < 30 ? 'val-warn' : 'val-muted') . '">(' . e($days) . ' d)</span>' : ''))
            . field('Statuses', is_array($rdap['statuses'] ?? null) ? implode(', ', $rdap['statuses']) : null)
            . field('Source', $rdap['source'] ?? null)
        );
    }

    // --- SSL / TLS ---
    if ($tls !== []) {
        $days = $tls['days_to_expiry'] ?? null;
        $protocols = [];
        foreach (($tls['protocols'] ?? []) as $name => $enabled) {
            if ($enabled) {
                $protocols[] = strtoupper(str_replace(['tls', '_'], ['TLS ', '.'], $name));
            }
        }
        $legacy = ($tls['protocols']['tls1_0'] ?? false) || ($tls['protocols']['tls1_1'] ?? false);
        echo section('SSL / TLS',
            field('Issuer', $tls['issuer'] ?? null)
            . field('Subject (CN)', $tls['subject_cn'] ?? null)
            . field_raw('Expires', e($tls['not_after'] ?? '—')
                . ($days !== null ? ' <span class="' . ($days < 30 ? 'val-warn' : 'val-muted') . '">(' . e($days) . ' d)</span>' : ''))
            . field_raw('SAN', fmt_list($tls['san'] ?? []))
            . field('Chain valid', $tls['chain_valid'] ?? null, ($tls['chain_valid'] ?? true) ? null : 'error')
            . field('Hostname covered', $tls['hostname_covered'] ?? null, ($tls['hostname_covered'] ?? true) ? null : 'error')
            . field('Self-signed', $tls['self_signed'] ?? null, ($tls['self_signed'] ?? false) ? 'error' : null)
            . field_raw('Protocols', $protocols === [] ? '—'
                : '<span class="' . ($legacy ? 'val-warn' : '') . '">' . e(implode(', ', $protocols)) . '</span>')
        );
    }

    // --- Server / Hosting ---
    echo section('Server & hosting',
        field('Web server', $p['web_server'] ?? null)
        . field('IP address', $dns['a'][0] ?? null)
        . field('Document root', $p['document_root'] ?? null)
        . field('Max execution time', ($p['php']['max_execution_time'] ?? null) !== null ? $p['php']['max_execution_time'] . ' s' : null)
        . field('Post max size', $p['php']['post_max_size'] ?? null)
        . field('Upload max filesize', $p['php']['upload_max_filesize'] ?? null)
        . field('Max input vars', $p['php']['max_input_vars'] ?? null)
    );

    // --- PHP ---
    echo section('PHP',
        field_raw('Version', e($p['php']['version'] ?? '—') . eol_annotation($eolPhp, $t))
        . field('Memory limit', $p['php']['memory_limit'] ?? null)
        . field_raw('Extensions', is_array($p['php']['extensions'] ?? null)
            ? count($p['php']['extensions']) . ' — ' . fmt_list($p['php']['extensions'], 10) : '—')
        . field_raw('Disabled functions', fmt_list($p['php']['disable_functions'] ?? []))
    );

    // --- Database ---
    echo section('Database',
        field('Type', $p['database_type'] ?? null)
        . field_raw('Version', e($p['database_version'] ?? '—') . eol_annotation($eolDb, $t))
        . field('Prefix', $p['db_table_prefix'] ?? null, ($p['db_table_prefix'] ?? '') === 'wp_' ? 'warn' : null)
        . field_raw('Size', fmt_bytes($p['database']['total_bytes'] ?? null))
        . field_raw('Transients', e($p['database']['transients']['total'] ?? '?')
            . ' (' . e($p['database']['transients']['expired'] ?? '?') . ' expired)')
    );

    // --- WordPress ---
    echo section('WordPress',
        field_raw('Version', e($p['wp_version'] ?? '—') . eol_annotation($eolWp, $t))
        . field('Core update', $p['core_update']['available_version'] ?? ($p['core_update']['status'] ?? null),
            !empty($p['core_update']['available_version']) ? 'warn' : null)
        . field('Active theme', $p['active_theme'] ?? null)
        . field('Multisite', $p['is_multisite'] ?? null)
        . field('Language', $p['site_locale'] ?? null)
        . field('Timezone', $p['timezone_string'] ?? null)
        . field('Admin email', $p['website_administrator_email'] ?? null)
        . field('Permalinks', $p['permalink_structure'] ?? null)
    );

    // --- Network / HTTP ---
    if ($http !== []) {
        $sec = $http['security_headers'] ?? [];
        $present = count(array_filter($sec, static fn ($v) => $v !== null));
        echo section('Network & HTTP',
            field('HTTP code', $http['status_code'] ?? null)
            . field('HTTP version', isset($http['http_version']) ? 'HTTP/' . $http['http_version'] : null)
            . field('Compression', $http['content_encoding'] ?? 'none', ($http['content_encoding'] ?? null) === null ? 'warn' : null)
            . field_raw('TTFB', ($http['ttfb_ms'] ?? null) !== null
                ? '<span class="' . (($http['ttfb_ms'] > 600) ? 'val-warn' : '') . '">' . e($http['ttfb_ms']) . ' ms</span>' : '—')
            . field('HTTPS forced', $http['redirects']['forces_https'] ?? null, ($http['redirects']['forces_https'] ?? true) ? null : 'warn')
            . field('Redirects', $http['redirects']['hops'] ?? null)
            . field('CDN', $http['cdn'] ?? null)
            . field_raw('Security headers', e($present) . ' / ' . e(count($sec)) . ' present')
        );
    }

    // --- DNS ---
    if ($dns !== []) {
        echo section('DNS',
            field_raw('Nameservers', fmt_list($dns['nameservers'] ?? []))
            . field_raw('A', fmt_list($dns['a'] ?? []))
            . field_raw('AAAA', fmt_list($dns['aaaa'] ?? [], 6))
            . field_raw('MX', fmt_list(array_map(static fn ($m) => $m['host'] ?? '', $dns['mx'] ?? [])))
            . field('SPF', ($dns['spf']['present'] ?? false) ? 'present' : 'absent', ($dns['spf']['present'] ?? false) ? null : 'warn')
            . field('DMARC', ($dns['dmarc']['present'] ?? false) ? ('p=' . ($dns['dmarc']['policy'] ?? 'none')) : 'absent',
                ($dns['dmarc']['present'] ?? false) ? null : 'warn')
            . field('CAA', is_array($dns['caa'] ?? null) ? count($dns['caa']) . ' record(s)' : null)
        );
    }

    // --- robots / sitemap ---
    $robots = $http['robots'] ?? null;
    if (is_array($robots)) {
        echo section('robots.txt / sitemap',
            field('robots.txt', ($robots['present'] ?? false) ? 'present' : 'absent', ($robots['present'] ?? false) ? null : 'warn')
            . field('Blocks whole site', $robots['disallow_all'] ?? false, ($robots['disallow_all'] ?? false) ? 'error' : null)
            . field_raw('Sitemaps', is_array($robots['sitemaps'] ?? null) && $robots['sitemaps'] !== []
                ? e(count($robots['sitemaps'])) . ' declared' . (($robots['sitemap_reachable'] ?? null) === false ? ' <span class="val-warn">(unreachable)</span>' : '')
                : '<span class="val-warn">none</span>')
        );
    }

    // --- Configuration (constants) ---
    if (is_array($p['constants'] ?? null) && $p['constants'] !== []) {
        $rows = '';
        foreach ($p['constants'] as $name => $value) {
            $status = (in_array($name, ['WP_DEBUG', 'WP_DEBUG_DISPLAY'], true) && $value === true) ? 'error' : null;
            $rows .= field($name, $value, $status);
        }
        echo section('Configuration', $rows);
    }

    // --- Users ---
    $admins = $p['administrators'] ?? [];
    echo section('Users',
        field('Total', $p['users_count'] ?? null)
        . field('Administrators', is_array($admins) ? count($admins) : null)
        . field_raw('Admin accounts', fmt_list(array_map(static fn ($a) => $a['login'] ?? '?', is_array($admins) ? $admins : [])))
    );

    // --- Content ---
    echo section('Content',
        field('Posts', $p['posts_count'] ?? null)
        . field('Pages', $p['page_count'] ?? null)
        . field('Media', $p['media_count'] ?? null)
        . field('Comments', $p['comments_count'] ?? null)
    );

    // --- Cron / Autoload / Filesystem / Object cache ---
    if (is_array($p['cron'] ?? null)) {
        echo section('Cron',
            field('WP-Cron disabled', $p['cron']['disabled'] ?? null)
            . field('Scheduled events', $p['cron']['scheduled_events'] ?? null)
            . field('Overdue', $p['cron']['overdue_events'] ?? null, ($p['cron']['overdue_events'] ?? 0) > 0 ? 'warn' : null)
            . field('Next (GMT)', $p['cron']['next_event_gmt'] ?? null)
        );
    }
    if (is_array($p['autoload'] ?? null)) {
        echo section('Autoload',
            field_raw('Total weight', fmt_bytes($p['autoload']['total_bytes'] ?? null))
            . field('Option count', $p['autoload']['count'] ?? null)
        );
    }
    if (is_array($p['filesystem'] ?? null)) {
        $fs = $p['filesystem'];
        echo section('Filesystem',
            field_raw('Free disk', fmt_bytes($fs['disk_free_bytes'] ?? null) . ' / ' . fmt_bytes($fs['disk_total_bytes'] ?? null))
            . field('Core writable', $fs['core_writable'] ?? null, ($fs['core_writable'] ?? false) ? 'warn' : null)
            . field('Uploads writable', $fs['uploads_writable'] ?? null)
        );
    }
    if (is_array($p['object_cache'] ?? null)) {
        echo section('Object cache',
            field('External (Redis/Memcached)', $p['object_cache']['external'] ?? null, ($p['object_cache']['external'] ?? false) ? null : 'warn')
            . field('Drop-in', $p['object_cache']['dropin'] ?? null)
            . field('Page cache', $p['object_cache']['page_cache'] ?? null)
        );
    }
    ?>
</div>

<?php // --- Performance (PageSpeed), read straight from the probe ---
$ps = $probes['pagespeed']['data'] ?? [];
if ($ps !== []):
    $categories = array_keys(($ps['mobile'] ?? reset($ps))['scores'] ?? []);
?>
    <h2>Performance (PageSpeed)</h2>
    <table>
        <thead>
        <tr>
            <th>Strategy</th>
            <?php foreach ($categories as $category): ?>
                <th><?= e(ucfirst(str_replace('-', ' ', $category))) ?></th>
            <?php endforeach; ?>
            <th>LCP</th><th>CLS</th><th>Field data</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($ps as $strategy => $result): if (!is_array($result)) { continue; } ?>
            <tr>
                <td><strong><?= e($strategy) ?></strong></td>
                <?php foreach ($categories as $category): $score = $result['scores'][$category] ?? null; ?>
                    <td><?= $score === null ? '—' : badge_score((int) $score) ?></td>
                <?php endforeach; ?>
                <td><?= isset($result['lab']['lcp']['value']) ? e(round((float) $result['lab']['lcp']['value'])) . ' ms' : '—' ?></td>
                <td><?= e($result['lab']['cls']['display'] ?? '—') ?></td>
                <td><?= e($result['field']['overall_category'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php // --- Plugins ---
$plugins = is_array($p['plugins'] ?? null) ? $p['plugins'] : [];
if ($plugins !== []):
    $active     = count(array_filter($plugins, static fn ($pl) => !empty($pl['active'])));
    $withUpdate = count(array_filter($plugins, static fn ($pl) => !empty($pl['new_version'])));
?>
    <h2>Plugins <span class="muted">— <?= count($plugins) ?> installed, <?= $active ?> active, <?= $withUpdate ?> with update</span></h2>
    <table>
        <thead><tr><th>Name</th><th>Version</th><th>Update</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($plugins as $pl): ?>
            <tr>
                <td><?= e($pl['name'] ?? $pl['slug'] ?? '?') ?></td>
                <td class="mono"><?= e($pl['version'] ?? '?') ?></td>
                <td><?= !empty($pl['new_version']) ? '<span class="val-warn mono">' . e($pl['new_version']) . '</span>' : '—' ?></td>
                <td><?= !empty($pl['active'])
                    ? '<span class="badge badge-ok">Active</span>'
                    : '<span class="badge badge-muted">Inactive</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php // --- Themes ---
$themes = is_array($p['themes'] ?? null) ? $p['themes'] : [];
if ($themes !== []): ?>
    <h2>Themes <span class="muted">— <?= count($themes) ?> installed</span></h2>
    <table>
        <thead><tr><th>Name</th><th>Version</th><th>Update</th><th>Template</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($themes as $th): ?>
            <tr>
                <td><?= e($th['name'] ?? $th['slug'] ?? '?') ?></td>
                <td class="mono"><?= e($th['version'] ?? '?') ?></td>
                <td><?= !empty($th['new_version']) ? '<span class="val-warn mono">' . e($th['new_version']) . '</span>' : '—' ?></td>
                <td class="mono"><?= e($th['template'] ?? '') ?></td>
                <td><?= !empty($th['active'])
                    ? '<span class="badge badge-ok">Active</span>'
                    : '<span class="badge badge-muted">Inactive</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php // --- Findings, grouped by category, rendered in the page language ---
if ($findings !== null):
    $counts = $findings['counts'];
    // Group failing findings by category.
    $byCategory = [];
    foreach ($findings['findings'] as $f) {
        if ($f['status'] === 'fail') {
            $byCategory[$f['category']][] = $f;
        }
    }
    $usedCategories = array_keys($byCategory);
    sort($usedCategories);
?>
    <h2>
        <?= e($t->ui('findings')) ?>
        <span class="muted">— <?= e($counts['fail']) ?> failing of <?= e($counts['total']) ?>
            (<?= e($counts['pass']) ?> <?= e($t->ui('passed')) ?>,
             <?= e($counts['na']) ?> <?= e($t->ui('not_applicable')) ?>,
             <?= e($counts['unknown']) ?> <?= e($t->ui('unknown')) ?>)</span>
    </h2>

    <p class="severity-tally">
        <?php foreach (['C', 'E', 'M', 'I'] as $sevKey): ?>
            <span class="badge <?= $counts['by_severity'][$sevKey] > 0 ? 'badge-error' : 'badge-muted' ?>">
                <?= e($t->severity($sevKey)) ?>: <?= e($counts['by_severity'][$sevKey]) ?>
            </span>
        <?php endforeach; ?>
        <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/findings">findings.json</a>
    </p>

    <?php foreach ($usedCategories as $category): ?>
        <h3 class="finding-cat"><?= e($t->category($category)) ?></h3>
        <table>
            <thead><tr><th>Id</th><th><?= e($t->ui('severity')) ?></th><th><?= e($t->ui('rule')) ?></th><th><?= e($t->ui('observation')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($byCategory[$category] as $finding): ?>
                <tr>
                    <td class="mono"><?= e($finding['id']) ?></td>
                    <td><?= badge_severity($finding['severity'], $t->severity($finding['severity'])) ?></td>
                    <td><?= e($t->title($finding['id'])) ?></td>
                    <td><?= e($t->message($finding) ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

    <details>
        <summary><?= e($t->ui('compliant_hidden')) ?></summary>
        <table>
            <tbody>
            <?php foreach ($findings['findings'] as $finding): ?>
                <?php if ($finding['status'] === 'fail') { continue; } ?>
                <tr>
                    <td class="mono"><?= e($finding['id']) ?></td>
                    <td><?= badge($finding['status'] === 'pass' ? 'ok' : ($finding['status'] === 'na' ? 'n/a' : 'unknown')) ?></td>
                    <td><?= e($t->title($finding['id'])) ?></td>
                    <td class="muted"><?= e($t->message($finding) ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>

    <details class="legend">
        <summary><?= e($t->ui('legend')) ?></summary>
        <table class="kv"><tbody>
        <?php foreach (\SatelliteWP\Xtractor\Rules\Category::ALL as $code): ?>
            <?= field_raw($code, e($t->category($code))) ?>
        <?php endforeach; ?>
        </tbody></table>
    </details>
<?php endif; ?>

<h2>Probes</h2>
<?php if ($probes === []): ?>
    <p class="empty">No probe has run yet (pipeline pending).</p>
<?php else: ?>
    <div class="cards">
        <?php foreach ($probes as $name => $envelope): ?>
            <div class="card">
                <h3>
                    <?= e($name) ?> <?= badge($envelope['status'] ?? null) ?>
                    <a class="raw-link mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/<?= e($name) ?>">raw</a>
                </h3>
                <p class="muted">
                    <?= e($envelope['ran_at'] ?? '?') ?> · <?= e($envelope['duration_ms'] ?? '?') ?> ms
                    · target <span class="mono"><?= e($envelope['target'] ?? '?') ?></span>
                    · v<?= e($envelope['probe_version'] ?? '?') ?>
                </p>
                <?php foreach ((array) ($envelope['errors'] ?? []) as $error): ?>
                    <p class="error-line"><?= e($error) ?></p>
                <?php endforeach; ?>
                <?= json_details('Data', $envelope['data'] ?? []) ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2>Raw data</h2>
<p>
    <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/payload">payload.json</a> ·
    <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/meta">meta.json</a> ·
    <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/findings">findings.json</a>
</p>
<?= json_details('Full payload (' . count($payload) . ' keys)', $payload) ?>
