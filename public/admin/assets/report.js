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

  // "Report data key" — mints a one-hour, single-extraction token server-side
  // (ExtractionController::reportToken()) and copies the ready-to-paste URL,
  // so the shared reports.api_key is never put on screen.
  var keyBtn = report.querySelector('#xt-report-key-btn');
  if (keyBtn) {
    keyBtn.addEventListener('click', function () {
      keyBtn.disabled = true;
      fetch(keyBtn.dataset.action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_csrf=' + encodeURIComponent(keyBtn.dataset.csrf)
      })
        .then(function (res) {
          if (!res.ok) { throw new Error('HTTP ' + res.status); }
          return res.json();
        })
        .then(function (data) { return navigator.clipboard.writeText(data.url); })
        .then(function () {
          if (window.showToast) { window.showToast('Report link copied — valid for one hour.', false); }
        })
        .catch(function () {
          if (window.showToast) { window.showToast('Could not create the report link.', true); }
        })
        .finally(function () { keyBtn.disabled = false; });
    });
  }
})();
