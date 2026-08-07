<?php
/** @var list<array<string, mixed>> $entries */
use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
?>
<nav class="breadcrumb"><a href="/"><?= e($t->ui('sites')) ?></a> › Software catalogue</nav>

<h1>Software catalogue</h1>
<p class="muted">Every plugin and theme seen across extractions, with its licensing.
    <strong>premium</strong> and <strong>mixed</strong> likely need a paid licence.</p>

<p class="filters">
    <a class="<?= !$needsOnly ? 'active' : '' ?>" href="/catalog">All</a> ·
    <a class="<?= $needsOnly ? 'active' : '' ?>" href="/catalog?needs=1">Needs licence</a>
</p>

<?php if ($entries === []): ?>
    <p class="empty">Catalogue is empty — process an extraction first.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Type</th><th>Slug</th><th>Name</th><th>Licence</th><th>Repo</th><th>Seen</th></tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $e): ?>
            <?php
            $effective = SoftwareCatalog::effectiveLicense($e);
            $isSuggested = ($e['license'] ?? 'unknown') === 'unknown' && $effective !== 'unknown';
            ?>
            <tr class="<?= SoftwareCatalog::needsLicense($e) ? 'needs-license' : '' ?>">
                <td><?= e($e['type']) ?></td>
                <td class="mono"><?= e($e['slug']) ?></td>
                <td><?= e($e['name']) ?></td>
                <td><?= license_select(
                    (string) $e['type'],
                    (string) $e['slug'],
                    (string) ($e['license'] ?? 'unknown'),
                    $csrf,
                    '/catalog' . ($needsOnly ? '?needs=1' : ''),
                    $e['suggested'] ?? null
                ) ?></td>
                <td class="muted"><?= e($e['source'] ?? 'unknown') ?></td>
                <td><?= e($e['seen_count'] ?? 0) ?> · <span class="muted"><?= e($e['last_seen'] ?? '') ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted"><?= count($entries) ?> entries. Set a licence from the CLI:
        <code>xtractor catalog:set &lt;plugin|theme&gt; &lt;slug&gt; &lt;free|premium|mixed&gt;</code></p>
<?php endif; ?>
