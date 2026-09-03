<?php
require_once dirname(__DIR__) . '/helpers.php';
/** @var \SatelliteWP\Xtractor\Rules\Translator $t */
$nav = $nav ?? 'sites';
?>
<!DOCTYPE html>
<html lang="<?= e($lang ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Xtractor') ?> — SatelliteWP Xtractor</title>
    <link rel="stylesheet" href="/assets/style.css">
    <?php // jQuery is shared by dataTables and select2 — loaded once, before either. ?>
    <?php if (!empty($dataTables) || !empty($select2)): ?>
    <script src="/assets/vendor/jquery/jquery-3.7.1.min.js"></script>
    <?php endif; ?>
    <?php if (!empty($dataTables)): ?>
    <link rel="stylesheet" href="/assets/vendor/datatables/dataTables.dataTables.min.css">
    <script src="/assets/vendor/datatables/dataTables.min.js"></script>
    <?php endif; ?>
    <?php if (!empty($select2)): ?>
    <link rel="stylesheet" href="/assets/vendor/select2/select2.min.css">
    <script src="/assets/vendor/select2/select2.min.js"></script>
    <?php endif; ?>
    <?php if (!empty($reportAssets)): ?>
    <link rel="stylesheet" href="/assets/report.css">
    <?php endif; ?>
