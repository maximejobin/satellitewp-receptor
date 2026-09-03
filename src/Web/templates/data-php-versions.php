<?php
/**
 * @var list<array<string, mixed>> $cycles
 * @var \SatelliteWP\Xtractor\Reference\EndOfLife $eol
 * @var string|null $refreshedAt
 */
?>
<h1>PHP versions</h1>
<p class="muted">Known PHP branches, from the local endoflife.date cache
    (<code>bin/xtractor reference:refresh</code>) — not the versions installed on tracked sites.
    Search, sort or filter with the search box; always sorted by version descending.</p>
<p><?= fmt_refreshed($refreshedAt, 2 * 3600) ?></p>

<?php if ($cycles === []): ?>
    <p class="empty">Cache empty: run <code>bin/xtractor reference:refresh</code> to fill it.</p>
<?php else: ?>
    <p><?= dt_search_box('php-versions') ?></p>
    <table id="php-versions" class="display" style="width:100%">
        <thead>
        <tr>
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
            $branch = (string) ($c['cycle'] ?? '');
            $status = $eol->eolStatus('php', $branch);
        ?>
            <tr>
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
        var dt = $('#php-versions').DataTable({ order: [[0, 'desc']], pageLength: 50, dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>' });
        initExplicitSearch('#php-versions', dt);
      });
    </script>
<?php endif; ?>
