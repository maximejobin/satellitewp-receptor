<?php
/**
 * @var list<array<string, mixed>> $websites
 * @var list<string> $selectedTags
 * @var list<string> $selectedExcludeTags
 * @var list<string> $allTags
 * @var string $selectedConnection
 * @var string $selectedSource '', 'wordpress' or 'external'
 * @var string $search
 * @var bool $canEdit
 * @var string $csrf
 * @var string $notice
 * @var array<string, string|null> $links
 */
use SatelliteWP\Manager\Crm\ClientsRepository;
?>
<h1>Websites</h1>
<p class="muted">From the external CRM/billing database, plus the non-WordPress websites added here
    ("External"). Filter by tag or connection below — DEV-tagged sites are excluded by default
    ("Exclude tags"). Search matches the site's URL or its client's company name.</p>

<?php
$notices = [
    'external-deleted'   => ['success', 'External website deleted.'],
    'external-invalid'   => ['critical', 'Enter an absolute http(s) URL.'],
    'external-duplicate' => ['critical', 'This URL is already a website.'],
    'external-not-found' => ['critical', 'External website not found.'],
    'external-failed'    => ['critical', 'Could not save the external website.'],
];
?>
<?php if (isset($notices[$notice])): [$level, $text] = $notices[$notice]; ?>
    <?= notice($level, e($text)) ?>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="obs-toolbar">
    <button type="button" class="btn" data-open-dialog="external-add">+ Add external website</button>
</div>
<?= external_website_dialog('external-add', '/websites/external', 'New external website', 'Add website', $csrf) ?>
<?php endif; ?>

<div class="search-group">
<form method="get" class="search">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search…">

    <?= tag_filter('tags-include-filter', 'Include tags', 'tag', $selectedTags, $allTags, false) ?>
    <?= tag_filter('tags-exclude-filter', 'Exclude tags', 'excludeTag', $selectedExcludeTags, $allTags, true) ?>
    <input type="hidden" name="exclude_tag_present" value="1">

    <select name="connection" class="js-filter-dropdown" data-label="Connection">
        <option value="">Any connection</option>
        <option value="CONNECTED" <?= $selectedConnection === 'CONNECTED' ? 'selected' : '' ?>>Connected</option>
        <option value="DISCONNECTED" <?= $selectedConnection === 'DISCONNECTED' ? 'selected' : '' ?>>Disconnected</option>
    </select>
    <select name="source" class="js-filter-dropdown" data-label="Source">
        <option value="">Any source</option>
        <option value="wordpress" <?= $selectedSource === 'wordpress' ? 'selected' : '' ?>>WordPress</option>
        <option value="external" <?= $selectedSource === 'external' ? 'selected' : '' ?>>External</option>
    </select>
    <button type="submit" class="btn btn-secondary js-apply-filters">Filter</button>
    <?php if ($selectedTags !== [] || $selectedConnection !== '' || $search !== '' || $selectedSource !== ''
        || $selectedExcludeTags !== [ClientsRepository::TAG_EXCLUDED_FROM_ASSIGNMENT]): ?>
        <a href="/websites" class="search-reset">Reset filter</a>
    <?php endif; ?>
</form>

<?php if ($websites === []): ?>
    <p class="empty">No website matches these filters.</p>
<?php else: ?>
    <table id="websites-table" class="display" style="width:100%">
        <thead>
        <tr><th>URL</th><th>Client</th><th>WordPress</th><th>Tags</th><th>Connection</th><th>Security</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($websites as $w): ?>
            <?php
            $clients      = $w['clients'] ?? [];
            $clientLabels = array_map(
                static fn (array $c): string => '<a href="/clients/' . (int) $c['id'] . '">' . e(ClientsRepository::clientLabel($c)) . '</a>',
                $clients
            );
            ?>
            <tr>
                <td><a href="/websites/<?= e((string) $w['id']) ?>"><?= e(site_display($w['url'])) ?></a><?php if (!empty($w['external'])): ?>
                    <span class="badge badge-muted">External</span><?php endif; ?></td>
                <td><?= $clientLabels !== [] ? implode(', ', $clientLabels) : '' ?></td>
                <td class="mono"><?= e($w['wp_core_version'] ?? '') ?></td>
                <td><?php foreach ($w['tags'] as $tag): ?><span class="badge badge-muted"><?= e($tag) ?></span> <?php endforeach; ?></td>
                <td><?php if (($w['connection_status'] ?? '') !== ''): ?><span class="badge <?= $w['connection_status'] === 'CONNECTED' ? 'badge-ok' : 'badge-error' ?>">
                        <?= e($w['connection_status']) ?></span><?php endif; ?></td>
                <td><?php if ($w['vulnerable_reasons'] !== []): ?><span class="badge badge-error" title="<?= e(implode(' · ', $w['vulnerable_reasons'])) ?>">Vulnerable</span><?php endif; ?></td>
                <td><?= external_link_icon(
                    $links['blogvault_view_website'] ?? null,
                    isset($w['blogvault_site_id']) ? substr((string) $w['blogvault_site_id'], 0, 8) : null,
                    'View in BlogVault'
                ) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <script>
      $(function () {
        $('#websites-table').DataTable({ pageLength: 50, dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>' });
      });
    </script>
<?php endif; ?>
</div>
