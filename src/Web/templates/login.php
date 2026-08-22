<div style="max-width:26rem;margin:4rem auto;text-align:center">
    <h1 style="margin-bottom:.3rem"><b>SatelliteWP</b> Xtractor</h1>
    <p class="muted" style="margin-top:0">Interface d'analyse — accès réservé.</p>

    <?php if ($message !== ''): ?>
        <p class="badge badge-error" style="display:inline-block;margin:1rem 0"><?= e($message) ?></p>
    <?php endif; ?>

    <?php if ($firstRun): ?>
        <p class="pending-note" style="text-align:left">
            Aucun utilisateur n'est enregistré, donc personne ne peut entrer.
            Amorce la liste depuis le serveur :
            <br><span class="mono">bin/xtractor users:add votre@adresse.com</span>
            <br>La première adresse ajoutée est l'administrateur.
        </p>
    <?php endif; ?>

    <p style="margin-top:1.5rem">
        <a class="btn" href="/auth/login">Se connecter avec Google</a>
    </p>
</div>
