<?php
/**
 * @var array<string, mixed> $website
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $subscriptions
 * @var array{plugin: list<array<string,mixed>>, theme: list<array<string,mixed>>, other: list<array<string,mixed>>} $items
 * @var string $csrf
 * @var string $notice
 * @var array<string, string|null> $links external_links config (see config/config.php)
 * @var \SatelliteWP\Xtractor\Reference\EndOfLife $eol
 */
use SatelliteWP\Xtractor\Crm\ClientsRepository;

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
// No engine column on swp_websites to tell MySQL from MariaDB apart (unlike
// Xtractor's own extraction payload, which has database_type) — but the
// version string itself does carry the tell (confirmed live: real rows read
// like "10.6.27-MariaDB" vs "8.4.9"), so the engine is sniffed from that
// instead of always assuming MySQL — most real websites in this database
// are MariaDB, and assuming MySQL would look up the wrong cycle for all of
// them (e.g. "10.6" is not a MySQL branch at all, so it would silently
// never match and never warn).
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
        field_raw('URL', '<a href="' . e($website['url']) . '" target="_blank" rel="noopener noreferrer">'
            . e(site_display($website['url'])) . '</a>'),
        field('Connection status', $website['connection_status'] ?? null),
        $versionField('PHP version', $website['php_version'] ?? null, $eolPhp),
        $versionField('MySQL version', $website['mysql_version'] ?? null, $eolMysql),
        $versionField('WordPress version', $website['wp_core_version'] ?? null, $eolWp, !empty($website['wp_core_is_vulnerable'])),
        field_raw('BlogVault site id', external_link(
            $links['blogvault_view_website'] ?? null,
            isset($website['blogvault_site_id']) ? substr((string) $website['blogvault_site_id'], 0, 8) : null,
            (string) ($website['blogvault_site_id'] ?? '—')
        )),
        field_raw('Tags', $website['tags'] !== []
            ? implode(' ', array_map(static fn (string $t): string => '<span class="badge badge-muted">' . e($t) . '</span>', $website['tags']))
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

    // Case-insensitive, and matched against the candidate's name too, not
    // just its slug: confirmed live against the real database that a
    // parent theme's own "slug" column here isn't reliably a true lowercase
    // WP slug (a real row: child slug "avada-child", but its parent's own
    // "slug" value is literally "Avada" — matching its display name, not a
    // normalized slug).
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
