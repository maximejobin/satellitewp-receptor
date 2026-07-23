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

<?php if ($summary !== null): ?>
    <h2>Sommaire</h2>
    <div class="cards">
        <div class="card">
            <h3>WordPress</h3>
            <dl>
                <dt>Version</dt><dd><?= e($summary['wp_version'] ?? '—') ?> (<?= e($summary['core_update'] ?? '?') ?>)</dd>
                <dt>Thème actif</dt><dd><?= e($summary['active_theme'] ?? '—') ?></dd>
                <dt>Extensions</dt>
                <dd>
                    <?= e($summary['plugins_active'] ?? '?') ?> actives / <?= e($summary['plugins_total'] ?? '?') ?>,
                    <?= e($summary['plugins_with_update'] ?? '0') ?> à mettre à jour
                </dd>
                <dt>Comptes</dt><dd><?= e($summary['users_count'] ?? '?') ?> dont <?= e($summary['admins_count'] ?? '?') ?> admin(s)</dd>
            </dl>
        </div>
        <div class="card">
            <h3>Serveur</h3>
            <dl>
                <dt>PHP</dt><dd><?= e($summary['php_version'] ?? '—') ?></dd>
                <dt>Serveur web</dt><dd><?= e($summary['web_server'] ?? '—') ?></dd>
                <dt>Base de données</dt>
                <dd><?= e($summary['database_type'] ?? '?') ?> <?= e($summary['database_version'] ?? '') ?> · <?= fmt_bytes($summary['db_total_bytes'] ?? null) ?></dd>
                <dt>Disque libre</dt>
                <dd><?= fmt_bytes($summary['disk_free_bytes'] ?? null) ?> / <?= fmt_bytes($summary['disk_total_bytes'] ?? null) ?></dd>
                <dt>Autoload</dt><dd><?= fmt_bytes($summary['autoload_bytes'] ?? null) ?></dd>
            </dl>
        </div>
        <div class="card">
            <h3>Externe</h3>
            <dl>
                <dt>SSL</dt>
                <dd><?= e($summary['ssl_issuer'] ?? '—') ?><?= isset($summary['ssl_days_to_expiry']) ? ', expire dans ' . e($summary['ssl_days_to_expiry']) . ' j' : '' ?></dd>
                <dt>Domaine</dt>
                <dd><?= e($summary['domain_registrar'] ?? '—') ?><?= isset($summary['domain_days_to_expiry']) ? ', expire dans ' . e($summary['domain_days_to_expiry']) . ' j' : '' ?></dd>
                <dt>HTTP</dt>
                <dd>
                    v<?= e($summary['http_version'] ?? '?') ?>,
                    <?= e($summary['content_encoding'] ?? 'aucune compression') ?>,
                    TTFB <?= e($summary['ttfb_ms'] ?? '?') ?> ms
                </dd>
                <dt>HTTPS forcé</dt>
                <dd><?= isset($summary['forces_https']) ? ($summary['forces_https'] ? 'oui' : 'non') : '—' ?></dd>
            </dl>
        </div>
    </div>

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
