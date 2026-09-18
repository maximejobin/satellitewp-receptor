<h1>Style guide</h1>
<p class="muted">Every reusable visual component in this app, in isolation.</p>
<?= notice('info', 'The extraction report (<span class="mono">/site/{id}/extraction/{id}</span>) is a deliberately '
    . '<strong>separate</strong> design system — its own <span class="mono">report.css</span>, scoped under '
    . '<span class="mono">.xt-report</span>. Not covered here on purpose.') ?>

<h2>Color tokens</h2>
<?php
$colorGroups = [
    'Text' => [
        ['text', 'primary text'],
        ['muted', 'secondary text'],
        ['faint', 'tertiary text, labels'],
        ['accent-2', 'links, hover text'],
        ['ok', 'success text'],
        ['warn', 'warning text'],
        ['error', 'error text'],
        ['error-2', 'critical text (on a dark fill)'],
        ['info', 'info text'],
    ],
    'Background' => [
        ['bg', 'page background'],
        ['surface', 'card / table background'],
        ['surface-2', 'subtle surface — hover, header row'],
        ['bg-ok', 'success wash'],
        ['bg-warn', 'warning wash'],
        ['bg-error', 'error wash'],
        ['bg-info', 'info wash'],
        ['bg-accent', 'accent wash'],
    ],
    'Border & accent' => [
        ['border', 'borders, dividers'],
        ['accent', 'primary action'],
    ],
];
$hex = [
    'text' => '#182430', 'muted' => '#64748b', 'faint' => '#93a2b3', 'accent-2' => '#d95c1c',
    'ok' => '#1f9d57', 'warn' => '#e07a1c', 'error' => '#d6453f', 'error-2' => '#8f1214', 'info' => '#2f7fe0',
    'bg' => '#eef1f4', 'surface' => '#ffffff', 'surface-2' => '#f5f7f9',
    'bg-ok' => '#cdefda', 'bg-warn' => '#fbf0d8', 'bg-error' => '#fbe5e4', 'bg-info' => '#e6f0fc', 'bg-accent' => '#fdece1',
    'border' => '#dde3e9', 'accent' => '#f26f2b',
];
?>
<?php foreach ($colorGroups as $groupName => $tokens): ?>
    <div class="sg-group">
        <div class="sg-group-title"><?= e($groupName) ?></div>
        <div class="sg-swatches">
            <?php foreach ($tokens as [$token, $desc]): ?>
                <div class="sg-swatch">
                    <span class="sg-swatch-color" style="background:var(--<?= e($token) ?>)"></span>
                    <span>
                        <span class="mono">--<?= e($token) ?></span>
                        <span class="sg-swatch-desc"> — <?= e($desc) ?> — <?= e($hex[$token]) ?></span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<h2>Buttons</h2>
<p class="muted">One class per level of emphasis — never a one-off <span class="mono">style="background:…"</span>
    on a <span class="mono">.btn</span>.</p>
<p class="sg-row">
    <button type="button" class="btn">.btn — primary</button>
    <button type="button" class="btn btn-secondary">.btn-secondary — Filter / Search</button>
    <button type="button" class="btn btn-muted">.btn-muted — Cancel / Suspend</button>
    <button type="button" class="btn btn-danger">.btn-danger — Revoke / Remove</button>
    <a class="google-btn" href="#" onclick="return false">
        <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
            <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z"/>
            <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z"/>
            <path fill="#FBBC05" d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707s.102-1.167.282-1.707V4.961H.957C.347 6.173 0 7.548 0 9s.348 2.827.957 4.039l3.007-2.332z"/>
            <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z"/>
        </svg>
        <span>.google-btn — sign-in only</span>
    </a>
</p>

<h2>Badges</h2>
<p class="muted"><span class="mono">badge()</span> — a small inline pill for one value in a table cell.</p>
<p class="sg-row">
    <?= badge('ok') ?> <?= badge('warn') ?> <?= badge('error') ?> <?= badge('unknown') ?>
    <span class="badge badge-critical">critical</span>
    <?= badge_score(96) ?> <?= badge_score(70) ?> <?= badge_score(30) ?>
</p>

<h2>Notices</h2>
<p class="muted"><span class="mono">notice()</span> — a page-level alert banner, not a value. Reach for this,
    never a stretched <span class="mono">.badge</span>, for something the reader should see before anything else.</p>
<?= notice('info', 'Informational — nothing needs to change.') ?>
<?= notice('warning', 'Warning — worth a look, not urgent.') ?>
<?= notice('critical', 'Critical — something is actually wrong.') ?>

<h2>Status dots</h2>
<p class="muted"><span class="mono">status_dot()</span> — a lighter alternative to <span class="mono">badge()</span>
    for a status column. Used on the Services table (<span class="mono">/clients/{id}</span>);
    <span class="mono">badge()</span> is still what the rest of the app uses for status.</p>
