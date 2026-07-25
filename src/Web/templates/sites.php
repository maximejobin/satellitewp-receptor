<?php /** @var \SatelliteWP\Xtractor\Rules\Translator $t */ ?>
<h1><?= e($t->ui('sites')) ?></h1>

<form method="get" class="search">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="URL, name or site_id…">
    <button type="submit"><?= e($t->ui('search')) ?></button>
</form>

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
                <td><?= e($site['last_seen']) ?></td>
                <td><?= badge($site['last_extraction_status']) ?></td>
                <td><?= e($site['extraction_count']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
