<nav class="breadcrumb">
    <a href="/">Sites</a> ›
    <a href="/site/<?= e($siteId) ?>"><?= e($site['name'] ?? $siteId) ?></a> ›
    <span class="mono"><?= e($extractionId) ?></span>
</nav>

<h1>Extraction <span class="mono"><?= e($extractionId) ?></span></h1>
<p class="muted">
    Reçue <?= e($meta['received_at'] ?? '?') ?>
    · signature <?= !empty($meta['signature_valid']) ? 'valide' : 'absente/non vérifiée' ?>
    · schéma <?= e($meta['schema_version'] ?? '?') ?>
    · <?= fmt_bytes($meta['body_bytes'] ?? null) ?>
    · statut <?= badge($row['status'] ?? null) ?>
</p>

<?php
// Data sources for the section cards below.
$p    = $payload;
$dns  = $probes['dns']['data'] ?? [];
$tls  = $probes['tls']['data'] ?? [];
$rdap = $probes['rdap']['data'] ?? [];
$http = $probes['http']['data'] ?? [];

// Inline EOL annotations from the reference cache (endoflife.date).
$eolPhp = $eol->eolStatus('php', (string) ($p['php']['version'] ?? ''));
$eolWp  = $eol->eolStatus('wordpress', (string) ($p['wp_version'] ?? ''));
$dbType = str_contains(strtolower((string) ($p['database_type'] ?? '')), 'maria') ? 'mariadb'
    : (str_contains(strtolower((string) ($p['database_type'] ?? '')), 'mysql') ? 'mysql' : null);
$eolDb  = $dbType !== null ? $eol->eolStatus($dbType, (string) ($p['database_version'] ?? '')) : null;

$rawLink = static fn (string $f): string =>
    '/site/' . e($siteId) . '/extraction/' . e($extractionId) . '/raw/' . $f;
?>

