<?php
/**
 * @var list<array{version: string, branch: string, status: string, branchReleased: string|null}> $rows
 * @var string|null $refreshedAt
 * @var string|null $eolRefreshedAt
 */
?>
<h1>WordPress versions</h1>
<p class="muted">Every explicit version known to wordpress.org — not what's installed on tracked sites.</p>
<p><?= fmt_refreshed($refreshedAt, 2 * 3600, 'Last WP sync', 'Source: WordPress.org repository') ?></p>
<p><?= fmt_refreshed($eolRefreshedAt, 2 * 3600, 'Last EOL sync', 'Source: endoflife.date') ?></p>

<?php if ($rows === []): ?>
    <p class="empty">Cache empty: run <code>bin/xtractor reference:refresh</code> to fill it.</p>
<?php else: ?>
    <div class="search-group">
    <p class="search"><?= dt_search_box('wp-versions') ?></p>
    <table id="wp-versions" class="display" style="width:100%">
        <thead>
        <tr>
            <th>Version</th>
            <th>Branch</th>
            <th>Status</th>
            <th>Branch released</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="mono"><?= e($r['version']) ?></td>
                <td class="mono"><?= e($r['branch']) ?></td>
                <td>
                    <?php if ($r['status'] === 'unsecure'): ?>
                        <span class="badge badge-error">Insecure</span>
                    <?php elseif ($r['status'] === 'uptodate'): ?>
                        <span class="badge badge-ok">Up to date</span>
                    <?php else: ?>
                        <span class="badge badge-warn">Outdated</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= e($r['branchReleased'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <script>
      $(function () {
        var dt = $('#wp-versions').DataTable({
          order: [[0, 'desc']],
          pageLength: 50,
          dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>',
          // "Search by version only" — every other column is excluded from
          // the global search box (an explicit list, not '_all' + an
          // override, which is order-of-application-fragile).
          columnDefs: [{ targets: [1, 2, 3], searchable: false }]
        });
        initExplicitSearch('#wp-versions', dt);
      });
    </script>
<?php endif; ?>
