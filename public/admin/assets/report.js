/* Extraction report — sticky nav scrollspy, animated health ring, print
   button. Scoped to elements under .xt-report; loaded only on that page
   (see layout.php, `reportAssets => true`). No dependency on jQuery/Datatables. */
(function () {
  var report = document.querySelector('.xt-report');
  if (!report) { return; }

  // Animate the health ring fill-in from 0 to its real value on first paint.
  // `--p` is a custom property; without @property CSS treats it as a plain
  // string and cannot tween it, so this still works (the ring simply jumps to
  // its final value instead of sweeping) on a browser that ignores @property.
  var ring = report.querySelector('.xt-hero-ring');
  if (ring) {
    var target = ring.getAttribute('data-score');
    if (target !== null) {
      ring.style.setProperty('--p', 0);
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
          ring.style.setProperty('--p', target);
        });
      });
    }
  }

  // Scrollspy: highlight the sticky nav entry for whichever group is
  // currently in view. IntersectionObserver only — no scroll listener.
  // Keyed by `data-nav-target`'s *value*, not the element's own id: the
  // Overview nav link has to jump to the hero (id="overview", so visiting
  // #overview or clicking Overview never scrolls the hero itself out of
  // view above the fold — that used to be the actual bug), while scrollspy
  // still needs to track the KPI/findings block below it as "Overview" once
  // the user has scrolled past the hero. Two different elements, one nav
  // entry — hence the explicit attribute value instead of matching on id.
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
})();
