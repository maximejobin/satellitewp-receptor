<?php
/**
 * @var list<array{email:string,role:string,first_name:string,last_name:string,status:string}> $users
 * @var list<string> $roles
 * @var string|null $me
 * @var array{add: bool, edit: bool, suspend: bool, remove: bool} $can
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

<?php if ($can['add']): ?>
<div class="obs-toolbar">
    <button type="button" class="btn user-open" data-user="">+ Add a user</button>
</div>
<?php endif; ?>

<table>
<thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php foreach ($users as $user): ?>
    <?php
    $name = trim($user['first_name'] . ' ' . $user['last_name']);
    $isLastActiveAdmin = $user['role'] === 'admin' && $user['status'] === 'active'
        && count(array_filter($users, static fn (array $u): bool => $u['role'] === 'admin' && $u['status'] === 'active')) <= 1;
    ?>
    <tr>
        <td><?= e($name) ?></td>
        <td class="mono"><?= e($user['email']) ?><?= $user['email'] === $me ? ' <span class="muted">(you)</span>' : '' ?></td>
        <td><span class="badge <?= $user['role'] === 'admin' ? 'badge-ok' : 'badge-muted' ?>"><?= e($user['role']) ?></span></td>
        <td><?= $user['status'] === 'suspended' ? '<span class="badge badge-warn">suspended</span>' : '<span class="badge badge-ok">active</span>' ?></td>
        <td style="white-space:nowrap">
            <?php if ($can['edit']): ?>
                <button type="button" class="row-edit-btn user-open" title="Edit"
                        data-user="<?= e((string) json_encode([
                            'email'      => $user['email'],
                            'first_name' => $user['first_name'],
                            'last_name'  => $user['last_name'],
                            'role'       => $user['role'],
                        ], JSON_INVALID_UTF8_SUBSTITUTE)) ?>"><?= icon_edit() ?></button>
            <?php endif; ?>
            <?php if ($can['suspend']): ?>
                <?php if ($user['status'] === 'suspended'): ?>
                    <form method="post" action="/users" style="display:inline;margin:0">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="reactivate">
                        <input type="hidden" name="email" value="<?= e($user['email']) ?>">
                        <button type="submit" class="btn" style="padding:.2rem .5rem;font-size:.8rem">Reactivate</button>
                    </form>
                <?php elseif (!$isLastActiveAdmin): ?>
                    <form method="post" action="/users" style="display:inline;margin:0"
                          onsubmit="return confirm(<?= e(json_encode('Suspend ' . $user['email'] . '? They will not be able to sign in until reactivated.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="email" value="<?= e($user['email']) ?>">
                        <button type="submit" class="btn btn-muted" style="padding:.2rem .5rem;font-size:.8rem">Suspend</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($can['remove']): ?>
                <?php if (!$isLastActiveAdmin): ?>
                    <form method="post" action="/users" style="display:inline;margin:0"
                          onsubmit="return confirm(<?= e(json_encode('Remove ' . $user['email'] . '? This cannot be undone.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="email" value="<?= e($user['email']) ?>">
                        <button type="submit" class="btn btn-danger" style="padding:.2rem .5rem;font-size:.8rem">Remove</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>

<?php if (!$can['add']): ?>
    <p class="pending-note">Only an administrator can manage this list.</p>
<?php endif; ?>

<?php if ($can['add'] || $can['edit']): ?>
<dialog id="user-dialog" class="obs-dialog" aria-labelledby="user-dialog-title">
    <form method="post" action="/users">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="email" value="">
        <header class="obs-dialog-head">
            <h3 id="user-dialog-title">New user</h3>
            <button type="button" class="json-dialog-close user-close" aria-label="Close">&times;</button>
        </header>
        <div class="obs-dialog-body">
            <div class="obs-names">
                <div class="obs-field">
                    <label for="user-first-name">First name</label>
                    <input id="user-first-name" type="text" name="first_name" autocomplete="off">
                </div>
                <div class="obs-field">
                    <label for="user-last-name">Last name</label>
                    <input id="user-last-name" type="text" name="last_name" autocomplete="off">
                </div>
            </div>
            <div class="obs-field">
                <label for="user-email">Google account email</label>
                <input id="user-email" type="email" name="new_email" required autocomplete="off" placeholder="first.last@example.com">
                <small>The address used to sign in with Google.</small>
            </div>
            <div class="obs-field">
                <label for="user-role">Role</label>
                <select id="user-role" name="role" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role) ?>"><?= e($role) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>The last active administrator keeps the admin role.</small>
            </div>
        </div>
        <footer class="obs-dialog-foot">
            <button type="button" class="btn btn-muted user-close">Cancel</button>
            <button type="submit" class="btn user-submit">Add user</button>
        </footer>
    </form>
</dialog>
<script>
(function () {
  var dialog = document.getElementById('user-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') { return; }
  var form = dialog.querySelector('form');
  document.addEventListener('click', function (e) {
    var opener = e.target.closest('.user-open');
    if (opener) {
      var rec = opener.dataset.user ? JSON.parse(opener.dataset.user) : null;
      form.reset();
      form.elements.action.value = rec ? 'edit' : 'add';
      form.elements.email.value = rec ? rec.email : '';
      form.elements.new_email.value = rec ? rec.email : '';
      form.elements.first_name.value = rec ? rec.first_name : '';
      form.elements.last_name.value = rec ? rec.last_name : '';
      form.elements.role.value = rec ? rec.role : <?= json_encode(\SatelliteWP\Manager\Storage\UserStore::DEFAULT_ROLE) ?>;
      document.getElementById('user-dialog-title').textContent = rec ? 'Edit user' : 'New user';
      dialog.querySelector('.user-submit').textContent = rec ? 'Save changes' : 'Add user';
      dialog.showModal();
      form.elements.first_name.focus();
      return;
    }
    if (e.target.closest('.user-close')) { dialog.close(); return; }
    if (e.target === dialog) { dialog.close(); }
  });
})();
</script>
<?php endif; ?>
