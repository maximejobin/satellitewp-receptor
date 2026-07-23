<h1>Sites</h1>

<form method="get" class="search">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="URL, nom ou site_id…">
    <button type="submit">Filtrer</button>
</form>

<?php if ($sites === []): ?>
    <p class="empty">Aucun site pour l’instant. Les sites apparaissent dès la première extraction reçue.</p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Site</th>
            <th>URL</th>
            <th>Dernière extraction</th>
            <th>Statut</th>
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
