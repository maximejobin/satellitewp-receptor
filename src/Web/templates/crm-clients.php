<?php
/**
 * @var list<array<string, mixed>> $clients
 * @var string $selectedStatus
 * @var string $search
 * @var string $selectedSubscriptions
 * @var string|null $lastSyncedAt
 * @var int $orphanCount
 */
use SatelliteWP\Xtractor\Crm\ClientsRepository;
?>
<h1>Clients</h1>
<p class="muted">From the external CRM/billing database — who owns which subscription and which website.
    Last synced <?= fmt_relative_time($lastSyncedAt) ?>.</p>

<?php if ($orphanCount > 0 && $selectedSubscriptions !== 'have_unassigned'): ?>
    <?= notice('warning', $orphanCount . ' subscription' . ($orphanCount === 1 ? '' : 's')
        . ' not linked to a website — <a href="/clients?subscriptions=have_unassigned">view '
        . ($orphanCount === 1 ? 'it' : 'them') . '</a>.') ?>
<?php endif; ?>

<form method="get" class="search" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Company, contact or email…">
    <select name="status">
        <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>All</option>
        <option value="active" <?= $selectedStatus === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $selectedStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
    <select name="subscriptions">
        <option value="all" <?= $selectedSubscriptions === 'all' ? 'selected' : '' ?>>All</option>
        <option value="have_unassigned" <?= $selectedSubscriptions === 'have_unassigned' ? 'selected' : '' ?>>Have unassigned</option>
        <option value="no_unassigned" <?= $selectedSubscriptions === 'no_unassigned' ? 'selected' : '' ?>>Do not have unassigned</option>
    </select>
    <button type="submit" class="btn">Search</button>
    <?php if ($selectedStatus !== 'active' || $selectedSubscriptions !== 'all' || $search !== ''): ?>
        <a href="/clients" class="muted">Reset to default</a>
    <?php endif; ?>
</form>

<?php if ($clients === []): ?>
    <p class="empty">No client matches these filters.</p>
<?php else: ?>
    <table id="clients-table" class="display" style="width:100%">
        <thead>
        <tr><th>Company</th><th>Contact</th><th>Email</th><th>Services</th><th>Websites</th></tr>
        </thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
            <?php
            $contact = trim(trim((string) ($c['first_name'] ?? '')) . ' ' . trim((string) ($c['last_name'] ?? '')));
            $company = trim((string) ($c['company'] ?? '')) !== '' ? (string) $c['company'] : $contact;
            ?>
            <tr>
                <td><a href="/clients/<?= (int) $c['id'] ?>"><?= e($company !== '' ? $company : ClientsRepository::clientLabel($c)) ?></a></td>
                <td><?= e($contact !== '' ? $contact : '—') ?></td>
                <td class="mono"><?= e($c['email']) ?></td>
                <td><?= (int) $c['active_subscription_count'] ?> active / <?= (int) $c['subscription_count'] ?> total
                    <?php if ((int) $c['orphan_subscription_count'] > 0): ?>
                        <span class="badge badge-warn" title="Subscription(s) with no linked site">
                            <?= (int) $c['orphan_subscription_count'] ?> unlinked</span>
                    <?php endif; ?></td>
                <td><?= (int) $c['website_count'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <script>
      $(function () {
        // The search box above already queries company/contact/email
        // server-side (Router::crmClientsPage()) — no separate in-table
        // quick-search box needed here, unlike the client-side-only tables.
        $('#clients-table').DataTable({ pageLength: 50, dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>' });
      });
    </script>
<?php endif; ?>
