<?php
/**
 * @var bool $available
 * @var string|null $refreshedAt
 */
?>
<h1>Vulnerabilities (Wordfence Intelligence)</h1>
<p class="muted">The full catalogue — not just what was detected on your sites — about
    84,000 known vulnerabilities across every plugin, theme and core. The search queries the
    server-side cache (the full file is never loaded into the browser) against slug, title or CVE;
    the result is always sorted by version descending. Column sort by click is not available here,
    since that order is imposed server-side.</p>

<?php if (!$available): ?>
    <p class="empty">The Wordfence Intelligence cache has not been refreshed yet
        (<span class="mono">bin/xtractor wordfence:refresh</span>).</p>
<?php else: ?>
    <p><?= fmt_refreshed($refreshedAt, 36 * 3600) ?></p>
    <p><?= dt_search_box('wf-vulnerabilities', 'Search slug, title or CVE…') ?></p>
    <table id="wf-vulnerabilities" class="display" style="width:100%">
        <thead>
        <tr><th>Slug</th><th>Type</th><th>CVE</th><th>Title</th><th>CVSS</th><th>Patched version</th></tr>
        </thead>
    </table>
    <script>
      $(function () {
        var dt = $('#wf-vulnerabilities').DataTable({
          ordering: false, // server streams the cache — see WordfenceIndex::search()
          serverSide: true,
          pageLength: 50,
          dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>',
          ajax: '/data/vulnerabilities/search',
        });
        initExplicitSearch('#wf-vulnerabilities', dt);
      });
    </script>
<?php endif; ?>
