<?php /** @var \SatelliteWP\Xtractor\Rules\Translator $t */ ?>
<nav class="breadcrumb"><a href="/"><?= e($t->ui('sites')) ?></a> › <?= e($site['name'] ?? $siteId) ?></nav>

<h1><?= e($site['name'] ?? $siteId) ?></h1>
<p>
    <a href="<?= e($site['site_url'] ?? '#') ?>" rel="noopener noreferrer"><?= e($site['site_url'] ?? '') ?></a>
    <span class="muted">· first seen <?= e($site['first_seen'] ?? '?') ?> · last <?= e($site['last_seen'] ?? '?') ?></span>
</p>

<h2>Extractions</h2>
<?php if ($extractions === []): ?>
    <p class="empty">No extraction.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Extraction</th><th>Received</th><th>WP</th><th>PHP</th><th><?= e($t->ui('status')) ?></th><th>Probes</th></tr>
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
    <h2>Trends</h2>
    <table>
        <thead>
        <tr><th>Extraction</th><th>Database</th><th>Autoload</th><th>Plugins with update</th><th>Admins</th></tr>
        </thead>
        <tbody>
        <?php foreach ($trends as $trend): ?>
            <tr>
                <td class="mono"><?= e($trend['id']) ?></td>
                <td><?= fmt_bytes($trend['db_total_bytes']) ?></td>
                <td><?= fmt_bytes($trend['autoload_bytes']) ?></td>
                <td><?= $trend['plugins_with_update'] === null ? '—' : e($trend['plugins_with_update']) ?></td>
                <td><?= e($trend['admins_count'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Recent events</h2>
<?php if ($events === []): ?>
    <p class="empty">No event received.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Timestamp (GMT)</th><th>Event</th><th>Actor</th><th>Details</th></tr>
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