</head>
<body>
<div class="app">
    <aside class="side">
        <a class="brand" href="/"><b>SatelliteWP</b> Xtractor</a>

        <!-- No label here on purpose (2026-09-02, user: "Retirer le label
             'CRM'") — these four sibling entities from the external CRM
             database open the sidebar unlabeled, ahead of every other
             section; none is nested under another (flat URLs: /clients,
             /websites, /products, /items) — the business identifies a
             website first and finds its client after, never the reverse. -->
        <a class="nav-item <?= $nav === 'crm-clients' ? 'active' : '' ?>" href="/clients">Clients</a>
        <a class="nav-item <?= $nav === 'crm-websites' ? 'active' : '' ?>" href="/websites">Websites</a>
        <a class="nav-item <?= $nav === 'crm-items' ? 'active' : '' ?>" href="/items">Items</a>
        <a class="nav-item <?= $nav === 'crm-products' ? 'active' : '' ?>" href="/products">Products</a>

        <div class="nav-label">Receptor</div>
        <a class="nav-item <?= $nav === 'sites' ? 'active' : '' ?>" href="/"><?= e($t->ui('sites')) ?></a>
        <a class="nav-item <?= $nav === 'catalog' ? 'active' : '' ?>" href="/catalog">Catalogue</a>

        <div class="nav-label">Data</div>
        <a class="nav-item <?= $nav === 'data-wp-versions' ? 'active' : '' ?>" href="/data/wp-versions">WordPress versions</a>
        <a class="nav-item <?= $nav === 'data-php-versions' ? 'active' : '' ?>" href="/data/php-versions">PHP versions</a>
        <a class="nav-item <?= $nav === 'data-databases' ? 'active' : '' ?>" href="/data/databases">Databases</a>
        <a class="nav-item <?= $nav === 'data-vulnerabilities' ? 'active' : '' ?>" href="/data/vulnerabilities">Vulnerabilities</a>

        <?php // "Users" is always visible, like every other nav item — "is
              // someone currently signed in" gates nothing real (the page
              // itself already does: read-only unless $isAdmin, mutations
              // blocked server-side regardless of nav visibility), so hiding
              // the link only when auth isn't configured yet made the page
              // undiscoverable for no actual security gain (2026-09-02, user:
              // "si on validait une capacity précise, je ne dis pas... mais
              // valider qu'on est un user, ça sert à quoi?"). "Sign out" is
              // the one item here that genuinely has nothing to do when
              // nobody is signed in, so it alone stays conditional. ?>
        <div class="nav-label">Management</div>
        <a class="nav-item <?= $nav === 'users' ? 'active' : '' ?>" href="/users">Users</a>
        <?php if (!empty($currentUser)): ?>
            <a class="nav-item" href="/auth/logout">Sign out</a>
        <?php endif; ?>

        <div class="side-foot">
            <?php if (!empty($currentUser)): ?>
                <div class="mono" style="overflow-wrap:anywhere"><?= e($currentUser) ?></div>
            <?php endif; ?>
            <?= e($appVersion ?? '') ?>
        </div>
    </aside>

    <div class="main">
        <main class="content">
            <?php require $templateFile; ?>
        </main>
    </div>
</div>

<script>
  (function () {
    var bar = document.querySelector('.filt');
    if (bar) bar.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      bar.querySelectorAll('button').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      var f = b.dataset.filter;
      document.querySelectorAll('.frow').forEach(function (r) {
        var show = f === 'all' || (f === 'attn' && r.dataset.attn === '1') || r.dataset.cat === f;
        r.style.display = show ? '' : 'none';
      });
    });

    // Licence dropdowns (catalog + per-plugin on an extraction report): save
    // via fetch() instead of a real form submit, so picking a licence for
    // one of a hundred plugins does not reload the whole page every time.
    // The form/action/CSRF are untouched — this is the same POST /catalog a
    // no-JS submit would make, just not navigated to.
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form.classList.contains('lic-form')) { return; }
      e.preventDefault();
      var select = form.querySelector('select[name="license"]');
      var previous = select ? select.className : '';
      // Build the body BEFORE disabling the select: a disabled form control
      // is excluded from FormData (same rule as a real submit), so disabling
      // first silently sent every save with no "license" field at all — the
      // server no-opped and still redirected, which read as success below.
      var body = new FormData(form);
      if (select) { select.className = 'lic-' + select.value; select.disabled = true; }
      // redirect: 'manual' — Router 303s back after a successful save;
      // following it would just download the whole page again for nothing,
      // since the response body is never read here. A rejected save now
      // comes back as a real error status instead of also 303ing, so this
      // opaqueredirect/ok check reflects whether anything was actually saved.
      fetch(form.action, { method: 'POST', body: body, redirect: 'manual' })
        .then(function (r) {
          if (!select) { return; }
          select.disabled = false;
          if (r.type === 'opaqueredirect' || r.ok) {
            select.classList.add('lic-saved');
            setTimeout(function () { select.classList.remove('lic-saved'); }, 1000);
          } else {
            select.className = previous;
            alert('Could not save the licence — try again.');
          }
        })
        .catch(function () {
          if (select) { select.disabled = false; select.className = previous; }
          alert('Could not save the licence — try again.');
        });
    });
  })();

  // Every Datatable's search now requires an explicit click (or Enter),
  // never a live filter on keystroke (2026-09-02, user: "on devra
  // absolument cliquer sur 'Search'... " — confirmed to cover the native
  // quick-search box too, not just a page's own filter-bar controls). Each
  // table is initialized with `dom` excluding 'f' so DataTables never
  // renders its own instant box; helpers.php's dt_search_box() renders the
  // replacement input+button, matched to the table by `data-table`. The
  // *feature* (DataTables' own .search()/.draw()) is unchanged — only the
  // trigger is.
  function initExplicitSearch(tableId, dt) {
    var $input = jQuery('.xt-dt-search[data-table="' + tableId + '"]');
    var $btn   = jQuery('.xt-dt-search-btn[data-table="' + tableId + '"]');
    function apply() { dt.search($input.val() || '').draw(); }
    $btn.on('click', apply);
    $input.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
  }

  // Searchable dropdowns for any select whose real option count (a client
  // out of 315, a website out of ~100) makes preloading everything into the
  // page wasteful, not just unusable past ~20 entries: a select carrying
  // data-ajax-url searches that endpoint server-side as the operator types
  // (see ClientsRepository::searchClients() et al.) instead of filtering a
  // list already sitting in the DOM — only the *currently selected* option,
  // if any, is ever rendered server-side. A select with data-ajax-url absent
  // still gets plain client-side search over its own options (none
  // currently in this app, but the fallback costs nothing to keep).
  // dropdownParent: body — several of these selects live inside a <table>,
  // and `table { overflow: hidden }` (style.css, for the rounded corners)
  // would otherwise clip the dropdown to the row/cell it opens from.
  function initSelect2($el) {
    var ajaxUrl = $el.data('ajax-url');
    var options = {
      width: '18rem',
      dropdownParent: jQuery(document.body),
      placeholder: $el.data('placeholder') || null,
      allowClear: !!$el.data('placeholder')
    };
    if (ajaxUrl) {
      options.ajax = {
        url: ajaxUrl,
        dataType: 'json',
        delay: 200,
        data: function (params) { return { q: params.term || '' }; }
      };
      // Show an initial set of results (alphabetical) as soon as the
      // dropdown opens, rather than requiring the first keystroke before
      // anything appears.
      options.minimumInputLength = 0;
    }
    $el.select2(options);
  }

  if (window.jQuery && jQuery.fn.select2) {
    jQuery('.js-select2').each(function () { initSelect2(jQuery(this)); });
  }

  // "Linked website" on a subscription (subscription_website_form()): shown
  // as plain text by default, an edit icon reveals the dropdown (2026-09-02,
  // user: "je voudrais que ce soit seulement afficher... avoir un icône pour
  // edit"). select2 is initialized lazily, the first time it is revealed —
  // NOT eagerly with the rest above (this select deliberately carries
  // .wf-select, not .js-select2, so the loop above skips it): initializing
  // select2 on a display:none element is a well-known source of a
  // width/position miscalculation, since it can't measure a hidden element.
  // Toggling uses element.style.display directly, on both elements, rather
  // than the `hidden` attribute — an inline `style="display:flex"` on the
  // same element as `hidden` would silently defeat it (equal CSS specificity,
  // so the actual winner would depend on stylesheet source order — fragile).
  document.addEventListener('click', function (e) {
    var editBtn = e.target.closest('.wf-edit-btn');
    if (editBtn) {
      var id = editBtn.dataset.wfId;
      var display = document.querySelector('.wf-display[data-wf-id="' + id + '"]');
      var form = document.querySelector('.wf-edit-form[data-wf-id="' + id + '"]');
      if (display) { display.style.display = 'none'; }
      if (form) {
        form.style.display = 'flex';
        if (window.jQuery) {
          var $select = jQuery(form).find('.wf-select');
          if ($select.length && !$select.hasClass('select2-hidden-accessible')) {
            $select.data('wf-original', $select.val());
            initSelect2($select);
          }
        }
      }
      return;
    }
    var cancelBtn = e.target.closest('.wf-cancel-btn');
    if (cancelBtn) {
      var form2 = cancelBtn.closest('.wf-edit-form');
      var id2 = form2.dataset.wfId;
      if (window.jQuery) {
        // Discard any unsaved pick: without this, reopening later (without
        // ever saving or reloading) would show the abandoned choice as if
        // it might be the real linked website.
        var $select2 = jQuery(form2).find('.wf-select');
        if ($select2.length) { $select2.val($select2.data('wf-original')).trigger('change'); }
      }
      form2.style.display = 'none';
      var display2 = document.querySelector('.wf-display[data-wf-id="' + id2 + '"]');
      if (display2) { display2.style.display = ''; }
      return;
    }

    // The simpler cousin of the above, no select2 involved (Users list:
    // edit name/email/role inline) — same display:none-based toggle, same
    // reasoning against the `hidden` attribute. Both rows are real <tr>s
    // (a colspan'd <form> in the edit row), so 'table-row' is the display
    // value that shows one — not 'flex', which is right for the subscription
    // form above but wrong for a table row.
    var rowEditBtn = e.target.closest('.row-edit-btn');
    if (rowEditBtn) {
      var rid = rowEditBtn.dataset.rowId;
      var rdisplay = document.querySelector('.row-display[data-row-id="' + rid + '"]');
      var rform = document.querySelector('.row-edit-form[data-row-id="' + rid + '"]');
      if (rdisplay) { rdisplay.style.display = 'none'; }
      if (rform) { rform.style.display = 'table-row'; }
      return;
    }
    var rowCancelBtn = e.target.closest('.row-cancel-btn');
    if (rowCancelBtn) {
      var rform2 = rowCancelBtn.closest('.row-edit-form');
      var rid2 = rform2.dataset.rowId;
      rform2.style.display = 'none';
      var rdisplay2 = document.querySelector('.row-display[data-row-id="' + rid2 + '"]');
      if (rdisplay2) { rdisplay2.style.display = ''; }
    }
  });
</script>
<?php if (!empty($reportAssets)): ?>
<script src="/assets/report.js"></script>
<?php endif; ?>
</body>
</html>
