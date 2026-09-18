<?php
/** @var list<string> $types */
?>
<h1>Items</h1>
<p class="muted">Every plugin/theme item across every website in the external CRM database —
    "which sites have which plugins", filterable and searchable (name, slug or site URL).</p>

<div class="search-group">
<form class="search" onsubmit="return false">
    <input type="search" id="items-q" placeholder="Search…">
    <select id="items-type" class="js-filter-dropdown" data-label="Type">
        <option value="">All types</option>
        <?php foreach ($types as $type): ?>
            <option value="<?= e($type) ?>"><?= e(ucfirst($type)) ?></option>
        <?php endforeach; ?>
    </select>
    <label><input type="checkbox" id="items-vulnerable"> Vulnerable only</label>
    <label><input type="checkbox" id="items-update"> Update available only</label>
    <button type="button" class="btn btn-secondary js-apply-filters" id="items-filter-btn">Filter</button>
    <?php // A plain reload, same as every GET-param filter bar's own reset link
          // — simpler and more reliable here than reaching into the enhanced
          // Type dropdown's own internal chip state from outside it (nothing
          // is actually persisted in the URL on this client-side-only page,
          // so reloading already IS the reset). ?>
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
        }
      },
      columnDefs: [
        {
          targets: 2,
          render: function (data, type) {
            return type === 'display' ? '<span class="mono">' + xtEscapeHtml(data) + '</span>' : data;
          }
        },
        {
          targets: 5,
          render: function (data, type) {
            if (type !== 'display') { return data; }
            return data === 'Vulnerable' ? '<span class="val-error">Vulnerable</span>' : '—';
          }
        },
        {
          targets: 7,
          render: function (data, type, row) {
            return type === 'display'
              ? '<a href="/websites/' + encodeURIComponent(row[8]) + '">' + xtEscapeHtml(data) + '</a>'
              : data;
          }
        },
        { targets: 8, visible: false, searchable: false }
      ]
    });
    function apply() { table.draw(); }
    $('#items-filter-btn').on('click', apply);
    $('#items-q').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
  });
</script>