<h2>Informations</h2>
<div class="cards info-cards">

    <?php
    // --- Domaine (RDAP) ---
    if ($rdap !== []) {
        $days = $rdap['days_to_expiry'] ?? null;
        echo section('Domaine',
            field('Cible', $probes['rdap']['target'] ?? null)
            . field('Registraire', $rdap['registrar'] ?? null)
            . field('Création', $rdap['created_at'] ?? null)
            . field_raw('Expiration', e($rdap['expires_at'] ?? '—')
                . ($days !== null ? ' <span class="' . ($days < 30 ? 'val-warn' : 'val-muted') . '">(' . e($days) . ' j)</span>' : ''))
            . field('Statuts', is_array($rdap['statuses'] ?? null) ? implode(', ', $rdap['statuses']) : null)
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
            field('Émetteur', $tls['issuer'] ?? null)
            . field('Sujet (CN)', $tls['subject_cn'] ?? null)
            . field_raw('Expiration', e($tls['not_after'] ?? '—')
                . ($days !== null ? ' <span class="' . ($days < 30 ? 'val-warn' : 'val-muted') . '">(' . e($days) . ' j)</span>' : ''))
            . field_raw('SAN', fmt_list($tls['san'] ?? []))
            . field('Chaîne valide', $tls['chain_valid'] ?? null, ($tls['chain_valid'] ?? true) ? null : 'error')
            . field('Hôte couvert', $tls['hostname_covered'] ?? null, ($tls['hostname_covered'] ?? true) ? null : 'error')
            . field('Auto-signé', $tls['self_signed'] ?? null, ($tls['self_signed'] ?? false) ? 'error' : null)
            . field_raw('Protocoles', $protocols === [] ? '—'
                : '<span class="' . ($legacy ? 'val-warn' : '') . '">' . e(implode(', ', $protocols)) . '</span>')
        );
    }

    // --- Serveur / Hébergement ---
    echo section('Serveur & hébergement',
        field('Serveur web', $p['web_server'] ?? null)
        . field('Adresse IP', $dns['a'][0] ?? null)
        . field('Répertoire racine', $p['document_root'] ?? null)
        . field('Max execution time', ($p['php']['max_execution_time'] ?? null) !== null ? $p['php']['max_execution_time'] . ' s' : null)
        . field('Post max size', $p['php']['post_max_size'] ?? null)
        . field('Upload max filesize', $p['php']['upload_max_filesize'] ?? null)
        . field('Max input vars', $p['php']['max_input_vars'] ?? null)
    );

    // --- PHP ---
    echo section('PHP',
        field_raw('Version', e($p['php']['version'] ?? '—') . eol_annotation($eolPhp))
        . field('Memory limit', $p['php']['memory_limit'] ?? null)
        . field_raw('Extensions', is_array($p['php']['extensions'] ?? null)
            ? count($p['php']['extensions']) . ' — ' . fmt_list($p['php']['extensions'], 10) : '—')
        . field_raw('Fonctions désactivées', fmt_list($p['php']['disable_functions'] ?? []))
    );

    // --- Base de données ---
    echo section('Base de données',
        field('Type', $p['database_type'] ?? null)
        . field_raw('Version', e($p['database_version'] ?? '—') . eol_annotation($eolDb))
        . field('Préfixe', $p['db_table_prefix'] ?? null, ($p['db_table_prefix'] ?? '') === 'wp_' ? 'warn' : null)
        . field_raw('Taille', fmt_bytes($p['database']['total_bytes'] ?? null))
        . field_raw('Transients', e($p['database']['transients']['total'] ?? '?')
            . ' dont ' . e($p['database']['transients']['expired'] ?? '?') . ' expirés')
    );

    // --- WordPress ---
    $coreStatus = $p['core_update']['status'] ?? null;
    echo section('WordPress',
        field_raw('Version', e($p['wp_version'] ?? '—') . eol_annotation($eolWp))
        . field('Mise à jour cœur', $p['core_update']['available_version'] ?? $coreStatus,
            !empty($p['core_update']['available_version']) ? 'warn' : null)
        . field('Thème actif', $p['active_theme'] ?? null)
        . field('Multisite', $p['is_multisite'] ?? null)
        . field('Langue', $p['site_locale'] ?? null)
        . field('Fuseau horaire', $p['timezone_string'] ?? null)
        . field('Email admin', $p['website_administrator_email'] ?? null)
        . field('Permaliens', $p['permalink_structure'] ?? null)
    );

    // --- Réseau / HTTP ---
    if ($http !== []) {
        $sec = $http['security_headers'] ?? [];
        $present = count(array_filter($sec, static fn ($v) => $v !== null));
        echo section('Réseau & HTTP',
            field('Code HTTP', $http['status_code'] ?? null)
            . field('Version HTTP', isset($http['http_version']) ? 'HTTP/' . $http['http_version'] : null)
            . field('Compression', $http['content_encoding'] ?? 'aucune', ($http['content_encoding'] ?? null) === null ? 'warn' : null)
            . field_raw('TTFB', ($http['ttfb_ms'] ?? null) !== null
                ? '<span class="' . (($http['ttfb_ms'] > 600) ? 'val-warn' : '') . '">' . e($http['ttfb_ms']) . ' ms</span>' : '—')
            . field('HTTPS forcé', $http['redirects']['forces_https'] ?? null, ($http['redirects']['forces_https'] ?? true) ? null : 'warn')
            . field('Redirections', $http['redirects']['hops'] ?? null)
            . field('CDN', $http['cdn'] ?? null)
            . field_raw('En-têtes sécurité', e($present) . ' / ' . e(count($sec)) . ' présents')
        );
    }

    // --- DNS ---
    if ($dns !== []) {
        echo section('DNS',
            field_raw('Serveurs de noms', fmt_list($dns['nameservers'] ?? []))
            . field_raw('A', fmt_list($dns['a'] ?? []))
            . field_raw('AAAA', fmt_list($dns['aaaa'] ?? [], 6))
            . field_raw('MX', fmt_list(array_map(static fn ($m) => $m['host'] ?? '', $dns['mx'] ?? [])))
            . field('SPF', ($dns['spf']['present'] ?? false) ? 'présent' : 'absent', ($dns['spf']['present'] ?? false) ? null : 'warn')
            . field('DMARC', ($dns['dmarc']['present'] ?? false) ? ('p=' . ($dns['dmarc']['policy'] ?? 'none')) : 'absent',
                ($dns['dmarc']['present'] ?? false) ? null : 'warn')
            . field('CAA', is_array($dns['caa'] ?? null) ? count($dns['caa']) . ' enregistrement(s)' : null)
        );
    }

    // --- robots / sitemap ---
    $robots = $http['robots'] ?? null;
    if (is_array($robots)) {
        echo section('robots.txt / sitemap',
            field('robots.txt', ($robots['present'] ?? false) ? 'présent' : 'absent', ($robots['present'] ?? false) ? null : 'warn')
            . field('Bloque tout le site', $robots['disallow_all'] ?? false, ($robots['disallow_all'] ?? false) ? 'error' : null)
            . field_raw('Sitemaps', is_array($robots['sitemaps'] ?? null) && $robots['sitemaps'] !== []
                ? e(count($robots['sitemaps'])) . ' déclaré(s)' . (($robots['sitemap_reachable'] ?? null) === false ? ' <span class="val-warn">(injoignable)</span>' : '')
                : '<span class="val-warn">aucun</span>')
        );
    }

    // --- Configuration (constantes) ---
    if (is_array($p['constants'] ?? null) && $p['constants'] !== []) {
        $rows = '';
        foreach ($p['constants'] as $name => $value) {
            $status = null;
            if (in_array($name, ['WP_DEBUG', 'WP_DEBUG_DISPLAY'], true) && $value === true) {
                $status = 'error';
            }
            $rows .= field($name, $value, $status);
        }
        echo section('Configuration', $rows);
    }

    // --- Utilisateurs ---
    $admins = $p['administrators'] ?? [];
    echo section('Utilisateurs',
        field('Total', $p['users_count'] ?? null)
        . field('Administrateurs', is_array($admins) ? count($admins) : null)
        . field_raw('Comptes admin', fmt_list(array_map(static fn ($a) => $a['login'] ?? '?', is_array($admins) ? $admins : [])))
    );

    // --- Contenu ---
    echo section('Contenu',
        field('Articles', $p['posts_count'] ?? null)
        . field('Pages', $p['page_count'] ?? null)
        . field('Médias', $p['media_count'] ?? null)
        . field('Commentaires', $p['comments_count'] ?? null)
    );

    // --- Cron / Autoload / Filesystem / Cache objet ---
    if (is_array($p['cron'] ?? null)) {
        echo section('Cron',
            field('WP-Cron désactivé', $p['cron']['disabled'] ?? null)
            . field('Événements planifiés', $p['cron']['scheduled_events'] ?? null)
            . field('En retard', $p['cron']['overdue_events'] ?? null, ($p['cron']['overdue_events'] ?? 0) > 0 ? 'warn' : null)
            . field('Prochain (GMT)', $p['cron']['next_event_gmt'] ?? null)
        );
    }
    if (is_array($p['autoload'] ?? null)) {
        echo section('Autoload',
            field_raw('Poids total', fmt_bytes($p['autoload']['total_bytes'] ?? null))
            . field('Nombre d\'options', $p['autoload']['count'] ?? null)
        );
    }
    if (is_array($p['filesystem'] ?? null)) {
        $fs = $p['filesystem'];
        echo section('Système de fichiers',
            field_raw('Disque libre', fmt_bytes($fs['disk_free_bytes'] ?? null) . ' / ' . fmt_bytes($fs['disk_total_bytes'] ?? null))
            . field('Cœur inscriptible', $fs['core_writable'] ?? null, ($fs['core_writable'] ?? false) ? 'warn' : null)
            . field('Uploads inscriptible', $fs['uploads_writable'] ?? null)
        );
    }
    if (is_array($p['object_cache'] ?? null)) {
        echo section('Cache objet',
            field('Externe (Redis/Memcached)', $p['object_cache']['external'] ?? null, ($p['object_cache']['external'] ?? false) ? null : 'warn')
            . field('Drop-in', $p['object_cache']['dropin'] ?? null)
            . field('Page cache', $p['object_cache']['page_cache'] ?? null)
        );
    }
    ?>
