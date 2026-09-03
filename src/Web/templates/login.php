<div style="max-width:26rem;margin:4rem auto;text-align:center">
    <h1 style="margin-bottom:.3rem"><b>SatelliteWP</b> Xtractor</h1>
    <p class="muted" style="margin-top:0">Analyst interface — restricted access.</p>

    <?php if ($message !== ''): ?>
        <p class="badge badge-error" style="display:inline-block;margin:1rem 0"><?= e($message) ?></p>
    <?php endif; ?>

    <?php if ($firstRun): ?>
        <p class="pending-note" style="text-align:left">
            No user is registered yet, so no one can sign in.
            Seed the list from the server:
            <br><span class="mono">bin/xtractor users:add your@address.com</span>
            <br>The first address added becomes the administrator.
        </p>
    <?php endif; ?>

    <p style="margin-top:1.5rem">
        <a class="btn" href="/auth/login">Sign in with Google</a>
    </p>
</div>
