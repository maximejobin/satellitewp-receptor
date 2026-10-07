<?php
/**
 * @var array<string, mixed> $website
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $subscriptions
 * @var array{plugin: list<array<string,mixed>>, theme: list<array<string,mixed>>, other: list<array<string,mixed>>} $items
 * @var string $csrf
 * @var string $notice
 * @var array<string, string|null> $links external_links config (see config/config.php)
 * @var \SatelliteWP\Manager\Reference\EndOfLife $eol
 */
use SatelliteWP\Manager\Crm\ClientsRepository;

$notices = [
    'website-updated'       => ['badge-ok', 'Linked website updated.'],
    'website-update-failed' => ['badge-error', 'Could not update the linked website.'],
];

/** A version value plus an "Outdated"/"Vulnerable" badge, only when there's actually something to flag. */
$versionField = static function (string $label, mixed $version, ?array $eolStatus, bool $vulnerable = false): string {
    if ($version === null || $version === '') {
        return field($label, null);
    }
    $tags = '';
    if ($vulnerable) {
        $tags .= ' <span class="badge badge-error">Vulnerable</span>';
    }
    if ($eolStatus !== null && $eolStatus[0] === true) {
        $tags .= ' <span class="badge badge-error">Outdated</span>';
    }

    return field_raw($label, e($version) . $tags);
};
$eolPhp = $eol->eolStatus('php', (string) ($website['php_version'] ?? ''));
// swp_websites has no engine column; the version string carries it ("10.6.27-MariaDB").
$dbVersionRaw = (string) ($website['mysql_version'] ?? '');
$dbEngine     = str_contains(strtolower($dbVersionRaw), 'mariadb') ? 'mariadb' : 'mysql';
$eolMysql     = $eol->eolStatus($dbEngine, $dbVersionRaw);
$eolWp        = $eol->eolStatus('wordpress', (string) ($website['wp_core_version'] ?? ''));

$clientLabels = array_map(
    static fn (array $c): string => '<a href="/clients/' . (int) $c['id'] . '">' . e(ClientsRepository::clientLabel($c)) . '</a>',
    $clients
);
?>
<h1><?= e(site_display($website['url'])) ?></h1>

<?php if (isset($notices[$notice])): [$cls, $text] = $notices[$notice]; ?>
    <p><span class="badge <?= $cls ?>"><?= e($text) ?></span></p>
<?php endif; ?>

<?php
?>
<section class="card info-card kv-tight">
    <table class="kv"><tbody><?= implode('', [
        field_raw('URL', link_or_text($website['url'] ?? null, site_display($website['url'] ?? ''), true)),
        field('Connection status', $website['connection_status'] ?? null),
        $versionField('PHP version', $website['php_version'] ?? null, $eolPhp),
        $versionField('MySQL version', $website['mysql_version'] ?? null, $eolMysql),
        $versionField('WordPress version', $website['wp_core_version'] ?? null, $eolWp, !empty($website['wp_core_is_vulnerable'])),
        field_raw('BlogVault site id', external_link(
            $links['blogvault_view_website'] ?? null,
            isset($website['blogvault_site_id']) ? substr((string) $website['blogvault_site_id'], 0, 8) : null,
            (string) ($website['blogvault_site_id'] ?? '—')
        )),
        field_raw('Tags', !empty($website['tags']) && is_array($website['tags'])
            ? implode(' ', array_map(static fn (mixed $t): string => '<span class="badge badge-muted">' . e($t) . '</span>', $website['tags']))
            : '—'),
        field_raw('Client', $clientLabels !== [] ? implode(', ', $clientLabels) : '—'),
        field('Last synced', $website['date_sync'] ?? null),
    ]) ?></tbody></table>
</section>

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
                <td><?= status_dot((string) $s['subscription_status']) ?></td>
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
        . '<th>Update available</th><th>Vulnerable</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $item) {
        $out .= '<tr>'
            . '<td>' . e($item['name']) . '</td>'
            . '<td class="mono">' . e($item['slug']) . '</td>'
            . '<td class="mono">' . e($item['version']) . '</td>'
            . '<td class="mono">' . (!empty($item['is_update_available']) ? e($item['new_version'] ?? '?') : '—') . '</td>'
            . '<td>' . (!empty($item['is_vulnerable']) ? '<span class="badge badge-error">Vulnerable</span>' : '—') . '</td>'
            . '<td>' . status_dot(!empty($item['is_active']) ? 'active' : 'inactive') . '</td>'
            . '</tr>';
    }

    return $out . '</tbody></table>';
};

$orderThemes = static function (array $themes): array {
    $activeIndex = null;
    foreach ($themes as $i => $t) {
        if (!empty($t['is_active'])) {
            $activeIndex = $i;
            break;
        }
    }
    if ($activeIndex === null) {
        return $themes;
    }

    $active = $themes[$activeIndex];
    unset($themes[$activeIndex]);

    // A parent's CRM "slug" can hold its display name ("Avada"): match name too, case-insensitively.
    $parentIndex = null;
    $childSlug   = strtolower((string) ($active['slug'] ?? ''));
    if (str_ends_with($childSlug, '-child')) {
        $parentGuess = substr($childSlug, 0, -6);
        foreach ($themes as $i => $t) {
            $slug = strtolower((string) ($t['slug'] ?? ''));
            $name = strtolower((string) ($t['name'] ?? ''));
            if ($slug === $parentGuess || $name === $parentGuess) {
                $parentIndex = $i;
                break;
            }
        }
    }

    $ordered = [$active];
    if ($parentIndex !== null) {
        $parent               = $themes[$parentIndex];
        $parent['is_active']  = true;
        $ordered[]            = $parent;
        unset($themes[$parentIndex]);
    }

    return array_merge($ordered, array_values($themes));
};
?>

<h2>Plugins</h2>
<?= $itemTable($items['plugin']) ?>

<h2>Themes</h2>
<?= $itemTable($orderThemes($items['theme'])) ?>

<?php if ($items['other'] !== []): ?>
    <h2>Other items</h2>
    <?= $itemTable($items['other']) ?>
<?php endif; ?>
