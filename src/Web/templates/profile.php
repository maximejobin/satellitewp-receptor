<?php
/**
 * @var array{
 *     email:string,role:string,first_name:string,last_name:string,status:string,
 *     data:array<string,mixed>|null,runcloud_api_key:string|null,public_ssh_key:string|null
 * } $user
 * @var string $csrf
 * @var string $notice
 */
$name = trim($user['first_name'] . ' ' . $user['last_name']);
$dataJson = $user['data'] !== null
    ? json_encode($user['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    : '';

$notices = [
    'saved'        => ['badge-ok', 'Profile saved.'],
    'invalid-json' => ['badge-error', 'Could not save — "Data" is not valid JSON (must be an object or array, e.g. {}).'],
];
?>
<h1>My profile</h1>
<p class="muted">Your own account — identity and role are managed by an administrator on <a href="/users">Users</a>;
    the fields below are yours to edit.</p>

<?php if (isset($notices[$notice])): [$cls, $text] = $notices[$notice]; ?>
    <p><span class="badge <?= $cls ?>"><?= e($text) ?></span></p>
<?php endif; ?>

<?= section('Identity', implode('', [
    field('Name', $name !== '' ? $name : null),
    field('Email', $user['email']),
    field('Role', $user['role']),
    field('Status', $user['status']),
])) ?>

<form method="post" action="/profile">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <section class="card info-card">
        <h3>Profile</h3>
        <table class="kv">
            <tbody>
            <tr>
                <th>Runcloud API key</th>
                <td>
                    <input type="password" name="runcloud_api_key" value="<?= e($user['runcloud_api_key'] ?? '') ?>"
                           placeholder="Not set" autocomplete="off" style="width:100%;max-width:28rem;padding:.4rem .6rem;font:inherit">
                </td>
            </tr>
            <tr>
                <th>Public SSH key</th>
                <td>
                    <textarea name="public_ssh_key" rows="3" placeholder="ssh-ed25519 AAAA..."
                              style="width:100%;max-width:28rem;padding:.4rem .6rem;font:inherit;font-family:var(--mono);font-size:.85rem"><?= e($user['public_ssh_key'] ?? '') ?></textarea>
                </td>
            </tr>
            <tr>
                <th>Data</th>
                <td>
                    <textarea name="data" rows="6" placeholder="{}"
                              style="width:100%;max-width:28rem;padding:.4rem .6rem;font:inherit;font-family:var(--mono);font-size:.85rem"><?= e($dataJson) ?></textarea>
                    <div class="muted" style="font-size:.8rem;margin-top:.3rem">Free-form JSON (an object or array) — leave empty to clear.</div>
                </td>
            </tr>
            </tbody>
        </table>
    </section>
    <button type="submit" class="btn">Save</button>
</form>
