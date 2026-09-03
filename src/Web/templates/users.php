<?php
/**
 * @var list<array{email:string,role:string,first_name:string,last_name:string,status:string}> $users
 * @var list<string> $roles
 * @var string|null $admin
 * @var string|null $me
 * @var bool $isAdmin
 * @var string $csrf
 * @var string $notice
 */
?>
<h1>Users</h1>
<p class="muted">
    Only these addresses can access the interface. The administrator role
    can manage this list; the last remaining active administrator can
    neither be removed nor suspended.
</p>

<?php
$notices = [
    'added'             => ['badge-ok', 'User added.'],
    'edited'            => ['badge-ok', 'User updated.'],
    'suspended'         => ['badge-ok', 'User suspended.'],
    'reactivated'       => ['badge-ok', 'User reactivated.'],
    'removed'           => ['badge-ok', 'User removed.'],
    'add-failed'        => ['badge-error', 'Invalid address, unknown role, or already present.'],
    'edit-failed'       => ['badge-error', 'Could not save — invalid/duplicate address, unknown role, or the last active administrator.'],
    'suspend-failed'    => ['badge-error', 'Could not suspend — unknown address or the last active administrator.'],
    'reactivate-failed' => ['badge-error', 'Could not reactivate — unknown address.'],
    'remove-failed'     => ['badge-error', 'Cannot remove this address (not found, or the last active administrator).'],
];
if (isset($notices[$notice])):
    [$cls, $text] = $notices[$notice];
?>
    <p><span class="badge <?= $cls ?>"><?= e($text) ?></span></p>
<?php endif; ?>

<table>
<thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php foreach ($users as $i => $user): ?>
    <?php
    $name = trim($user['first_name'] . ' ' . $user['last_name']);
    $isLastActiveAdmin = $user['role'] === 'admin' && $user['status'] === 'active'
        && count(array_filter($users, static fn (array $u): bool => $u['role'] === 'admin' && $u['status'] === 'active')) <= 1;
    ?>
    <tr class="row-display" data-row-id="<?= $i ?>">
        <td><?= e($name !== '' ? $name : '—') ?></td>
        <td class="mono"><?= e($user['email']) ?><?= $user['email'] === $me ? ' <span class="muted">(you)</span>' : '' ?></td>
        <td><span class="badge <?= $user['role'] === 'admin' ? 'badge-ok' : 'badge-muted' ?>"><?= e($user['role']) ?></span></td>
        <td><?= $user['status'] === 'suspended' ? '<span class="badge badge-warn">suspended</span>' : '<span class="badge badge-ok">active</span>' ?></td>
        <td>
            <?php if ($isAdmin): ?>
                <button type="button" class="row-edit-btn" data-row-id="<?= $i ?>" title="Edit">✎</button>
                <?php if ($user['status'] === 'suspended'): ?>
                    <form method="post" action="/users" style="display:inline;margin:0">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="reactivate">
                        <input type="hidden" name="email" value="<?= e($user['email']) ?>">
                        <button type="submit" class="btn" style="padding:.2rem .5rem;font-size:.8rem">Reactivate</button>
                    </form>
                <?php elseif (!$isLastActiveAdmin): ?>
                    <form method="post" action="/users" style="display:inline;margin:0"
                          onsubmit="return confirm('Suspend <?= e($user['email']) ?>? They will not be able to sign in until reactivated.')">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="email" value="<?= e($user['email']) ?>">
                        <button type="submit" class="btn" style="padding:.2rem .5rem;font-size:.8rem;background:var(--surface-2);color:var(--text)">Suspend</button>
                    </form>
                <?php endif; ?>
                <?php if (!$isLastActiveAdmin): ?>
                    <form method="post" action="/users" style="display:inline;margin:0"
                          onsubmit="return confirm('Remove <?= e($user['email']) ?>? This cannot be undone.')">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="email" value="<?= e($user['email']) ?>">
                        <button type="submit" class="btn" style="padding:.2rem .5rem;font-size:.8rem;background:var(--error)">Remove</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </td>
    </tr>
    <?php if ($isAdmin): ?>
    <tr class="row-edit-form" data-row-id="<?= $i ?>" style="display:none">
        <td colspan="5">
            <form method="post" action="/users" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin:0">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="email" value="<?= e($user['email']) ?>">
                <input type="text" name="first_name" value="<?= e($user['first_name']) ?>" placeholder="First name" style="padding:.35rem .5rem;font:inherit;width:8rem">
                <input type="text" name="last_name" value="<?= e($user['last_name']) ?>" placeholder="Last name" style="padding:.35rem .5rem;font:inherit;width:8rem">
                <input type="email" name="new_email" value="<?= e($user['email']) ?>" required style="padding:.35rem .5rem;font:inherit;min-width:16rem">
                <select name="role" style="padding:.35rem .5rem;font:inherit">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role) ?>" <?= $role === $user['role'] ? 'selected' : '' ?>><?= e($role) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn" style="padding:.35rem .7rem">Save</button>
                <button type="button" class="btn row-cancel-btn" style="padding:.35rem .7rem;background:var(--surface-2);color:var(--text)">Cancel</button>
            </form>
        </td>
    </tr>
    <?php endif; ?>
<?php endforeach; ?>
</tbody>
</table>

<?php if ($isAdmin): ?>
    <h3 style="margin-top:1.5rem;font-size:.9rem" class="muted">Add a user</h3>
    <form method="post" action="/users" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="add">
        <input type="text" name="first_name" placeholder="First name" style="padding:.45rem .6rem;font:inherit;width:9rem">
        <input type="text" name="last_name" placeholder="Last name" style="padding:.45rem .6rem;font:inherit;width:9rem">
        <input type="email" name="email" required placeholder="first.last@example.com"
               style="padding:.45rem .6rem;min-width:18rem;font:inherit">
        <select name="role" style="padding:.45rem .6rem;font:inherit">
            <?php foreach ($roles as $role): ?>
                <option value="<?= e($role) ?>" <?= $role === 'maintenance' ? 'selected' : '' ?>><?= e($role) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Add</button>
    </form>
<?php else: ?>
    <p class="pending-note">Only an administrator can manage this list.</p>
<?php endif; ?>