<p class="sg-row"><?= status_dot('active') ?> <?= status_dot('pending') ?> <?= status_dot('on-hold') ?> <?= status_dot('unknown') ?></p>

<h2>Pastilles</h2>
<p class="muted"><span class="mono">pastille()</span> — the extraction report's own findings signal. Official
    labels: info, pass, attention, critical, na.</p>
<p class="sg-row">
    <?= pastille('blue', 'info') ?> <?= pastille('green', 'pass') ?> <?= pastille('orange', 'attention') ?>
    <?= pastille('red', 'critical') ?> <?= pastille('grey', 'na') ?>
</p>

<h2>Cards</h2>
<p class="muted">Single-column <span class="mono">.card.info-card</span>.</p>
<?= section('Example card', implode('', [
    field('Label', 'Left-aligned value'),
    field('Status', true),
    field('Empty', null),
]), '<span class="badge badge-ok">optional badge</span>') ?>

<p class="muted" style="margin-top:1.4rem">Two-column <span class="mono">.kv-cols-2</span> — a real CSS Grid of two
    <span class="mono">&lt;table class="kv"&gt;</span>, not the single-table <span class="mono">section()</span>
    above (a browser will not fragment one table's rows across CSS columns — see the comment on
    <span class="mono">.kv-cols-2</span> in style.css). Used once today, the Client card on
    <span class="mono">/clients/{id}</span>.</p>
<section class="card info-card">
    <h3>Example two-column card</h3>
    <div class="kv-cols-2">
        <table class="kv"><tbody><?= field('Column 1', 'a') . field('Column 1', 'b') ?></tbody></table>
        <table class="kv"><tbody><?= field('Column 2', 'c') . field('Column 2', 'd') ?></tbody></table>
    </div>
</section>

<h2>Filter bar</h2>
<p class="muted">The one <span class="mono">.search</span> treatment used everywhere a page filters a list —
    icon-in-field search, chevron selects, squared corners.</p>
<form class="search" onsubmit="return false">
    <input type="search" placeholder="Search…">
    <select><option>All</option><option>Active</option></select>
    <button type="button" class="btn btn-secondary">Filter</button>
    <a href="#" class="search-reset" onclick="return false">Reset filter</a>
</form>

<h2>Filter bar — dropdown-as-tag concept</h2>
<?= notice('warning', '<strong>Proposal, not yet real</strong> — the plain <span class="mono">.search</span> above '
    . 'is still what every page actually uses. Explored here per the reference screenshot: a dropdown\'s own '
    . 'button always shows the filter\'s <em>name</em> (never the picked value, unlike a native '
    . '&lt;select&gt;), and a pick shows up as a removable tag below the search row instead — which needs a '
    . 'second row of vertical space the plain version above doesn\'t.') ?>
<div class="search-group">
    <form class="search" onsubmit="return false">
        <input type="search" placeholder="Search…">
        <div class="filter-dropdown" data-filter="status" data-label="Status">
            <button type="button" class="filter-dropdown-btn">
                Status
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="filter-dropdown-panel">
                <button type="button" data-value="active">Active</button>
                <button type="button" data-value="inactive">Inactive</button>
            </div>
        </div>
        <div class="filter-dropdown" data-filter="type" data-label="Type">
            <button type="button" class="filter-dropdown-btn">
                Type
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="filter-dropdown-panel">
                <button type="button" data-value="license">License</button>
                <button type="button" data-value="plan">Maintenance plan</button>
            </div>
        </div>
        <button type="button" class="btn btn-secondary">Filter</button>
        <a href="#" class="search-reset" id="sg-tags-reset">Reset filter</a>
    </form>
    <div class="filter-tags" id="sg-filter-tags"></div>
</div>
<script>
  (function () {
    var tagsContainer = document.getElementById('sg-filter-tags');

    function closeAllDropdowns(except) {
      document.querySelectorAll('.filter-dropdown.open').forEach(function (d) {
        if (d !== except) { d.classList.remove('open'); }
      });
    }

    function addOrReplaceTag(filterKey, label, text) {
      var existing = tagsContainer.querySelector('[data-filter="' + filterKey + '"]');
      if (existing) { existing.remove(); }
      var tag = document.createElement('span');
      tag.className = 'filter-tag';
      tag.dataset.filter = filterKey;
      tag.innerHTML = label + ': ' + text + ' <button type="button" aria-label="Remove ' + label + ' filter">&times;</button>';
      tag.querySelector('button').addEventListener('click', function () { tag.remove(); });
      tagsContainer.appendChild(tag);
    }

    document.querySelectorAll('.filter-dropdown').forEach(function (dropdown) {
      var btn = dropdown.querySelector('.filter-dropdown-btn');
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = dropdown.classList.contains('open');
        closeAllDropdowns();
        dropdown.classList.toggle('open', !isOpen);
      });
      dropdown.querySelectorAll('.filter-dropdown-panel button').forEach(function (opt) {
        opt.addEventListener('click', function () {
          addOrReplaceTag(dropdown.dataset.filter, dropdown.dataset.label, opt.textContent);
          dropdown.classList.remove('open');
        });
      });
    });
    document.addEventListener('click', function () { closeAllDropdowns(); });

    document.getElementById('sg-tags-reset').addEventListener('click', function (e) {
      e.preventDefault();
      tagsContainer.innerHTML = '';
    });
  })();
