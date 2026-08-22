<?php
require_once dirname(__DIR__) . '/helpers.php';
/** @var \SatelliteWP\Xtractor\Rules\Translator $t */
$nav = $nav ?? 'sites';
$langQ = ($lang ?? 'en') === 'en' ? '' : '?lang=' . e($lang ?? 'en');
?>
<!DOCTYPE html>
<html lang="<?= e($lang ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Xtractor') ?> — SatelliteWP Xtractor</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="app">
    <aside class="side">
        <a class="brand" href="/<?= $langQ ?>"><b>SatelliteWP</b> Xtractor</a>

        <div class="nav-label">Monitoring</div>
        <a class="nav-item <?= $nav === 'sites' ? 'active' : '' ?>" href="/<?= $langQ ?>"><?= e($t->ui('sites')) ?></a>
        <a class="nav-item <?= $nav === 'catalog' ? 'active' : '' ?>" href="/catalog<?= $langQ ?>">Catalogue</a>

        <?php if (!empty($currentUser)): ?>
            <div class="nav-label">Compte</div>
            <a class="nav-item <?= $nav === 'users' ? 'active' : '' ?>" href="/users<?= $langQ ?>">Utilisateurs</a>
            <a class="nav-item" href="/auth/logout">Déconnexion</a>
        <?php endif; ?>

        <div class="side-foot">
            <?php if (!empty($currentUser)): ?>
                <div class="mono" style="overflow-wrap:anywhere"><?= e($currentUser) ?></div>
            <?php endif; ?>
            v1 · 69 rules · 14 sources
        </div>
    </aside>

    <div class="main">
        <div class="topbar">
            <div class="breadcrumb"><?= $breadcrumb ?? '' ?></div>
            <div class="spacer"></div>
            <div class="seg">
                <a class="<?= ($lang ?? 'en') === 'en' ? 'on' : '' ?>" href="?lang=en">EN</a>
                <a class="<?= ($lang ?? 'en') === 'fr' ? 'on' : '' ?>" href="?lang=fr">FR</a>
            </div>
            <button class="theme-btn" title="Toggle theme" aria-label="Toggle light/dark theme">◐</button>
        </div>

        <main class="content">
            <?php require $templateFile; ?>
        </main>
    </div>
</div>

<script>
  (function () {
    var root = document.documentElement;
    try { root.dataset.theme = localStorage.getItem('xt-theme') || 'light'; } catch (e) { root.dataset.theme = 'light'; }
    var btn = document.querySelector('.theme-btn');
    if (btn) btn.addEventListener('click', function () {
      var next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      try { localStorage.setItem('xt-theme', next); } catch (e) {}
    });

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
  })();
</script>
</body>
</html>
