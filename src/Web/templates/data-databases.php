<?php
/**
 * @var list<array<string, mixed>> $cycles
 * @var \SatelliteWP\Xtractor\Reference\EndOfLife $eol
 * @var string|null $mysqlRefreshedAt
 * @var string|null $mariadbRefreshedAt
 */
?>
<h1>Databases</h1>
<p class="muted">Known MySQL and MariaDB branches, from the local endoflife.date cache
    (<code>bin/xtractor reference:refresh</code>) — not the engines installed on tracked sites.
    Search, sort or filter with the search box; always sorted by version descending.</p>
<p><?= fmt_refreshed($mysqlRefreshedAt, 2 * 3600) ?> <span class="muted" style="font-size:.85rem">(MySQL)</span>
    &nbsp; <?= fmt_refreshed($mariadbRefreshedAt, 2 * 3600) ?> <span class="muted" style="font-size:.85rem">(MariaDB)</span></p>

<?php if ($cycles === []): ?>
    <p class="empty">Cache empty: run <code>bin/xtractor reference:refresh</code> to fill it.</p>
<?php else: ?>
    <p style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
        <input type="search" id="db-search" placeholder="Search…">
        <label for="db-engine-filter">Engine: </label>
        <select id="db-engine-filter">
            <option value="">All</option>
            <option value="mysql">MySQL</option>
            <option value="mariadb">MariaDB</option>
        </select>
        <button type="button" class="btn" id="db-filter-btn">Filter</button>
    </p>
    <table id="db-versions" class="display" style="width:100%">
        <thead>
        <tr>
            <th>Engine</th>
            <th>Branch</th>
            <th>Latest version</th>
            <th>Released</th>
            <th>Latest release</th>
            <th>End of life</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($cycles as $c):
            $engine = (string) ($c['engine'] ?? '');
            $branch = (string) ($c['cycle'] ?? '');
            $status = $eol->eolStatus($engine, $branch);
        ?>
            <tr>
                <td><?= e($engine) ?></td>
                <td class="mono"><?= e($branch) ?></td>
                <td class="mono"><?= e($c['latest'] ?? '—') ?></td>
                <td class="muted"><?= e($c['releaseDate'] ?? '—') ?></td>
                <td class="muted"><?= e($c['latestReleaseDate'] ?? '—') ?></td>
                <td class="muted"><?= e($status[1] ?? '—') ?></td>
                <td>
                    <?php if ($status === null): ?>
                        <span class="badge badge-muted">Unknown</span>
                    <?php elseif ($status[0]): ?>
                        <span class="badge badge-error">End of life</span>
                    <?php else: ?>
                        <span class="badge badge-ok">Supported</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <script>
      $(function () {
        // One "Filter" button governs the engine dropdown and the text
        // search together (2026-09-02: neither applies live any more).
        var table = $('#db-versions').DataTable({
          order: [[1, 'desc']], pageLength: 50, dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>'
        });
        function apply() {
          table.column(0).search($('#db-engine-filter').val() || '');
          table.search($('#db-search').val() || '').draw();
        }
        $('#db-filter-btn').on('click', apply);
        $('#db-search').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
      });
    </script>
<?php endif; ?>
