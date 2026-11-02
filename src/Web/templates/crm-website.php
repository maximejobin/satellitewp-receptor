<?php
/**
 * @var array<string, mixed> $website
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $subscriptions
 * @var array{plugin: list<array<string,mixed>>, theme: list<array<string,mixed>>, other: list<array<string,mixed>>} $items
 * @var string $csrf
 * @var string $notice
 * @var array<string, string|null> $links external_links config (see config/config.php)
 * @var \SatelliteWP\Manager\Crm\WebsiteVulnerability $vulnerability
 */
use SatelliteWP\Manager\Crm\ClientsRepository;

$notices = [
    'website-updated'       => ['success', 'Linked website updated.'],
    'website-update-failed' => ['critical', 'Could not update the linked website.'],
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
$eolStatus = $vulnerability->endOfLife($website);
$reasons   = $vulnerability->reasons($website, array_merge($items['plugin'], $items['theme']));

$clientLabels = array_map(
    static fn (array $c): string => '<a href="/clients/' . (int) $c['id'] . '">' . e(ClientsRepository::clientLabel($c)) . '</a>',
    $clients
);
?>
<h1><?= e(site_display($website['url'])) ?><?php if ($reasons !== []): ?> <span class="badge badge-error" title="<?= e(implode(' · ', $reasons)) ?>">Vulnerable</span><?php endif; ?></h1>

<?php if (isset($notices[$notice])): [$level, $text] = $notices[$notice]; ?>
    <?= notice($level, e($text)) ?>
<?php endif; ?>

<?php
?>
<section class="card info-card kv-tight">
    <table class="kv"><tbody><?= implode('', [
        field_raw('URL', link_or_text($website['url'] ?? null, site_display($website['url'] ?? ''), true)),
        field('Connection status', $website['connection_status'] ?? null),
        $versionField('PHP version', $website['php_version'] ?? null, $eolStatus['php']),
        $versionField('MySQL version', $website['mysql_version'] ?? null, $eolStatus['database']),
        $versionField('WordPress version', $website['wp_core_version'] ?? null, $eolStatus['wordpress'], $vulnerability->coreVulnerabilities($website) !== []),
        field_raw('BlogVault site id', external_link(
            $links['blogvault_view_website'] ?? null,
            isset($website['blogvault_site_id']) ? substr((string) $website['blogvault_site_id'], 0, 8) : null,
            (string) ($website['blogvault_site_id'] ?? '')
        )),
        field_raw('Tags', !empty($website['tags']) && is_array($website['tags'])
            ? implode(' ', array_map(static fn (mixed $t): string => '<span class="badge badge-muted">' . e($t) . '</span>', $website['tags']))
            : ''),
        field_raw('Client', $clientLabels !== [] ? implode(', ', $clientLabels) : ''),
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
                <td class="muted"><?= e($s['product_category'] ?? '') ?></td>
                <td><?= status_dot((string) $s['subscription_status']) ?></td>
                <td><?= e($s['next_renewal_date'] !== null ? substr((string) $s['next_renewal_date'], 0, 10) : '') ?></td>
                <td><?= subscription_website_form(
                    $s + ['website_id' => $website['id'], 'website_url' => $website['url'], 'client_label' => ClientsRepository::clientLabel($s)],
                    '/websites/' . (int) $website['id']
                ) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?= subscription_link_dialog($csrf) ?>
<?php endif; ?>

<?php
$itemTable = static function (array $rows) use ($vulnerability): string {
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
            . '<td class="mono">' . (!empty($item['is_update_available']) ? e($item['new_version'] ?? '?') : '') . '</td>'
            . '<td>' . ($vulnerability->itemIsVulnerable($item) ? '<span class="badge badge-error">Vulnerable</span>' : '') . '</td>'
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
