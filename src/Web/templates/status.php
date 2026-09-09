<?php
/**
 * @var array{pending: int, running: int, done_24h: int, error: int} $extractionCounts
 * @var array<string, array{synced_at: string|null, threshold_seconds: int, stale: bool}> $syncSources
 * @var array{total: int, upToDate: int, stale: int} $syncTotals
 * @var bool $crmConfigured
 * @var array{
 *     unpaidClients?: list<array<string, mixed>>,
 *     emptyCompanyClients?: list<array<string, mixed>>,
 *     activeEmptyCompanyClients?: list<array<string, mixed>>,
 *     activeNoHubspotClients?: list<array<string, mixed>>,
 *     activeNoTeamworkClients?: list<array<string, mixed>>,
 *     orphanSubscriptions?: int,
 *     error?: string,
 * }|null $crm
 */
use SatelliteWP\Xtractor\Crm\ClientsRepository;

/** Short "name (N more)" list of client links, capped so this page never grows without bound. */
$clientLinks = static function (array $clients, int $cap = 8): string {
    if ($clients === []) {
        return '';
    }
    $shown = array_slice($clients, 0, $cap);
    $links = array_map(
        static fn (array $c): string => '<a href="/clients/' . (int) $c['id'] . '">' . e(ClientsRepository::clientLabel($c)) . '</a>',
        $shown
    );
    $extra = count($clients) - count($shown);

    return implode(', ', $links) . ($extra > 0 ? ' <span class="muted">(+' . $extra . ' more)</span>' : '');
};

/** One KPI tile for the Clients summary — red/plain depending on the count. */
$clientTile = static function (string $label, int $count, string $sub = ''): string {
    $cls = $count > 0 ? ' class="v val-error"' : ' class="v"';

    return '<div class="tile"><div class="k">' . e($label) . '</div><div' . $cls . '>' . $count . '</div>'
        . ($sub !== '' ? '<div class="s">' . e($sub) . '</div>' : '') . '</div>';
};
?>
<h1>Status</h1>
<p class="muted">A system-health snapshot — the extraction pipeline, external sync freshness, and a few
    data-quality checks.</p>

<h2>Clients</h2>
<?php if (!$crmConfigured): ?>
    <p class="empty">External CRM database not connected — <span class="mono">crm_db</span> isn't configured.</p>
<?php elseif (isset($crm['error'])): ?>
    <?= notice('critical', 'Could not reach the external CRM database right now. Logged as '
        . '<span class="mono">ref ' . e($crm['error']) . '</span>.') ?>
<?php else: ?>
    <div class="tiles">
        <?= $clientTile('Unpaid', count($crm['unpaidClients']), 'clients with an on-hold subscription') ?>
        <?= $clientTile('Missing company', count($crm['emptyCompanyClients']), 'all clients') ?>
        <?= $clientTile('Missing company (active)', count($crm['activeEmptyCompanyClients']), 'active clients only') ?>
        <?= $clientTile('Missing HubSpot ID (active)', count($crm['activeNoHubspotClients']), 'active clients only') ?>
        <?= $clientTile('Missing Teamwork ID (active)', count($crm['activeNoTeamworkClients']), 'active clients only') ?>
        <?= $clientTile('Subscriptions not linked', $crm['orphanSubscriptions'], 'no website assigned') ?>
    </div>
<?php endif; ?>

<h2>Extractions</h2>
<div class="tiles">
    <div class="tile">
        <div class="k">Waiting to run</div>
        <div class="v"><?= (int) $extractionCounts['pending'] ?></div>
        <div class="s">pending + queued</div>
    </div>
    <div class="tile">
        <div class="k">Running</div>
        <div class="v"><?= (int) $extractionCounts['running'] ?></div>
    </div>
    <div class="tile">
        <div class="k">Done</div>
        <div class="v"><?= (int) $extractionCounts['done_24h'] ?></div>
        <div class="s">last 24h</div>
    </div>
    <div class="tile">
        <div class="k">Errors</div>
        <div class="v"><?= (int) $extractionCounts['error'] ?></div>
        <?php if ($extractionCounts['error'] > 0): ?>
            <div class="s crit">needs a look</div>
        <?php endif; ?>
    </div>
</div>

<h2>External sync</h2>
<p class="muted">Every external table/cache this app reads, and how stale it's allowed to get
    (<span class="mono">crm_sync_freshness</span>/<span class="mono">data_sync_freshness</span> in config.php)
    before this counts it as an error.</p>
