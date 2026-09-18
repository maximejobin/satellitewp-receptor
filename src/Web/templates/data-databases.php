<?php
/**
 * @var list<array<string, mixed>> $cycles
 * @var \SatelliteWP\Xtractor\Reference\EndOfLife $eol
 * @var string|null $mysqlRefreshedAt
 * @var string|null $mariadbRefreshedAt
 */
?>
<h1>Databases</h1>
<p class="muted">Known MySQL and MariaDB branches — not the engines installed on tracked sites.</p>
<p><?= fmt_refreshed($mysqlRefreshedAt, 2 * 3600, 'Last MySQL sync', 'Source: endoflife.date') ?></p>
<p><?= fmt_refreshed($mariadbRefreshedAt, 2 * 3600, 'Last MariaDB sync', 'Source: endoflife.date') ?></p>

<?php if ($cycles === []): ?>
    <p class="empty">Cache empty: run <code>bin/xtractor reference:refresh</code> to fill it.</p>
<?php else: ?>
    <div class="search-group">
    <form class="search" onsubmit="return false">
        <input type="search" id="db-search" placeholder="Search…">
        <select id="db-engine-filter" class="js-filter-dropdown" data-label="Engine">
            <option value="">All engines</option>
            <option value="mysql">MySQL</option>
            <option value="mariadb">MariaDB</option>
        </select>
        <button type="button" class="btn btn-secondary js-apply-filters" id="db-filter-btn">Filter</button>
    </form>
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
    </div>
    <script>
      $(function () {
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
