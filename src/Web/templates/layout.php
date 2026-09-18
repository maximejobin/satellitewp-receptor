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
    <script>
      (function () {
        var collator = new Intl.Collator('fr', { sensitivity: 'base', numeric: true });
        var stripHtml = function (value) {
          if (value === null || value === undefined) { return ''; }
          return String(value).replace(/<[^>]*>/g, '').trim();
        };
        ['string', 'html'].forEach(function (type) {
          $.fn.dataTable.ext.type.order[type + '-pre']  = stripHtml;
          $.fn.dataTable.ext.type.order[type + '-asc']  = function (a, b) { return collator.compare(a, b); };
          $.fn.dataTable.ext.type.order[type + '-desc'] = function (a, b) { return collator.compare(b, a); };
        });
      })();
    </script>
    <?php endif; ?>
    <?php if (!empty($select2)): ?>
    <link rel="stylesheet" href="/assets/vendor/select2/select2.min.css">
    <script src="/assets/vendor/select2/select2.min.js"></script>
    <?php endif; ?>
    <?php if (!empty($tooltip)): ?>
    <link rel="stylesheet" href="/assets/vendor/tippy/tippy.css">
    <link rel="stylesheet" href="/assets/vendor/tippy/light-border.css">
    <script src="/assets/vendor/tippy/tippy-bundle.umd.min.js"></script>
    <?php endif; ?>
    <?php if (!empty($reportAssets)): ?>
    <link rel="stylesheet" href="/assets/report.css">
    <?php endif; ?>
</head>
<body>
<?php if (!empty($bare)): ?>
<div class="app-bare">
    <?php require $templateFile; ?>
</div>
<?php else: ?>
<div class="app">
    <div class="mobile-topbar">
        <button type="button" class="nav-toggle" id="navToggle" aria-label="Menu" aria-expanded="false" aria-controls="sideNav">
            <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <line x1="2" y1="5" x2="16" y2="5"/><line x1="2" y1="9" x2="16" y2="9"/><line x1="2" y1="13" x2="16" y2="13"/>
            </svg>
        </button>
        <a class="brand" href="/"><b>SatelliteWP</b> Xtractor</a>
    </div>
    <div class="nav-backdrop" id="navBackdrop"></div>
    <aside class="side" id="sideNav">
        <a class="brand" href="/"><b>SatelliteWP</b> Xtractor</a>

        <a class="nav-item <?= $nav === 'status' ? 'active' : '' ?>" href="/status">Status</a>

        <a class="nav-item <?= $nav === 'crm-clients' ? 'active' : '' ?>" href="/clients">Clients</a>
        <a class="nav-item <?= $nav === 'crm-websites' ? 'active' : '' ?>" href="/websites">Websites</a>
        <a class="nav-item <?= $nav === 'crm-items' ? 'active' : '' ?>" href="/items">Items</a>
        <a class="nav-item <?= $nav === 'crm-products' ? 'active' : '' ?>" href="/products">Products</a>

        <div class="nav-label">Receptor</div>
        <a class="nav-item <?= $nav === 'sites' ? 'active' : '' ?>" href="/extractions"><?= e($t->ui('sites')) ?></a>
        <a class="nav-item <?= $nav === 'catalog' ? 'active' : '' ?>" href="/catalog">Catalogue</a>

        <div class="nav-label">Data</div>
        <a class="nav-item <?= $nav === 'data-wp-versions' ? 'active' : '' ?>" href="/data/wp-versions">WordPress versions</a>
        <a class="nav-item <?= $nav === 'data-php-versions' ? 'active' : '' ?>" href="/data/php-versions">PHP versions</a>
        <a class="nav-item <?= $nav === 'data-databases' ? 'active' : '' ?>" href="/data/databases">Databases</a>
        <a class="nav-item <?= $nav === 'data-vulnerabilities' ? 'active' : '' ?>" href="/data/vulnerabilities">Vulnerabilities</a>

        <div class="nav-label">Management</div>
        <a class="nav-item <?= $nav === 'users' ? 'active' : '' ?>" href="/users">Users</a>
        <a class="nav-item <?= $nav === 'styleguide' ? 'active' : '' ?>" href="/styleguide">Style guide</a>
        <?php // Unlike "Users" above, this one genuinely has nothing to show
              // without an identity (profilePage() 404s — there is no
              // per-user account under Basic auth/the open dev fallback),
              // so it stays conditional, same reasoning as "Sign out". ?>
        <?php if (!empty($currentUser)): ?>
            <a class="nav-item <?= $nav === 'profile' ? 'active' : '' ?>" href="/profile">My profile</a>
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
<?php endif; ?>