<div class="tiles">
    <div class="tile">
        <div class="k">Total</div>
        <div class="v"><?= (int) $syncTotals['total'] ?></div>
    </div>
    <div class="tile">
        <div class="k">Up to date</div>
        <div class="v"><?= (int) $syncTotals['upToDate'] ?></div>
    </div>
    <div class="tile">
        <div class="k">Error</div>
        <div class="v"><?= (int) $syncTotals['stale'] ?></div>
        <?php if ($syncTotals['stale'] > 0): ?>
            <div class="s crit">overdue for a refresh</div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$crmConfigured): ?>
    <p class="empty" style="margin-top:.8rem">External CRM database not connected —
        <span class="mono">crm_db</span> isn't configured, so its tables aren't in the list below
        (the app's own reference caches still are).</p>
<?php elseif (isset($crm['error'])): ?>
    <div style="margin-top:.8rem"><?= notice('critical', 'Could not reach the external CRM database right now — '
        . 'its tables aren\'t in the list below. Logged as <span class="mono">ref ' . e($crm['error']) . '</span>.') ?></div>
<?php endif; ?>

<table style="margin-top:.8rem">
    <thead><tr><th>Source</th><th>Last sync</th><th>Expected within</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($syncSources as $name => $info): ?>
        <tr>
            <td class="mono"><?= e($name) ?></td>
            <td><?= $info['synced_at'] !== null ? fmt_relative_time($info['synced_at']) : '<span class="muted">never</span>' ?></td>
            <td class="muted"><?= e((string) round($info['threshold_seconds'] / 3600, 1)) ?>h</td>
            <td><?= $info['stale'] ? badge('error') : badge('ok') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($crmConfigured && !isset($crm['error'])): ?>
    <h2>Client detail</h2>
    <?php
    $unpaid              = $crm['unpaidClients'];
    $emptyCompany        = $crm['emptyCompanyClients'];
    $activeEmptyCompany  = $crm['activeEmptyCompanyClients'];
    $activeNoHubspot     = $crm['activeNoHubspotClients'];
    $activeNoTeamwork    = $crm['activeNoTeamworkClients'];
    $orphanCount         = $crm['orphanSubscriptions'];
    ?>
    <?= section('Data quality', implode('', [
        field_raw(
            'Unpaid subscriptions',
            count($unpaid) > 0
                ? '<span class="val-error">' . count($unpaid) . ' client' . (count($unpaid) === 1 ? '' : 's') . '</span> — ' . $clientLinks($unpaid)
                : '<span class="val-ok">0</span>'
        ),
        field_raw(
            'Missing company name',
            count($emptyCompany) > 0
                ? '<span class="val-warn">' . count($emptyCompany) . ' client' . (count($emptyCompany) === 1 ? '' : 's') . '</span> — ' . $clientLinks($emptyCompany)
                : '<span class="val-ok">0</span>'
        ),
        field_raw(
            'Missing company name (active clients)',
            count($activeEmptyCompany) > 0
                ? '<span class="val-warn">' . count($activeEmptyCompany) . ' client' . (count($activeEmptyCompany) === 1 ? '' : 's') . '</span> — ' . $clientLinks($activeEmptyCompany)
                : '<span class="val-ok">0</span>'
        ),
        field_raw(
            'Missing HubSpot ID (active clients)',
            count($activeNoHubspot) > 0
                ? '<span class="val-warn">' . count($activeNoHubspot) . ' client' . (count($activeNoHubspot) === 1 ? '' : 's') . '</span> — ' . $clientLinks($activeNoHubspot)
                : '<span class="val-ok">0</span>'
        ),
        field_raw(
            'Missing Teamwork ID (active clients)',
            count($activeNoTeamwork) > 0
                ? '<span class="val-warn">' . count($activeNoTeamwork) . ' client' . (count($activeNoTeamwork) === 1 ? '' : 's') . '</span> — ' . $clientLinks($activeNoTeamwork)
                : '<span class="val-ok">0</span>'
        ),
        field_raw(
            'Subscriptions with no linked website',
            $orphanCount > 0
                ? '<span class="val-warn">' . $orphanCount . '</span> — <a href="/clients?subscriptions=have_unassigned">view</a>'
                : '<span class="val-ok">0</span>'
        ),
    ])) ?>
<?php endif; ?>
