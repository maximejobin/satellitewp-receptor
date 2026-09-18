<?php
/**
 * @var list<array<string, mixed>> $websites
 * @var list<string> $selectedTags
 * @var list<string> $selectedExcludeTags
 * @var list<string> $allTags
 * @var string $selectedConnection
 * @var string $search
 * @var array<string, string|null> $links
 */
use SatelliteWP\Xtractor\Crm\ClientsRepository;

$tagFilter = static function (string $id, string $label, string $field, array $selected, array $allTags, bool $excludeStyle): string {
    $options = '';
    foreach ($allTags as $tag) {
        $options .= '<button type="button" data-tag="' . e($tag) . '">' . e($tag) . '</button>';
    }

    return '<div class="filter-dropdown tag-filter' . ($excludeStyle ? ' tag-filter-exclude' : '') . '" id="' . e($id) . '" '
        . 'data-field="' . e($field) . '" data-selected="' . e(implode(',', $selected)) . '">'
        . '<button type="button" class="filter-dropdown-btn">' . e($label)
        . ' <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></button>'
        . '<div class="filter-dropdown-panel">' . $options . '</div></div>';
};
?>
<h1>Websites</h1>
<p class="muted">From the external CRM/billing database. Filter by tag or connection below —
    DEV-tagged sites are excluded by default ("Exclude tags"). Search matches the site's URL or
    its client's company name.</p>

<div class="search-group">
<form method="get" class="search">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search…">

    <?php // Plain enumerated dropdowns, not select2 AJAX — the tag vocabulary is
          // small. initTagFilter() in layout.php owns the interaction: click a
          // tag to add/remove it from THIS list, independently of the other. ?>
    <?= $tagFilter('tags-include-filter', 'Include tags', 'tag', $selectedTags, $allTags, false) ?>
    <?= $tagFilter('tags-exclude-filter', 'Exclude tags', 'excludeTag', $selectedExcludeTags, $allTags, true) ?>
    <input type="hidden" name="exclude_tag_present" value="1">

    <select name="connection" class="js-filter-dropdown" data-label="Connection">
        <option value="">Any connection</option>
        <option value="CONNECTED" <?= $selectedConnection === 'CONNECTED' ? 'selected' : '' ?>>Connected</option>
        <option value="DISCONNECTED" <?= $selectedConnection === 'DISCONNECTED' ? 'selected' : '' ?>>Disconnected</option>
    </select>
    <button type="submit" class="btn btn-secondary js-apply-filters">Filter</button>
    <?php if ($selectedTags !== [] || $selectedConnection !== '' || $search !== ''
        || $selectedExcludeTags !== [ClientsRepository::TAG_EXCLUDED_FROM_ASSIGNMENT]): ?>
        <a href="/websites" class="search-reset">Reset filter</a>
    <?php endif; ?>
</form>

<?php if ($websites === []): ?>
    <p class="empty">No website matches these filters.</p>
<?php else: ?>
    <table id="websites-table" class="display" style="width:100%">
        <thead>
        <tr><th>URL</th><th>Client</th><th>WordPress</th><th>Tags</th><th>Connection</th><th></th></tr>
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
                <td><a href="/websites/<?= (int) $w['id'] ?>"><?= e(site_display($w['url'])) ?></a></td>
                <td><?= $clientLabels !== [] ? implode(', ', $clientLabels) : '<span class="muted">—</span>' ?></td>
                <td class="mono"><?= e($w['wp_core_version'] ?? '—') ?></td>
                <td><?php foreach ($w['tags'] as $tag): ?><span class="badge badge-muted"><?= e($tag) ?></span> <?php endforeach; ?></td>
                <td><span class="badge <?= ($w['connection_status'] ?? '') === 'CONNECTED' ? 'badge-ok' : 'badge-error' ?>">
                        <?= e($w['connection_status'] ?? '—') ?></span></td>
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