</script>

<h2>Toasts</h2>
<p class="muted">A transient confirmation after an AJAX save — auto-dismisses, bottom-right, never blocks
    the page. Same accent-bar language as <span class="mono">.notice</span>. <span class="mono">showToast()</span>
    is global (layout.php) and already wired into the one real AJAX save in the app —
    <span class="mono">license_select()</span>'s licence dropdown on /catalog, which used to <span class="mono">alert()</span>
    on failure.</p>
<p class="sg-row">
    <button type="button" class="btn btn-secondary" onclick="showToast('Saved successfully.', false)">Simulate a successful save</button>
    <button type="button" class="btn btn-muted" onclick="showToast('Could not save — try again.', true)">Simulate a failed save</button>
</p>

<h2>Icons</h2>
<p class="muted">Two proposed icons — <span class="mono">icon_edit()</span> and
    <span class="mono">icon_external_link()</span> in helpers.php — hand-drawn SVG, same convention as
    <span class="mono">report_icon()</span>. Not wired into any real page yet (every edit affordance today
    still uses a plain "✎" character; every external link is still plain text).</p>
<p class="sg-row">
    <span class="icon" style="font-size:1.3rem"><?= icon_edit() ?></span> <span class="mono">icon_edit()</span>
    &nbsp;&nbsp;
    <span class="icon" style="font-size:1.3rem"><?= icon_external_link() ?></span> <span class="mono">icon_external_link()</span>
</p>

<h2>Quiet secondary facts</h2>
<p class="muted"><span class="mono">.text-subtle</span> — a pale grey italic fact placed near a heading, not
    competing with real content. The tooltip is a real widget (Tippy.js, vendored, not the native
    <span class="mono">title=""</span> attribute this app uses everywhere else today).</p>
<p><span class="text-subtle" data-tippy-content="<?= e(date('Y-m-d H:i:s', strtotime('-15 minutes'))) ?>">Last sync: 15 minutes ago</span></p>

<h2>Forms</h2>
<p class="muted"><span class="mono">license_select()</span> — auto-saves on change via <span class="mono">fetch()</span>,
    one option per licence including <span class="mono">custom</span>.</p>
<p><?= license_select('plugin', 'example-plugin', 'unknown', $csrf, '/styleguide', 'free') ?></p>

<h2>Tables</h2>
<p class="muted">Plain table (no Datatable) — used for short, unpaginated lists like Users.</p>
<table>
    <thead><tr><th>Column</th><th>Column</th></tr></thead>
    <tbody><tr><td>Value</td><td>Value</td></tr></tbody>
</table>

<p class="muted" style="margin-top:1.4rem">Datatable, client-side, with the explicit search box every table uses
    (never a live filter on keystroke), the length dropdown and pagination — all three are DataTables' own
    generated markup, styled by their stable class names.</p>
<div class="search-group">
<p class="search">
    <input type="search" class="xt-dt-search" data-table="#sg-table" placeholder="Search…">
    <button type="button" class="btn btn-secondary xt-dt-search-btn" data-table="#sg-table">Filter</button>
    <a href="#" class="search-reset" id="sg-table-reset">Reset filter</a>
</p>
<table id="sg-table" class="display" style="width:100%">
    <thead><tr><th>Row</th><th>Value</th></tr></thead>
    <tbody>
        <tr><td>One</td><td>Alpha</td></tr>
        <tr><td>Two</td><td>Bravo</td></tr>
        <tr><td>Three</td><td>Charlie</td></tr>
    </tbody>
</table>
</div>
<script>
  $(function () {
    var dt = $('#sg-table').DataTable({ pageLength: 10, dom: '<"xt-dt-top">rt<"xt-dt-bottom"lip>' });
    initExplicitSearch('#sg-table', dt);
    document.getElementById('sg-table-reset').addEventListener('click', function (e) {
      e.preventDefault();
      document.querySelector('.xt-dt-search[data-table="#sg-table"]').value = '';
      dt.search('').draw();
    });
  });
</script>

<h2>select2</h2>
<p class="muted">Plain (no AJAX) and AJAX-backed — both share the same retheme (font, arrow, vertical centering).</p>
<p class="sg-row">
    <select class="js-select2" data-placeholder="Plain select2" style="width:16rem">
        <option></option>
        <option>Option one</option>
        <option>Option two</option>
    </select>
    <select class="js-select2" data-ajax-url="/clients/search" data-placeholder="AJAX select2 (clients)" style="width:16rem">
        <option></option>
    </select>
</p>
