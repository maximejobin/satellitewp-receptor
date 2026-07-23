<nav class="breadcrumb"><a href="/">Sites</a> › <?= e($site['name'] ?? $siteId) ?></nav>

<h1><?= e($site['name'] ?? $siteId) ?></h1>
<p>
    <a href="<?= e($site['site_url'] ?? '#') ?>" rel="noopener noreferrer"><?= e($site['site_url'] ?? '') ?></a>
    <span class="muted">· premier contact <?= e($site['first_seen'] ?? '?') ?> · dernier <?= e($site['last_seen'] ?? '?') ?></span>
</p>

<h2>Extractions</h2>
<?php if ($extractions === []): ?>
    <p class="empty">Aucune extraction.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Extraction</th><th>Reçue</th><th>WP</th><th>PHP</th><th>Statut</th><th>Probes</th></tr>
        </thead>
        <tbody>
        <?php foreach ($extractions as $extraction): ?>
            <tr>
                <td><a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extraction['id']) ?>"><?= e($extraction['id']) ?></a></td>
                <td><?= e($extraction['received_at']) ?></td>
                <td><?= e($extraction['wp_version']) ?></td>
                <td><?= e($extraction['php_version']) ?></td>
                <td><?= badge($extraction['status']) ?></td>
                <td>
                    <?php foreach ($probeRuns[$extraction['id']] ?? [] as $run): ?>
                        <span class="probe-chip"><?= e($run['probe']) ?> <?= badge($run['status']) ?></span>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($trends !== []): ?>
    <h2>Tendances</h2>
    <table>
        <thead>
        <tr><th>Extraction</th><th>BD</th><th>Autoload</th><th>Extensions à jour ?</th><th>Admins</th></tr>
        </thead>
        <tbody>
        <?php foreach ($trends as $trend): ?>
            <tr>
                <td class="mono"><?= e($trend['id']) ?></td>
                <td><?= fmt_bytes($trend['db_total_bytes']) ?></td>
                <td><?= fmt_bytes($trend['autoload_bytes']) ?></td>
                <td><?= $trend['plugins_with_update'] === null ? '—' : e($trend['plugins_with_update']) . ' mise(s) à jour en attente' ?></td>
                <td><?= e($trend['admins_count'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Événements récents</h2>
<?php if ($events === []): ?>
    <p class="empty">Aucun événement reçu.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Horodatage (GMT)</th><th>Événement</th><th>Acteur</th><th>Détails</th></tr>
        </thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><?= e($event['timestamp_gmt'] ?? $event['received_at'] ?? '') ?></td>
                <td><span class="badge badge-muted"><?= e($event['event'] ?? '?') ?></span></td>
                <td><?= e($event['actor_login'] ?? '') ?></td>
                <td class="muted">
                    <?php
                    $details = array_diff_key(
                        $event,
                        array_flip(['schema_version', 'event', 'actor_user_id', 'actor_login', 'timestamp_gmt', 'received_at'])
                    );
                    echo e(json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
