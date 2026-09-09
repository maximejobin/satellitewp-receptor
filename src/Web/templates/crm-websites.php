<?php
/**
 * @var list<array<string, mixed>> $websites
 * @var list<string> $selectedTags
 * @var int|null $selectedClientId
 * @var string|null $selectedClientLabel
 * @var string $selectedConnection
 * @var string $search
 */
?>
<h1>Websites</h1>
<p class="muted">From the external CRM/billing database. Filter by tag, client or connection below.</p>

<form method="get" class="search">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="URL or host…">
    <?php // select2 AJAX: only the *selected* option(s), if any, are ever rendered here —
          // every other tag/client is fetched from the search endpoints below as the
          // operator types, so this page never has to preload the full list. ?>
    <select name="tag[]" class="js-select2" data-ajax-url="/websites/tags/search" data-placeholder="All tags" multiple style="min-width:16rem">
        <?php foreach ($selectedTags as $tag): ?>
            <option value="<?= e($tag) ?>" selected><?= e($tag) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="client_id" class="js-select2" data-ajax-url="/clients/search" data-placeholder="All clients">
        <option value=""></option>
        <?php if ($selectedClientId !== null): ?>
            <option value="<?= (int) $selectedClientId ?>" selected><?= e($selectedClientLabel ?? ('#' . $selectedClientId)) ?></option>
        <?php endif; ?>
    </select>
    <select name="connection" class="js-filter-dropdown" data-label="Connection">
        <option value="">Any connection</option>
        <option value="CONNECTED" <?= $selectedConnection === 'CONNECTED' ? 'selected' : '' ?>>Connected</option>
        <option value="DISCONNECTED" <?= $selectedConnection === 'DISCONNECTED' ? 'selected' : '' ?>>Disconnected</option>
    </select>
    <button type="submit" class="btn btn-secondary">Filter</button>
    <?php if ($selectedTags !== [] || $selectedClientId !== null || $selectedConnection !== '' || $search !== ''): ?>
        <a href="/websites" class="search-reset">Reset filter</a>
    <?php endif; ?>
</form>

<?php if ($websites === []): ?>
    <p class="empty">No website matches these filters.</p>
<?php else: ?>
    <div class="search-group">
    <p class="search"><?= dt_search_box('websites-table', 'Search within these results…') ?></p>
    <table id="websites-table" class="display" style="width:100%">
        <thead>
        <tr><th>URL</th><th>WordPress</th><th>Tags</th><th>Connection</th></tr>
        </thead>
        <tbody>
        <?php foreach ($websites as $w): ?>
            <tr>
                <td><a href="/websites/<?= (int) $w['id'] ?>"><?= e(site_display($w['url'])) ?></a></td>
                <td class="mono"><?= e($w['wp_core_version'] ?? '—') ?></td>
                <td><?php foreach ($w['tags'] as $tag): ?><span class="badge badge-muted"><?= e($tag) ?></span> <?php endforeach; ?></td>
                <td><span class="badge <?= ($w['connection_status'] ?? '') === 'CONNECTED' ? 'badge-ok' : 'badge-error' ?>">
                        <?= e($w['connection_status'] ?? '—') ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <script>
      $(function () {
        var dt = $('#websites-table').DataTable({ pageLength: 50, dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>' });
        initExplicitSearch('#websites-table', dt);
      });
    </script>
<?php endif; ?>
