<?php
/**
 * @var list<array<string, mixed>> $products
 * @var string $selectedType
 * @var string|null $lastSyncedAt
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
<p class="muted">From the external CRM/billing database. Last synced <?= fmt_relative_time($lastSyncedAt) ?>.
    A product's category is derived from whether it has a matching license or maintenance-plan
    record, not a free-text field.</p>

<p class="filters">
    <?php foreach ($types as $value => $label): ?>
        <a class="<?= $value === $selectedType ? 'active' : '' ?>"
           href="/products<?= $value !== '' ? '?type=' . urlencode($value) : '' ?>"><?= e($label) ?></a><?= $value !== ClientsRepository::PRODUCT_TYPE_OTHER ? ' ·' : '' ?>
    <?php endforeach; ?>
</p>

<?php if ($products === []): ?>
    <p class="empty">No product matches this filter.</p>
<?php else: ?>
    <div class="search-group">
    <p class="search"><?= dt_search_box('products-table') ?></p>
    <table id="products-table" class="display" style="width:100%">
        <thead>
        <tr><th>Name</th><th>Slug</th><th>Category</th></tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><?= e($p['name']) ?></td>
                <td class="mono"><?= e($p['license_slug'] ?? '—') ?></td>
                <td><?= badge($p['product_type']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <script>
      $(function () {
        var dt = $('#products-table').DataTable({ pageLength: 50, dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>' });
        initExplicitSearch('#products-table', dt);
      });
    </script>
<?php endif; ?>
