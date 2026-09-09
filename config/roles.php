<?php

declare(strict_types=1);

/**
 * Role -> capability map for the web UI.
 *
 * Wired throughout the app (2026-09-03) — every mutation route and every
 * data-sensitive GET page calls Router::requireCapability() (or the stricter
 * identity check /users keeps, see below) with one of the names below.
 * '*' means every capability, present or future. Extend a role by adding a
 * capability here — nothing that reads via RoleCapabilities::can() needs to
 * change.
 *
 * Capabilities are named `<entity>_<verb>`, one per distinct action, rather
 * than a handful of coarse groups (the pre-2026-09-03 shape had one
 * `manage_users` covering add/edit/suspend/remove alike) — a role can be
 * handed exactly "may suspend a user" without also getting "may delete one".
 *
 *   user_add                   - add a web UI account (/users)
 *   user_edit                  - rename/rename-email/change role of one
 *   user_suspend                - suspend or reactivate one (same capability
 *                                 both ways — it is one "manage this
 *                                 account's ability to sign in" concern)
 *   user_remove                 - remove one outright
 *   site_key_add                - pair a new site / issue a new API key
 *   site_key_revoke              - revoke a site's key
 *   site_key_rebind              - move a key to a new origin (keys:rebind)
 *   site_http_auth_edit         - set/clear the HTTP Basic Auth used to probe a site
 *   extraction_run              - queue a pending/queued extraction for analysis
 *   catalog_view                - browse the software catalogue
 *   catalog_edit                 - set a plugin/theme's licence
 *   extraction_view_technical    - the full per-site extraction report
 *                                 (probes, raw payload) — not the plain site
 *                                 list/pairing page, which stays open
 *   data_view                   - the reference "Data" pages (WordPress/
 *                                 PHP/database versions, the Wordfence
 *                                 vulnerability catalogue)
 *   crm_view                    - browse the external CRM database
 *                                 (clients/websites/products/items)
 *   crm_subscription_edit       - relink a subscription to a website
 *
 * `/users` mutations additionally require a *real* Google-signed-in identity
 * regardless of capability (Router keeps this check separate, unchanged from
 * before capabilities existed) — under Basic auth or the open dev fallback
 * there is no per-user roster to check a capability against, and the web UI
 * account list is sensitive enough that "stays blocked" is the safer default
 * there, not "promoted to full access". Every other capability below follows
 * the rest of the app's existing posture: with Google sign-in configured it
 * gates by role; without it (Basic auth / open dev), nothing here is
 * enforced (there is no per-user role to check), same as before this file's
 * checks were wired up.
 *
 * Role assignments below are a reasonable starting point, not a business
 * decision made elsewhere — tune freely:
 *   - maintenance: everything operational (sites, keys, catalog, running
 *     analyses, the CRM) except managing other web UI accounts.
 *   - coordinator: read access to the technical extraction report, the
 *     catalogue, the reference Data pages and the CRM — no mutations.
 *   - sale: the catalogue and the CRM only (no technical extraction detail,
 *     no mutations) — closest existing role to "front-of-house".
 */
return [
    'admin'       => ['*'],
    'maintenance' => [
        'site_key_add', 'site_key_revoke', 'site_key_rebind', 'site_http_auth_edit',
        'extraction_run', 'catalog_view', 'catalog_edit', 'extraction_view_technical',
        'data_view', 'crm_view', 'crm_subscription_edit',
    ],
    'coordinator' => ['extraction_view_technical', 'catalog_view', 'data_view', 'crm_view'],
    'sale'        => ['catalog_view', 'crm_view'],
];
