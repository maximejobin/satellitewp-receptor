<?php /** @var \SatelliteWP\Xtractor\Rules\Translator $t */ ?>
<h1><?= e($t->ui('sites')) ?></h1>

<form method="get" class="search">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="URL, name or site_id…">
    <button type="submit"><?= e($t->ui('search')) ?></button>
</form>

<?php
$notices = [
    'invalid-uuid' => ['badge-error', 'The site identifier must be a valid UUID.'],
];
if (isset($notices[$notice ?? ''])):
    [$cls, $text] = $notices[$notice];
?>
    <p><span class="badge <?= $cls ?>"><?= e($text) ?></span></p>
<?php endif; ?>

<details style="margin:.2rem 0 1rem">
    <summary class="muted" style="cursor:pointer">Pair a new site</summary>
    <div style="margin-top:.6rem">
        <p class="muted" style="font-size:.85rem;max-width:46rem">
            A site must be paired before the receptor accepts its pushes. On the site,
            <b>Settings → SatelliteWP</b> shows its "Site identifier" — a UUID the plugin
            generates itself on first load. Paste that same UUID here to create its key
            (shown once, on the site's own page next).
        </p>
        <form method="post" action="/keys">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <input type="text" name="site_id" required placeholder="Site UUID (from Settings → SatelliteWP)"
                   pattern="[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}"
                   style="padding:.45rem .6rem;min-width:24rem;font:inherit">
            <input type="text" name="origin" placeholder="Origin (optional) — e.g. https://client.example.com"
                   style="padding:.45rem .6rem;min-width:20rem;font:inherit">
            <button type="submit" class="btn">Create key</button>
        </form>
    </div>
</details>

<?php if ($sites === []): ?>
    <p class="empty">No site yet. A site appears as soon as its first extraction is received.</p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Site</th>
            <th>URL</th>
            <th>Last extraction</th>
            <th><?= e($t->ui('status')) ?></th>
            <th>Extractions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($sites as $site): ?>
            <tr>
                <td>
                    <a href="/site/<?= e($site['site_id']) ?>"><?= e($site['name'] ?: $site['site_id']) ?></a>
                    <div class="muted mono"><?= e($site['site_id']) ?></div>
                </td>
                <td><?= e($site['site_url']) ?></td>
                <td><?= e($site['last_extraction_received_at'] ?? '—') ?></td>
                <td><?= badge($site['last_extraction_status']) ?></td>
                <td><?= e($site['extraction_count']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
