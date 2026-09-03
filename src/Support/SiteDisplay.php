<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Support;

/**
 * A website URL for display: strip the scheme and a leading "www.", so
 * "https://www.rds.ca" reads as "rds.ca" (2026-09-02, user: "le but est que
 * ce soit facile à lire" — applied everywhere a site is shown as text or in
 * a dropdown). **Never** use this for a link's `href` or an API/AJAX request
 * target — it produces a schemeless string, not a valid URL, only something
 * a human reads.
 *
 * A plain autoloaded class rather than a template-only helper function: some
 * callers (Router.php building a page `title`/breadcrumb string) run before
 * helpers.php is guaranteed loaded — that file is `require_once`'d by
 * layout.php, which Router.php's own code runs ahead of — the same
 * constraint that already keeps Router.php using htmlspecialchars() instead
 * of helpers.php's e(). Putting the one implementation here lets every
 * caller, template or not, share it; helpers.php's site_display() is a thin
 * wrapper over this for template code.
 */
final class SiteDisplay
{
    public static function of(mixed $url): string
    {
        $url = trim((string) $url);
        $url = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url);
        $url = (string) preg_replace('#^www\.#i', '', $url);

        return rtrim($url, '/');
    }
}
