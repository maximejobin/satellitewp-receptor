<?php
/** @var list<string> $types */
?>
<h1>Items</h1>
<p class="muted">Every plugin/theme item across every website in the external CRM database —
    "which sites have which plugins", filterable and searchable (name, slug or site URL).</p>

<p style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
    <input type="search" id="items-q" placeholder="Search name, slug or site URL…">
    <label>Type:
        <select id="items-type">
            <option value="">All</option>
            <?php foreach ($types as $type): ?>
                <option value="<?= e($type) ?>"><?= e(ucfirst($type)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><input type="checkbox" id="items-vulnerable"> Vulnerable only</label>
    <label><input type="checkbox" id="items-update"> Update available only</label>
    <button type="button" class="btn" id="items-filter-btn">Filter</button>
</p>

<table id="items-table" class="display" style="width:100%">
    <thead>
    <tr><th>Type</th><th>Name</th><th>Slug</th><th>Version</th><th>New version</th><th>Vulnerable</th><th>Active</th><th>Website</th></tr>
    </thead>
</table>
<script>
  $(function () {
    // One "Filter" button governs the text search + every dropdown/checkbox
    // together (2026-09-02, user: filters — the native quick-search box
    // included — must never apply live on keystroke/change, only on an
    // explicit click). `dom` below drops DataTables' own search box ('f');
    // the text field here drives `search.value` directly instead.
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
      }
    });
    function apply() { table.draw(); }
    $('#items-filter-btn').on('click', apply);
    $('#items-q').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
  });
</script>
