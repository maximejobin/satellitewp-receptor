<?php
/**
 * @var string $reason 'unconfigured' | 'error'
 * @var string|null $ref
 */
?>
<h1><?= e($title) ?></h1>

<?php if ($reason === 'unconfigured'): ?>
    <p class="empty">Not connected yet — this reads from a separate external database
        (clients, subscriptions, products, websites, items) that has not been configured.
        Set <span class="mono">crm_db.host</span>/<span class="mono">crm_db.database</span>
        (and credentials) in <span class="mono">config/config.local.php</span> to enable this section.</p>
<?php else: ?>
    <p class="empty">Could not reach the external database right now.
        <?php if ($ref !== null): ?>Logged as <span class="mono">ref <?= e($ref) ?></span>.<?php endif; ?>
        Try again shortly; if this persists, check the connection settings.</p>
<?php endif; ?>
