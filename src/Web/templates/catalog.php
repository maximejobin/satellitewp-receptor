<?php
/** @var bool $needsOnly @var bool $unclassifiedOnly */
?>
<h1>Software catalogue</h1>
<p class="muted">Every plugin and theme seen across extractions, with its licensing.
    <strong>premium</strong> and <strong>mixed</strong> likely need a paid licence.</p>

<p class="filters">
    <a class="<?= !$needsOnly && !$unclassifiedOnly ? 'active' : '' ?>" href="/catalog">All</a> ·
    <a class="<?= $needsOnly ? 'active' : '' ?>" href="/catalog?needs=1">Needs licence</a> ·
    <a class="<?= $unclassifiedOnly ? 'active' : '' ?>" href="/catalog?unclassified=1">Not classified</a>
</p>

<p><?= dt_search_box('catalog-table', 'Search slug or name…') ?></p>
<table id="catalog-table" class="display" style="width:100%">
    <thead>
    <tr><th>Type</th><th>Slug</th><th>Name</th><th>Licence</th></tr>
    </thead>
</table>
<script>
  $(function () {
    // Server-side AJAX (SoftwareCatalog::search()), not a client-side table:
    // the catalogue is expected to grow into the thousands of entries.
    var dt = $('#catalog-table').DataTable({
      serverSide: true,
      pageLength: 50,
      dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>',
      language: { emptyTable: 'Catalogue is empty — process an extraction first.' },
      ajax: {
        url: '/catalog/search',
        data: function (d) {
          d.needs = <?= $needsOnly ? 'true' : 'false' ?>;
          d.unclassified = <?= $unclassifiedOnly ? 'true' : 'false' ?>;
        }
      }
    });
    initExplicitSearch('#catalog-table', dt);
  });
</script>
