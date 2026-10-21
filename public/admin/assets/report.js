/* Extraction report — sticky nav scrollspy and print button. Scoped to
   elements under .xt-report; loaded only on that page (see layout.php,
   `reportAssets => true`). No dependency on jQuery/Datatables. */
(function () {
  var report = document.querySelector('.xt-report');
  if (!report) { return; }

  // Scrollspy: highlight the sticky nav entry for whichever group is in view
  // (IntersectionObserver, no scroll listener). Keyed by data-nav-target, not
  // id: the Overview link jumps to the hero while the block below it is what
  // the spy tracks as "Overview" — two elements, one nav entry.
  var nav = report.querySelector('.xt-nav');
  var groups = report.querySelectorAll('[data-nav-target]');
  if (nav && groups.length && 'IntersectionObserver' in window) {
    var links = {};
    nav.querySelectorAll('a[href^="#"]').forEach(function (a) {
      links[a.getAttribute('href').slice(1)] = a;
    });

    var current = null;
    var setActive = function (id) {
      if (id === current || !links[id]) { return; }
      if (current && links[current]) { links[current].classList.remove('on'); }
      links[id].classList.add('on');
      current = id;
    };

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          setActive(entry.target.getAttribute('data-nav-target'));
        }
      });
    }, { rootMargin: '-96px 0px -70% 0px', threshold: 0 });

    groups.forEach(function (el) { observer.observe(el); });
  }

  // Print: a plain window.print() — the @media print rules in report.css do
  // the rest (hide chrome, expand every <details>, avoid breaking cards).
  report.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      report.querySelectorAll('details').forEach(function (d) { d.open = true; });
      window.print();
    });
  });

  // "Report data key" and "AI bundle command" mint a one-hour, single-extraction
  // token server-side and copy what carries it, so the shared reports.api_key is
  // never put on screen.
  function copyFromPost(btn, field, done, failed) {
    if (!btn) { return; }
    btn.addEventListener('click', function () {
      btn.disabled = true;
      fetch(btn.dataset.action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_csrf=' + encodeURIComponent(btn.dataset.csrf)
      })
        .then(function (res) {
          if (!res.ok) { throw new Error('HTTP ' + res.status); }
          return res.json();
        })
        .then(function (data) { return navigator.clipboard.writeText(data[field]); })
        .then(function () {
          if (window.showToast) { window.showToast(done, false); }
        })
        .catch(function () {
          if (window.showToast) { window.showToast(failed, true); }
        })
        .finally(function () { btn.disabled = false; });
    });
  }

  copyFromPost(report.querySelector('#xt-report-key-btn'), 'url',
    'Report link copied — valid for one hour.', 'Could not create the report link.');
  copyFromPost(report.querySelector('#xt-bundle-btn'), 'command',
    'Bundle command copied — valid for one hour.', 'Could not create the bundle command.');
})();