<div class="toast-container" id="app-toast-container"></div>
<script>
  function showToast(message, isError) {
    var checkIcon = '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="7.2"/><polyline points="5.5 9.2 8 11.7 12.7 6.5"/></svg>';
    var errorIcon = '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="7.2"/><line x1="6.6" y1="6.6" x2="11.4" y2="11.4"/><line x1="11.4" y1="6.6" x2="6.6" y2="11.4"/></svg>';
    var container = document.getElementById('app-toast-container');
    var toast = document.createElement('div');
    toast.className = 'toast' + (isError ? ' toast-error' : '');
    toast.innerHTML = '<span class="icon">' + (isError ? errorIcon : checkIcon) + '</span><span>' + message + '</span>';
    container.appendChild(toast);
    setTimeout(function () {
      toast.style.opacity = '0';
      setTimeout(function () { toast.remove(); }, 200);
    }, 3000);
  }

  (function () {
    var toggle = document.getElementById('navToggle');
    var side = document.getElementById('sideNav');
    var backdrop = document.getElementById('navBackdrop');
    if (!toggle || !side || !backdrop) { return; }

    function closeNav() {
      side.classList.remove('open');
      backdrop.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    }
    function openNav() {
      side.classList.add('open');
      backdrop.classList.add('open');
      toggle.setAttribute('aria-expanded', 'true');
    }
    toggle.addEventListener('click', function () {
      side.classList.contains('open') ? closeNav() : openNav();
    });
    backdrop.addEventListener('click', closeNav);
  })();

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
            showToast('Licence saved.', false);
          } else {
            select.className = previous;
            showToast('Could not save the licence — try again.', true);
          }
        })
        .catch(function () {
          if (select) { select.disabled = false; select.className = previous; }
          showToast('Could not save the licence — try again.', true);
        });
    });
  })();

  function xtEscapeHtml(value) {
    var d = document.createElement('div');
    d.textContent = value === null || value === undefined ? '' : String(value);
    return d.innerHTML;
  }

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

  if (window.tippy) {
    tippy('[data-tippy-content]', { theme: 'light-border', animation: 'fade' });
  }

  function initFilterDropdown(select) {
    var label = select.dataset.label || select.name || 'Filter';
    var emptyValue = select.dataset.emptyValue !== undefined ? select.dataset.emptyValue : '';

    var wrap = document.createElement('div');
    wrap.className = 'filter-dropdown';
    select.parentNode.insertBefore(wrap, select);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'filter-dropdown-btn';
    btn.innerHTML = label + ' <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
      + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
    wrap.appendChild(btn);

    var panel = document.createElement('div');
    panel.className = 'filter-dropdown-panel';
    Array.prototype.forEach.call(select.options, function (opt) {
      // The "All"/"Any" option itself isn't a pickable tag — removing the
      // tag is what already means that.
      if (opt.value === emptyValue) { return; }
      var optBtn = document.createElement('button');
      optBtn.type = 'button';
      optBtn.textContent = opt.textContent;
      optBtn.dataset.value = opt.value;
      panel.appendChild(optBtn);
    });
    wrap.appendChild(panel);
    wrap.appendChild(select);
    select.style.display = 'none';

    var form = select.closest('form');
    var tagsContainer = form.parentNode.querySelector('.filter-tags');
    if (!tagsContainer) {
      tagsContainer = document.createElement('div');
      tagsContainer.className = 'filter-tags';
      form.insertAdjacentElement('afterend', tagsContainer);
    }

    function syncTag() {
      var existing = tagsContainer.querySelector('[data-filter="' + select.name + '"]');
      if (existing) { existing.remove(); }
      panel.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
      if (select.value === emptyValue) { return; }
      var chosen = select.options[select.selectedIndex];
      var picked = panel.querySelector('[data-value="' + select.value + '"]');
      if (picked) { picked.classList.add('active'); }
      var tag = document.createElement('span');
      tag.className = 'filter-tag';
      tag.dataset.filter = select.name;
      tag.innerHTML = label + ': ' + (chosen ? chosen.textContent : select.value)
        + ' <button type="button" aria-label="Remove ' + label + ' filter">&times;</button>';
      tag.querySelector('button').addEventListener('click', function () {
        select.value = emptyValue;
        select.dispatchEvent(new Event('change'));
        tag.remove();
        var applyBtn = form.querySelector('.js-apply-filters');
        if (applyBtn) { applyBtn.click(); }
      });
      tagsContainer.appendChild(tag);
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = wrap.classList.contains('open');
      document.querySelectorAll('.filter-dropdown.open').forEach(function (d) { d.classList.remove('open'); });
      wrap.classList.toggle('open', !isOpen);
    });
    panel.querySelectorAll('button').forEach(function (optBtn) {
      optBtn.addEventListener('click', function () {
        select.value = optBtn.dataset.value;
        select.dispatchEvent(new Event('change'));
        syncTag();
        wrap.classList.remove('open');
      });
    });

    syncTag(); // reflect whatever the select's initial value already is (e.g. a preselected ?status=… on load)
  }
  document.addEventListener('click', function () {
    document.querySelectorAll('.filter-dropdown.open').forEach(function (d) { d.classList.remove('open'); });
  });
  document.querySelectorAll('select.js-filter-dropdown').forEach(initFilterDropdown);

  function initTagFilter(root) {
    var btn    = root.querySelector('.filter-dropdown-btn');
    var panel  = root.querySelector('.filter-dropdown-panel');
    var field  = root.dataset.field; // e.g. 'tag' or 'excludeTag' -> submits as tag[]/excludeTag[]
    var isExcludeStyle = root.classList.contains('tag-filter-exclude');
    var hidden = document.createElement('div');
    hidden.style.display = 'none';
    root.appendChild(hidden);

    var form = root.closest('form');
    var tagsContainer = form.parentNode.querySelector('.filter-tags');
    if (!tagsContainer) {
      tagsContainer = document.createElement('div');
      tagsContainer.className = 'filter-tags';
      form.insertAdjacentElement('afterend', tagsContainer);
    }

    var selected = (root.dataset.selected || '').split(',').filter(function (t) { return t !== ''; });

    function sync() {
      hidden.innerHTML = '';
      tagsContainer.querySelectorAll('[data-tag-chip="' + root.id + '"]').forEach(function (el) { el.remove(); });
      panel.querySelectorAll('button').forEach(function (b) {
        b.classList.toggle('active', selected.indexOf(b.dataset.tag) !== -1);
      });
      selected.forEach(function (tag) {
        var input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = field + '[]';
        input.value = tag;
        hidden.appendChild(input);

        var chip = document.createElement('span');
        chip.className = 'filter-tag' + (isExcludeStyle ? ' filter-tag-excluded' : '');
        chip.dataset.tagChip = root.id;
        chip.innerHTML = '<span class="filter-tag-label">' + tag + '</span> '
          + '<button type="button" aria-label="Remove ' + tag + ' filter">&times;</button>';
        // Removing a chip is a complete action, same as the single-value
        // filter-dropdown tags above — applies immediately rather than
        // waiting for a separate "Filter" click.
        chip.querySelector('button').addEventListener('click', function () {
          selected = selected.filter(function (t) { return t !== tag; });
          sync();
          var applyBtn = form.querySelector('.js-apply-filters');
          if (applyBtn) { applyBtn.click(); }
        });
        tagsContainer.appendChild(chip);
      });
    }

    panel.querySelectorAll('button').forEach(function (optBtn) {
      optBtn.addEventListener('click', function () {
        var tag = optBtn.dataset.tag;
        var idx = selected.indexOf(tag);
        if (idx === -1) { selected.push(tag); } else { selected.splice(idx, 1); }
        sync();
        // Picking stays behind the page's own explicit "Filter" click (same
        // rule as every other filter control) — only removing a chip above
        // applies immediately. The panel stays open so several tags can be
        // picked in a row without reopening it each time.
      });
    });

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = root.classList.contains('open');
      document.querySelectorAll('.filter-dropdown.open').forEach(function (d) { d.classList.remove('open'); });
      root.classList.toggle('open', !isOpen);
    });

    sync(); // reflect whatever ?tag[]=/?excludeTag[]= the page already loaded with
  }
  document.querySelectorAll('.tag-filter').forEach(initTagFilter);

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