</div>

<?php // --- Extensions (plugins) ---
$plugins = is_array($p['plugins'] ?? null) ? $p['plugins'] : [];
if ($plugins !== []):
    $withUpdate = array_filter($plugins, static fn ($pl) => !empty($pl['new_version']));
?>
    <h2>Extensions <span class="muted">— <?= count($plugins) ?> installées,
        <?= count(array_filter($plugins, static fn ($pl) => !empty($pl['active']))) ?> actives,
        <?= count($withUpdate) ?> à mettre à jour</span></h2>
    <table>
        <thead><tr><th>Nom</th><th>Version</th><th>Mise à jour</th><th>Active</th></tr></thead>
        <tbody>
        <?php foreach ($plugins as $pl): ?>
            <tr>
                <td><?= e($pl['name'] ?? $pl['slug'] ?? '?') ?></td>
                <td class="mono"><?= e($pl['version'] ?? '?') ?></td>
                <td><?= !empty($pl['new_version']) ? '<span class="val-warn mono">' . e($pl['new_version']) . '</span>' : '—' ?></td>
                <td><?= !empty($pl['active']) ? badge('ok') : '<span class="val-muted">non</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php // --- Thèmes ---
$themes = is_array($p['themes'] ?? null) ? $p['themes'] : [];
if ($themes !== []): ?>
    <h2>Thèmes <span class="muted">— <?= count($themes) ?> installés</span></h2>
    <table>
        <thead><tr><th>Nom</th><th>Version</th><th>Mise à jour</th><th>Modèle</th><th>Actif</th></tr></thead>
        <tbody>
        <?php foreach ($themes as $th): ?>
            <tr>
                <td><?= e($th['name'] ?? $th['slug'] ?? '?') ?></td>
                <td class="mono"><?= e($th['version'] ?? '?') ?></td>
                <td><?= !empty($th['new_version']) ? '<span class="val-warn mono">' . e($th['new_version']) . '</span>' : '—' ?></td>
                <td class="mono"><?= e($th['template'] ?? '') ?></td>
                <td><?= !empty($th['active']) ? badge('ok') : '<span class="val-muted">non</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($summary !== null): ?>
    <?php if (!empty($summary['pagespeed_scores'])): ?>
        <h2>Performance (PageSpeed)</h2>
        <table>
            <thead>
            <tr>
                <th>Stratégie</th>
                <?php foreach (array_keys(reset($summary['pagespeed_scores']) ?: []) as $category): ?>
                    <th><?= e(ucfirst(str_replace('-', ' ', $category))) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($summary['pagespeed_scores'] as $strategy => $scores): ?>
                <tr>
                    <td><strong><?= e($strategy) ?></strong></td>
                    <?php foreach ($scores as $score): ?>
                        <td><?= $score === null ? '—' : badge_score((int) $score) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted">
            LCP <?= isset($summary['lcp_ms']) ? e(round((float) $summary['lcp_ms'])) . ' ms' : '—' ?>
            · CLS <?= e($summary['cls'] ?? '—') ?>
            · données terrain (CrUX) : <?= e($summary['field_data'] ?? 'indisponibles') ?>
            <?= isset($summary['pagespeed_strategy']) ? '· chiffres de tête : ' . e($summary['pagespeed_strategy']) : '' ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($findings !== null): ?>
    <?php $counts = $findings['counts']; ?>
    <h2>
        Constats
        <span class="muted">
            — <?= e($counts['fail']) ?> en échec sur <?= e($counts['total']) ?> règles
            (<?= e($counts['pass']) ?> conformes, <?= e($counts['na']) ?> n/a, <?= e($counts['unknown']) ?> indéterminées)
        </span>
    </h2>

    <p class="severity-tally">
        <?php foreach (['C' => 'Critique', 'E' => 'Élevée', 'M' => 'Moyenne', 'I' => 'Info'] as $key => $label): ?>
            <span class="badge <?= $counts['by_severity'][$key] > 0 ? 'badge-error' : 'badge-muted' ?>">
                <?= e($label) ?> : <?= e($counts['by_severity'][$key]) ?>
            </span>
        <?php endforeach; ?>
        <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/findings">findings.json</a>
    </p>

    <table>
        <thead>
        <tr><th>Id</th><th>Catégorie</th><th>Sévérité</th><th>Règle</th><th>Constat</th></tr>
        </thead>
        <tbody>
        <?php foreach ($findings['findings'] as $finding): ?>
            <?php if ($finding['status'] !== 'fail') { continue; } ?>
            <tr>
                <td class="mono"><?= e($finding['id']) ?></td>
                <td><?= e($finding['category']) ?></td>
                <td><?= badge_severity($finding['severity'], $finding['severity_label']) ?></td>
                <td><?= e($finding['title']) ?></td>
                <td>
                    <?= e($finding['message']) ?>
                    <?php if (!empty($finding['detail'])): ?>
                        <div class="muted"><?= e($finding['detail']) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <details>
        <summary>Règles conformes, non applicables et indéterminées</summary>
        <table>
            <tbody>
            <?php foreach ($findings['findings'] as $finding): ?>
                <?php if ($finding['status'] === 'fail') { continue; } ?>
                <tr>
                    <td class="mono"><?= e($finding['id']) ?></td>
                    <td><?= badge($finding['status'] === 'pass' ? 'ok' : ($finding['status'] === 'na' ? 'n/a' : 'indéterminé')) ?></td>
                    <td><?= e($finding['title']) ?></td>
                    <td class="muted"><?= e($finding['detail'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
<?php endif; ?>

<h2>Probes</h2>
<?php if ($probes === []): ?>
    <p class="empty">Aucune probe exécutée pour l’instant (en attente du pipeline).</p>
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
                    · cible <span class="mono"><?= e($envelope['target'] ?? '?') ?></span>
                    · v<?= e($envelope['probe_version'] ?? '?') ?>
                </p>
                <?php foreach ((array) ($envelope['errors'] ?? []) as $error): ?>
                    <p class="error-line"><?= e($error) ?></p>
                <?php endforeach; ?>
                <?= json_details('Données', $envelope['data'] ?? []) ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2>Payload brut</h2>
<p>
    <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/payload">payload.json</a> ·
    <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/meta">meta.json</a> ·
    <a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extractionId) ?>/raw/summary">summary.json</a>
</p>
<?= json_details('Payload complet (' . count($payload) . ' clés)', $payload) ?>
