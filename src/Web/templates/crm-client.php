<?php
/**
 * @var array<string, mixed> $client
 * @var list<array<string, mixed>> $subscriptions
 * @var string $csrf
 * @var string $notice
 * @var array<string, string|null> $links external_links config (see config/config.php)
 */
use SatelliteWP\Xtractor\Crm\ClientsRepository;

$notices = [
    'website-updated'       => ['badge-ok', 'Linked website updated.'],
    'website-update-failed' => ['badge-error', 'Could not update the linked website.'],
];

$contact = trim(trim((string) ($client['first_name'] ?? '')) . ' ' . trim((string) ($client['last_name'] ?? '')));
$company = trim((string) ($client['company'] ?? '')) !== '' ? (string) $client['company'] : $contact;

$unassigned = array_values(array_filter($subscriptions, static fn (array $s): bool => empty($s['website_id'])));
?>
<h1><?= e($company !== '' ? $company : ClientsRepository::clientLabel($client)) ?></h1>

<?php if (isset($notices[$notice])): [$cls, $text] = $notices[$notice]; ?>
    <p><span class="badge <?= $cls ?>"><?= e($text) ?></span></p>
<?php endif; ?>

<?php if ($unassigned !== []): ?>
    <?= notice('warning', count($unassigned) . ' service' . (count($unassigned) === 1 ? '' : 's')
        . ' not linked to a website.') ?>
<?php endif; ?>

<?php
// Explicit column 1 / column 2 split, NOT the section()/kv-cols-2
// single-table pattern used everywhere else on this page — see the CSS
// comment on .kv-cols-2. section() always wraps its rows in one <table>, and
// a browser will not fragment a <table> across CSS multi-column layout, so
// this card is built directly instead: a normal card shell around two
// separate <table class="kv"> columns.
$editButton = external_link_button($links['wordpress_edit_user'] ?? null, $client['id'], 'Edit');
?>
<section class="card info-card">
    <h3>Client<?= $editButton !== '' ? ' ' . $editButton : '' ?></h3>
    <div class="kv-cols-2">
        <table class="kv"><tbody><?= implode('', [
            field('Company', $company !== '' ? $company : null),
            field('Contact', $contact !== '' ? $contact : null),
            field_raw('Email', e($client['email'] ?? '') . ' ' . copy_button((string) ($client['email'] ?? ''))),
        ]) ?></tbody></table>
        <table class="kv"><tbody><?= implode('', [
            field_raw('Teamwork id', external_link($links['teamwork_client_url'] ?? null, $client['teamwork_id'] ?? null, (string) ($client['teamwork_id'] ?? '—'))),
            field_raw('HubSpot id', external_link($links['hubspot_client_url'] ?? null, $client['hubspot_id'] ?? null, (string) ($client['hubspot_id'] ?? '—'))),
            field_raw('BlogVault client id', external_link($links['blogvault_client_url'] ?? null, $client['blogvault_client_id'] ?? null, (string) ($client['blogvault_client_id'] ?? '—'))),
        ]) ?></tbody></table>
    </div>
</section>
<p class="muted" style="margin-top:-.8rem">Last sync: <?= fmt_relative_time($client['date_sync'] ?? null) ?>.</p>

<h2>Services</h2>
<?php if ($subscriptions === []): ?>
    <p class="empty">No service.</p>
<?php else: ?>
    <?php
    $websiteLabels = [];
    foreach ($subscriptions as $s) {
        $websiteLabels[] = empty($s['website_id']) ? 'Unassigned' : site_display($s['website_url']);
    }
    $websiteLabels = array_values(array_unique($websiteLabels));
    sort($websiteLabels);
    ?>
    <?php // A single wrapper so the filter bar sits right against the table
          // below it (2026-09-02, user: "plus 'collés'") — as two direct
          // siblings of .content they'd each get its 1.4rem flex gap, same
          // as every other pair of blocks on the page. ?>
    <div class="pill-bar-group">
    <form class="pill-bar" onsubmit="return false">
        <input type="search" id="svc-search" placeholder="Search product…">
        <select id="svc-website">
            <option value="">All websites</option>
            <?php foreach ($websiteLabels as $wl): ?>
                <option value="<?= e($wl) ?>"><?= e($wl) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-secondary" id="svc-filter-btn">Filter</button>
        <a href="#" class="pill-reset" id="svc-reset">Reset Filters</a>
    </form>
    <table id="services-table">
        <thead>
        <tr>
            <th>Product</th><th>Category</th><th>Status</th>
            <th>Created</th><th>Last payment</th><th>Next renewal</th><th>Website</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($subscriptions as $s): ?>
            <?php $websiteLabel = empty($s['website_id']) ? 'Unassigned' : site_display($s['website_url']); ?>
            <tr data-product="<?= e(strtolower((string) $s['product_name'])) ?>" data-website="<?= e($websiteLabel) ?>">
                <td><?= e($s['product_name']) ?><?php if (!empty($s['license_slug'])): ?>
                        <div class="muted mono" style="font-size:.8rem"><?= e($s['license_slug']) ?></div>
                    <?php endif; ?></td>
                <td class="muted"><?= e($s['product_category'] ?? '—') ?></td>
                <td><?= status_dot((string) $s['subscription_status']) ?></td>
                <td><?= e($s['creation_date'] !== null ? substr((string) $s['creation_date'], 0, 10) : '—') ?></td>
                <td><?= e($s['last_payment_date'] !== null ? substr((string) $s['last_payment_date'], 0, 10) : '—') ?></td>
                <td><?= e($s['next_renewal_date'] !== null ? substr((string) $s['next_renewal_date'], 0, 10) : '—') ?></td>
                <td><?= subscription_website_form($s, $csrf, '/clients/' . (int) $client['id']) ?></td>
                <td><?= external_link_button($links['wordpress_edit_subscription'] ?? null, $s['id'], 'Edit') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <script>
      (function () {
        var $search = document.getElementById('svc-search');
        var $website = document.getElementById('svc-website');
        function apply() {
          var q = $search.value.trim().toLowerCase();
          var site = $website.value;
          document.querySelectorAll('#services-table tbody tr').forEach(function (tr) {
            var matchesProduct = q === '' || tr.dataset.product.indexOf(q) !== -1;
            var matchesSite = site === '' || tr.dataset.website === site;
            tr.style.display = (matchesProduct && matchesSite) ? '' : 'none';
          });
        }
        document.getElementById('svc-filter-btn').addEventListener('click', apply);
        $search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
        document.getElementById('svc-reset').addEventListener('click', function (e) {
          e.preventDefault();
          $search.value = '';
          $website.value = '';
          apply();
        });
      })();
    </script>
<?php endif; ?>
