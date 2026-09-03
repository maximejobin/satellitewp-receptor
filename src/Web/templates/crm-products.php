<?php
/**
 * @var list<array<string, mixed>> $products
 * @var string $selectedType
 */
use SatelliteWP\Xtractor\Crm\ClientsRepository;

$types = [
    ''                                          => 'All',
    ClientsRepository::PRODUCT_TYPE_LICENSE          => 'License',
    ClientsRepository::PRODUCT_TYPE_MAINTENANCE_PLAN => 'Maintenance plan',
    ClientsRepository::PRODUCT_TYPE_OTHER            => 'Other',
];
?>
<h1>Products</h1>
<p class="muted">From the external CRM/billing database. A product's type is derived from whether it has a
    matching license or maintenance-plan record, not a free-text field.</p>

<p class="filters">
    <?php foreach ($types as $value => $label): ?>
        <a class="<?= $value === $selectedType ? 'active' : '' ?>"
           href="/products<?= $value !== '' ? '?type=' . urlencode($value) : '' ?>"><?= e($label) ?></a><?= $value !== ClientsRepository::PRODUCT_TYPE_OTHER ? ' ·' : '' ?>
    <?php endforeach; ?>
</p>

<?php if ($products === []): ?>
    <p class="empty">No product matches this filter.</p>
<?php else: ?>
    <p><?= dt_search_box('products-table', 'Search within these results…') ?></p>
    <table id="products-table" class="display" style="width:100%">
        <thead>
        <tr><th>Name</th><th>Type</th><th>Category</th><th>Detail</th><th>Last synced</th></tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><?= e($p['name']) ?></td>
                <td><?= badge($p['product_type']) ?></td>
                <td class="muted"><?= e($p['category'] ?? '—') ?></td>
                <td class="mono" style="font-size:.8rem">
                    <?php if ($p['product_type'] === ClientsRepository::PRODUCT_TYPE_LICENSE): ?>
                        <?= e($p['license_slug'] ?? '—') ?><?= !empty($p['is_manual_update']) ? ' (manual update)' : '' ?>
                    <?php elseif ($p['product_type'] === ClientsRepository::PRODUCT_TYPE_MAINTENANCE_PLAN): ?>
                        <?= !empty($p['is_licenses_included']) ? 'includes licenses' : 'no licenses included' ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td class="muted"><?= e($p['date_sync']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <script>
      $(function () {
        var dt = $('#products-table').DataTable({ pageLength: 50, dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>' });
        initExplicitSearch('#products-table', dt);
      });
    </script>
<?php endif; ?>
