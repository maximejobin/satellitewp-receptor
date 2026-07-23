<?php require_once dirname(__DIR__) . '/helpers.php'; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Xtractor') ?> — SatelliteWP Xtractor</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">SatelliteWP <strong>Xtractor</strong></a>
</header>
<main>
    <?php require $templateFile; ?>
</main>
</body>
</html>
