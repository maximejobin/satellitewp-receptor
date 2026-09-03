<?php

declare(strict_types=1);

/**
 * Role -> capability map for the web UI.
 *
 * Nothing in the app is gated by a role yet (2026-09-02) — this is only the
 * data model (roles exist, are stored per user, and can be asked "does this
 * role have capability X") so that wiring an actual restriction later is a
 * one-line change at the call site, not a new subsystem. Every signed-in
 * user can still do everything they could before this file existed.
 *
 * '*' means every capability, present or future. Extend a role by adding a
 * capability here — nothing that reads via RoleCapabilities::can() needs to
 * change.
 *
 * Capabilities:
 *   manage_users    - add/remove web UI accounts (/users)
 *   manage_sites    - pairing, key revoke/rebind, HTTP Basic Auth for probing (/keys)
 *   edit_catalog    - set a plugin/theme's licence (/catalog)
 *   run_analysis    - queue an extraction for analysis
 *   view_technical  - full per-site extraction report (probes, raw data)
 *   view_catalog    - software catalogue + the reference Data pages
 */
return [
    'admin'       => ['*'],
    'maintenance' => ['manage_sites', 'edit_catalog', 'run_analysis', 'view_technical', 'view_catalog'],
    'coordinator' => ['view_technical', 'view_catalog'],
    'sale'        => ['view_catalog'],
];
