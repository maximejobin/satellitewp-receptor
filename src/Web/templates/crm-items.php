<?php
/**
 * @var list<string> $types
 * @var list<string> $allTags
 * @var list<string> $excludeTags
 */
?>
<h1>Items</h1>
<p class="muted">Every plugin/theme item across every website in the external CRM database —
    "which sites have which plugins", filterable and searchable (name, slug or site URL).
    DEV-tagged sites are excluded by default ("Exclude tags").</p>

<div class="search-group">
<form class="search" id="items-filters" onsubmit="return false">
    <input type="search" id="items-q" placeholder="Search…">
    <select id="items-type" class="js-filter-dropdown" data-label="Type">
        <option value="">All types</option>
        <?php foreach ($types as $type): ?>
            <option value="<?= e($type) ?>"><?= e(ucfirst($type)) ?></option>
        <?php endforeach; ?>
    </select>
    <?= tag_filter('tags-include-filter', 'Include tags', 'tag', [], $allTags, false) ?>
    <?= tag_filter('tags-exclude-filter', 'Exclude tags', 'excludeTag', $excludeTags, $allTags, true) ?>
    <label><input type="checkbox" id="items-vulnerable"> Vulnerable only</label>
    <label><input type="checkbox" id="items-update"> Update available only</label>
    <button type="button" class="btn btn-secondary js-apply-filters" id="items-filter-btn">Filter</button>
    <?php // Nothing is in the URL here, so a reload is the reset. ?>
    <a href="/items" class="search-reset">Reset filter</a>
</form>

<table id="items-table" class="display" style="width:100%">
    <thead>
    <tr><th>Type</th><th>Name</th><th>Slug</th><th>Version</th><th>New version</th><th>Vulnerable</th><th>Active</th><th>Website</th><th></th></tr>
    </thead>
</table>
</div>
<script>
  $(function () {
    var table = $('#items-table').DataTable({
      serverSide: true,
      pageLength: 50,
      dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>',
      ajax: {
        url: '/items/search',
        data: function (d) {
          d.search.value = $('#items-q').val() || '';
          d.type = $('#items-type').val();
          d.vulnerable = $('#items-vulnerable').is(':checked') ? 1 : '';
          d.updateAvailable = $('#items-update').is(':checked') ? 1 : '';
          d.tag = $('#items-filters input[name="tag[]"]').map(function () { return this.value; }).get();
          d.excludeTag = $('#items-filters input[name="excludeTag[]"]').map(function () { return this.value; }).get();
          d.exclude_tag_present = 1;
        }
      },
      // Cells arrive as escaped HTML from CrmController::itemRows().
      columnDefs: [
        { targets: 8, visible: false, searchable: false }
      ]
    });
    function apply() { table.draw(); }
    $('#items-filter-btn').on('click', apply);
    $('#items-q').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
  });
</script>
