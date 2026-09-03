<?php
/**
 * @var array<string, mixed> $website
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $subscriptions
 * @var array{plugin: list<array<string,mixed>>, theme: list<array<string,mixed>>, other: list<array<string,mixed>>} $items
 * @var string $csrf
 * @var string $notice
 * @var array<string, string|null> $links external_links config (see config/config.php)
 */
$notices = [
    'website-updated'       => ['badge-ok', 'Linked website updated.'],
    'website-update-failed' => ['badge-error', 'Could not update the linked website.'],
];
?>
<h1><?= e(site_display($website['url'])) ?></h1>

<?php if (isset($notices[$notice])): [$cls, $text] = $notices[$notice]; ?>
    <p><span class="badge <?= $cls ?>"><?= e($text) ?></span></p>
<?php endif; ?>

<?= section('Website', implode('', [
    field('URL', isset($website['url']) ? site_display($website['url']) : null),
    field('Host', $website['host'] ?? null),
    field('Connection status', $website['connection_status'] ?? null),
    field('PHP version', $website['php_version'] ?? null),
    field('MySQL version', $website['mysql_version'] ?? null),
    field('WordPress version', $website['wp_core_version'] ?? null,
        !empty($website['wp_core_is_vulnerable']) ? 'error' : null),
    field('BlogVault site id', $website['blogvault_site_id'] ?? null),
    field('Tags', $website['tags'] !== [] ? implode(', ', $website['tags']) : null),
    field('Last synced', $website['date_sync'] ?? null),
]), external_link_button($links['blogvault_view_website'] ?? null, $website['blogvault_site_id'] ?? null, 'View in BlogVault')) ?>

<?php foreach ($clients as $c): ?>
    <?= section('Client', implode('', [
        field('Company', $c['company'] ?? null),
        field('First name', $c['first_name'] ?? null),
        field('Last name', $c['last_name'] ?? null),
        field_raw('Email', e($c['email'] ?? '') . ' ' . copy_button((string) ($c['email'] ?? ''))),
    ]), '<a href="/clients/' . (int) $c['id'] . '" class="btn" style="margin:0;padding:.25rem .6rem;font-size:.8rem">View client</a>') ?>
<?php endforeach; ?>

<h2>Subscriptions</h2>
<?php if ($subscriptions === []): ?>
    <p class="empty">No subscription linked to this website.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Product</th><th>Category</th><th>Status</th><th>Next renewal</th><th>Linked website</th></tr>
        </thead>
        <tbody>
        <?php foreach ($subscriptions as $s): ?>
            <tr>
                <td><?= e($s['product_name']) ?></td>
                <td class="muted"><?= e($s['product_category'] ?? '—') ?></td>
                <td><?= badge((string) $s['subscription_status']) ?></td>
                <td><?= e($s['next_renewal_date'] !== null ? substr((string) $s['next_renewal_date'], 0, 10) : '—') ?></td>
                <td><?= subscription_website_form(
                    $s + ['website_id' => $website['id'], 'website_url' => $website['url']],
                    $csrf,
                    '/websites/' . (int) $website['id']
                ) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
$itemTable = static function (array $rows): string {
    if ($rows === []) {
        return '<p class="empty">None.</p>';
    }
    $out = '<table><thead><tr><th>Name</th><th>Slug</th><th>Version</th>'
        . '<th>Update available</th><th>Vulnerable</th><th>Active</th></tr></thead><tbody>';
    foreach ($rows as $item) {
        $out .= '<tr class="' . (empty($item['is_active']) ? 'row-inactive' : '') . '">'
            . '<td>' . e($item['name']) . '</td>'
            . '<td class="mono">' . e($item['slug']) . '</td>'
            . '<td class="mono">' . e($item['version']) . '</td>'
            . '<td>' . (!empty($item['is_update_available']) ? e($item['new_version'] ?? '?') : '—') . '</td>'
            . '<td>' . (!empty($item['is_vulnerable']) ? badge('error') . ' (' . (int) ($item['vulnerability_count'] ?? 0) . ')' : '—') . '</td>'
            . '<td>' . (!empty($item['is_active']) ? 'yes' : 'no') . '</td>'
            . '</tr>';
    }

    return $out . '</tbody></table>';
};
?>

<h2>Plugins</h2>
<?= $itemTable($items['plugin']) ?>

<h2>Themes</h2>
<?= $itemTable($items['theme']) ?>

<?php if ($items['other'] !== []): ?>
    <h2>Other items</h2>
    <?= $itemTable($items['other']) ?>
<?php endif; ?>
