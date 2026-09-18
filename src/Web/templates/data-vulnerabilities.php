<?php
/**
 * @var bool $available
 * @var string|null $refreshedAt
 */
?>
<h1>Vulnerabilities (Wordfence Intelligence)</h1>
<p class="muted">The full Wordfence Intelligence catalogue — not just what was detected on your
    sites. Search matches plugin/theme name, slug, title or CVE; click a column header to sort,
    defaults to most recently published first. Click a row's view icon to see everything Xtractor
    has cached for that vulnerability.</p>

<?php if (!$available): ?>
    <p class="empty">The Wordfence Intelligence cache has not been refreshed yet
        (<span class="mono">bin/xtractor wordfence:refresh</span>).</p>
<?php else: ?>
    <p class="muted">Last refreshed <?= fmt_relative_time($refreshedAt) ?>.</p>
    <div class="search-group">
    <p class="search"><?= dt_search_box('wf-vulnerabilities') ?></p>
    <table id="wf-vulnerabilities" class="display" style="width:100%">
        <thead>
        <tr>
            <th>Plugin</th><th></th><th>Type</th><th>CVE</th><th>Title</th>
            <th>Published</th><th>CVSS</th><th></th><th>Patched version</th><th></th><th></th>
        </tr>
        </thead>
    </table>
    </div>
    <dialog id="json-view-dialog" class="json-dialog">
        <div class="json-dialog-head">
            <strong id="json-view-title"></strong>
            <button type="button" class="json-dialog-close" aria-label="Close"
                    onclick="document.getElementById('json-view-dialog').close()">×</button>
        </div>
        <pre id="json-view-body" class="json-dialog-body"></pre>
    </dialog>
    <script>
      $(function () {
        var dt = $('#wf-vulnerabilities').DataTable({
          serverSide: true,
          pageLength: 50,
          order: [[5, 'desc']],
          dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>',
          ajax: '/data/vulnerabilities/search',
          columnDefs: [
            { targets: [1, 7, 9], visible: false, searchable: false },
            { targets: [8, 10], orderable: false },
            {
              targets: 0,
              render: function (data, type, row) {
                if (type !== 'display') { return data; }
                return '<div>' + xtEscapeHtml(data) + '</div>'
                  + '<div class="muted mono" style="font-size:.8rem">' + xtEscapeHtml(row[1]) + '</div>';
              }
            },
            {
              targets: 2,
              render: function (data, type) {
                if (type !== 'display') { return data; }
                var byType = { core: ['WP', 'WordPress core'], plugin: ['P', 'Plugin'], theme: ['T', 'Theme'] };
                var info = byType[data] || [xtEscapeHtml(String(data || '?')).toUpperCase(), data || 'Unknown'];
                return '<span class="badge badge-muted" title="' + xtEscapeHtml(info[1]) + '">' + info[0] + '</span>';
              }
            },
            {
              targets: 5,
              render: function (data, type) {
                if (type !== 'display') { return data === null ? '' : data; }
                if (data === null || data === undefined || data === '') { return '—'; }
                return xtEscapeHtml(String(data).slice(0, 10));
              }
            },
            {
              targets: 6,
              render: function (data, type, row) {
                if (type !== 'display') { return data === null ? '' : data; }
                if (data === null || data === undefined || data === '') { return '—'; }
                var score = parseFloat(data);
                var cls = score >= 9.0 ? 'badge-critical' : score >= 8.1 ? 'badge-error' : score >= 6.1 ? 'badge-warn' : 'badge-ok';
                var rating = row[7];
                var title = 'CVSS ' + score + (rating ? ' — ' + rating : '');
                return '<span class="badge ' + cls + '" title="' + xtEscapeHtml(title) + '">' + score + '</span>';
              }
            },
            {
              targets: 10,
              searchable: false,
              render: function (data, type) {
                if (type !== 'display') { return ''; }
                var eye = '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5" '
                  + 'stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 9S4 3.5 9 3.5 16.5 9 16.5 9 '
                  + '14 14.5 9 14.5 1.5 9 1.5 9Z"/><circle cx="9" cy="9" r="2.1"/></svg>';
                return '<button type="button" class="icon-btn json-view-btn" title="View raw JSON" aria-label="View raw JSON">' + eye + '</button>';
              }
            }
          ]
        });
        initExplicitSearch('#wf-vulnerabilities', dt);

        $('#wf-vulnerabilities tbody').on('click', '.json-view-btn', function () {
          var raw = dt.row($(this).closest('tr')).data()[9];
          document.getElementById('json-view-title').textContent =
            raw && raw.slug ? raw.slug + (raw.cve_id ? ' — ' + raw.cve_id : '') : 'Vulnerability';
          document.getElementById('json-view-body').textContent = JSON.stringify(raw, null, 2);
          document.getElementById('json-view-dialog').showModal();
        });
      });
    </script>
<?php endif; ?>
