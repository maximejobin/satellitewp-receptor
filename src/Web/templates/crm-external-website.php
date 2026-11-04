<?php
/**
 * @var array{id: int, url: string, subscription_ids: list<int>, created_by: string, created_at: string} $website
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $subscriptions
 * @var bool $canEdit
 * @var bool $canLink
 * @var string $csrf
 * @var string $notice
 */
use SatelliteWP\Manager\Crm\ClientsRepository;
use SatelliteWP\Manager\Crm\ExternalWebsites;

$publicId = ExternalWebsites::publicId($website['id']);
$page     = '/websites/' . $publicId;

$notices = [
    'external-added'        => ['success', 'External website added.'],
    'external-updated'      => ['success', 'External website updated.'],
    'external-linked'       => ['critical', 'Unlink its subscriptions before deleting this website.'],
    'external-invalid'      => ['critical', 'Enter an absolute http(s) URL.'],
    'external-duplicate'    => ['critical', 'This URL is already a website.'],
    'external-failed'       => ['critical', 'Could not save the external website.'],
    'website-updated'       => ['success', 'Linked website updated.'],
    'website-update-failed' => ['critical', 'Could not update the linked website.'],
];

$clientLabels = array_map(
    static fn (array $c): string => '<a href="/clients/' . (int) $c['id'] . '">' . e(ClientsRepository::clientLabel($c)) . '</a>',
    $clients
);
?>
<h1><?= e(site_display($website['url'])) ?> <span class="badge badge-muted">External</span></h1>

<?php if (isset($notices[$notice])): [$level, $text] = $notices[$notice]; ?>
    <?= notice($level, e($text)) ?>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="obs-toolbar">
    <button type="button" class="btn btn-secondary" data-open-dialog="external-edit">Edit</button>
    <form method="post" action="<?= e($page) ?>/delete" onsubmit="return confirm('Delete this external website?')">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn-danger">Delete</button>
    </form>
</div>
<?= external_website_dialog('external-edit', $page . '/update', 'Edit external website', 'Save', $csrf, $website['url']) ?>
<?php endif; ?>

<section class="card info-card kv-tight">
    <table class="kv"><tbody><?= implode('', [
        field_raw('URL', link_or_text($website['url'], site_display($website['url']), true)),
        field('Site type', 'Non-WordPress'),
        field_raw('Client', $clientLabels !== [] ? implode(', ', $clientLabels) : ''),
        field('Added', trim(substr($website['created_at'], 0, 10) . ' ' . $website['created_by'])),
    ]) ?></tbody></table>
</section>

<h2>Subscriptions</h2>
<?php if ($subscriptions === []): ?>
    <p class="empty">No subscription linked to this website.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Product</th><th>Category</th><th>Client</th><th>Status</th><th>Next renewal</th><th>Linked website</th></tr>
        </thead>
        <tbody>
        <?php foreach ($subscriptions as $s): ?>
            <tr>
                <td><?= e($s['product_name']) ?></td>
                <td class="muted"><?= e($s['product_category'] ?? '') ?></td>
                <td><a href="/clients/<?= (int) $s['client_id'] ?>"><?= e(ClientsRepository::clientLabel($s + ['email' => $s['client_email'] ?? null])) ?></a></td>
                <td><?= status_dot((string) $s['subscription_status']) ?></td>
                <td><?= e($s['next_renewal_date'] !== null ? substr((string) $s['next_renewal_date'], 0, 10) : '') ?></td>
                <td><?= subscription_website_form($s + ['website_id' => $publicId, 'website_url' => $website['url']], $page, $canLink) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($canLink): ?><?= subscription_link_dialog($csrf) ?><?php endif; ?>
<?php endif; ?>
