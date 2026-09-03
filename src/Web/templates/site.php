<?php
/**
 * @var \SatelliteWP\Xtractor\Rules\Translator $t
 * @var array<string, mixed>|null $keyRow
 * @var array{site_id: string, key: string, origin: string|null}|null $createdKey
 */
?>
<h1><?= e($site['name'] ?? $siteId) ?></h1>
<?php if ($site === []): ?>
    <p class="pending-note">This site is paired but has not sent an extraction yet.</p>
<?php else: ?>
    <p>
        <a href="<?= e($site['site_url'] ?? '#') ?>" rel="noopener noreferrer"><?= e($site['site_url'] ?? '') ?></a>
    </p>
<?php endif; ?>

<?php if ($createdKey !== null): ?>
    <div class="pending-note" style="border-style:solid;border-color:var(--ok);background:var(--ok-bg);margin-bottom:.8rem">
        <b>Key created — copy it now, it will never be shown again:</b><br>
        <span class="mono" style="user-select:all;font-size:1rem"><?= e($createdKey['key']) ?></span><br>
        <span class="muted">Paste it into Settings → SatelliteWP → Pairing on the site
            (or <span class="mono">define('SWP_API_KEY', '…')</span> in wp-config.php).
            <?= $createdKey['origin'] !== null
                ? 'Bound to <span class="mono">' . e($createdKey['origin']) . '</span>.'
                : 'Not bound to an address yet: the first extraction received will bind it.' ?>
        </span>
    </div>
<?php endif; ?>

<h2>Extractions</h2>
<?php if ($extractions === []): ?>
    <p class="empty">No extraction.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Extraction</th><th>Received</th><th>WP</th><th>PHP</th><th><?= e($t->ui('status')) ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($extractions as $extraction): ?>
            <tr>
                <td><a class="mono" href="/site/<?= e($siteId) ?>/extraction/<?= e($extraction['id']) ?>"><?= e($extraction['id']) ?></a></td>
                <td><?= e($extraction['received_at']) ?></td>
                <td><?= e($extraction['wp_version']) ?></td>
                <td><?= e($extraction['php_version']) ?></td>
                <td><?= badge($extraction['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Recent events</h2>
<?php if ($events === []): ?>
    <p class="empty">No event received.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Timestamp (GMT)</th><th>Event</th><th>Actor</th><th>Details</th></tr>
        </thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><?= e($event['timestamp_gmt'] ?? $event['received_at'] ?? '') ?></td>
                <td><span class="badge badge-muted"><?= e($event['event'] ?? '?') ?></span></td>
                <td><?= e($event['actor_login'] ?? '') ?></td>
                <td class="muted">
                    <?php
                    $details = array_diff_key(
                        $event,
                        array_flip(['schema_version', 'event', 'actor_user_id', 'actor_login', 'timestamp_gmt', 'received_at'])
                    );
                    echo e(json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<details style="margin-top:1.5rem"<?= $createdKey !== null ? ' open' : '' ?>>
    <summary class="muted" style="cursor:pointer">⚙ Site settings (API key, address)</summary>
    <div style="margin-top:.8rem">
        <?php if ($keyRow !== null): ?>
            <?php $apiKey = (string) ($keyRow['api_key'] ?? ''); $revoked = !empty($keyRow['revoked']); ?>
            <table class="kv" style="max-width:40rem"><tbody>
                <tr><th>Key</th><td class="mono"><?= $apiKey !== '' ? e(substr($apiKey, 0, 6)) . '…' : '—' ?></td></tr>
                <tr><th>Origin</th><td class="mono"><?= e($keyRow['origin'] ?? '—') ?></td></tr>
                <tr><th>Created</th><td class="muted"><?= e($keyRow['created_at'] ?? '—') ?></td></tr>
                <tr><th>Status</th><td><?= $revoked
                        ? '<span class="badge badge-muted">revoked</span>'
                        : '<span class="badge badge-ok">active</span>' ?></td></tr>
            </tbody></table>

            <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:.7rem">
                <form method="post" action="/keys" style="margin:0;display:flex;gap:.4rem;align-items:center">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="rebind">
                    <input type="hidden" name="site_id" value="<?= e($siteId) ?>">
                    <input type="url" name="url" required placeholder="https://new-address.example.com"
                           style="padding:.35rem .5rem;font:inherit;min-width:18rem">
                    <button type="submit" class="btn" style="padding:.25rem .6rem;margin:0">Rebind</button>
                </form>
                <?php if (!$revoked): ?>
                    <form method="post" action="/keys" style="margin:0"
                          onsubmit="return confirm('Revoke this key? The site won\'t be able to send an extraction until a new key is created.')">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="revoke">
                        <input type="hidden" name="site_id" value="<?= e($siteId) ?>">
                        <button type="submit" class="btn" style="background:var(--error);margin:0;padding:.25rem .6rem">Revoke</button>
                    </form>
                <?php endif; ?>
            </div>
            <p class="muted" style="font-size:.85rem;margin-top:.7rem">
                Rotate the key by creating a new one below — it replaces this entry and reactivates it if revoked.
            </p>
        <?php else: ?>
            <p class="muted">No key registered for this site yet.</p>
        <?php endif; ?>

        <form method="post" action="/keys" style="margin-top:.3rem">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="site_id" value="<?= e($siteId) ?>">
            <input type="text" name="origin" placeholder="Origin (optional) — e.g. https://client.example.com"
                   style="padding:.45rem .6rem;min-width:20rem;font:inherit">
            <button type="submit" class="btn"><?= $keyRow === null ? 'Create key' : 'Create new key (rotate)' ?></button>
        </form>

        <?php if ($keyRow !== null): // needs an existing keys.json record to attach to — same
            // requirement as rebind/revoke above; a not-yet-paired site has nowhere to save this. ?>
            <?php $httpAuth = is_array($keyRow['http_auth'] ?? null) ? $keyRow['http_auth'] : null; ?>
            <hr style="margin:1.1rem 0;border:none;border-top:1px solid var(--border)">
            <p class="muted" style="font-size:.85rem;margin:0 0 .5rem">
                <b>HTTP Basic Auth for probing</b> — only needed when this site itself
                sits behind Basic Auth (a staging environment, an IP-restriction
                bypass, …). Without it, a probe cannot tell "not exposed" from
                "couldn't check because the whole site answers 401" — every
                security check on the report will show as <i>not checked</i>
                instead of pass/fail until this is set.
            </p>
            <?php if ($httpAuth !== null): ?>
                <p style="margin:0 0 .5rem">
                    <span class="badge badge-ok">configured</span>
                    <span class="mono muted">user: <?= e($httpAuth['username'] ?? '') ?></span>
                </p>
                <form method="post" action="/keys" style="margin:0"
                      onsubmit="return confirm('Remove the stored Basic Auth credentials for this site?')">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="http_auth_clear">
                    <input type="hidden" name="site_id" value="<?= e($siteId) ?>">
                    <button type="submit" class="btn" style="background:var(--error);padding:.25rem .6rem">Remove</button>
                </form>
            <?php else: ?>
                <form method="post" action="/keys" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="http_auth">
                    <input type="hidden" name="site_id" value="<?= e($siteId) ?>">
                    <input type="text" name="http_auth_username" placeholder="Username" required
                           style="padding:.35rem .5rem;font:inherit">
                    <input type="password" name="http_auth_password" placeholder="Password" required
                           style="padding:.35rem .5rem;font:inherit">
                    <button type="submit" class="btn" style="padding:.25rem .6rem;margin:0">Save</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</details>
