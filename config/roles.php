<?php

declare(strict_types=1);

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
