<h1>Utilisateurs</h1>
<p class="muted">
    Seules ces adresses peuvent accéder à l'interface. La première est
    l'administrateur : elle seule gère la liste, et ne peut pas être retirée.
</p>

<?php
$notices = [
    'added'         => ['badge-ok', 'Utilisateur ajouté.'],
    'removed'       => ['badge-ok', 'Utilisateur retiré.'],
    'add-failed'    => ['badge-error', 'Adresse invalide ou déjà présente.'],
    'remove-failed' => ['badge-error', "Impossible de retirer cette adresse (absente, ou c'est l'administrateur)."],
];
if (isset($notices[$notice])):
    [$cls, $text] = $notices[$notice];
?>
    <p><span class="badge <?= $cls ?>"><?= e($text) ?></span></p>
<?php endif; ?>

<table><thead><tr><th>Adresse</th><th>Rôle</th><th></th></tr></thead><tbody>
<?php foreach ($users as $email): ?>
    <tr>
        <td class="mono"><?= e($email) ?><?= $email === $me ? ' <span class="muted">(vous)</span>' : '' ?></td>
        <td><?= $email === $admin
                ? '<span class="badge badge-ok">administrateur</span>'
                : '<span class="badge badge-muted">utilisateur</span>' ?></td>
        <td>
            <?php if ($isAdmin && $email !== $admin): ?>
                <form method="post" action="/users" style="margin:0"
                      onsubmit="return confirm('Retirer <?= e($email) ?> ?')">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="email" value="<?= e($email) ?>">
                    <button type="submit" class="btn" style="background:var(--error);margin:0;padding:.25rem .6rem">Retirer</button>
                </form>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>

<?php if ($isAdmin): ?>
    <h3 style="margin-top:1.5rem;font-size:.9rem" class="muted">Ajouter un utilisateur</h3>
    <form method="post" action="/users">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="add">
        <input type="email" name="email" required placeholder="prenom.nom@exemple.com"
               style="padding:.45rem .6rem;min-width:20rem;font:inherit">
        <button type="submit" class="btn">Ajouter</button>
    </form>
<?php else: ?>
    <p class="pending-note">Seul <span class="mono"><?= e($admin ?? '—') ?></span> peut modifier cette liste.</p>
<?php endif; ?>
