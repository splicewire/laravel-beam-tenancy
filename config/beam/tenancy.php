<?php

return [
    'system_tenant' => [
        'slug' => env('SPLICEWIRE_SYSTEM_TENANT_SLUG', 'system'),
        'name' => 'System',
        'role' => 'system',
    ],

    /*
     * Register the package's CENTRAL tenancy substrate migrations
     * (tenants/domains/tenant_users/tenant_invitations + tenant-row ALTERs).
     * Default ON. Set false if the host owns the substrate itself.
     */
    'register_migrations' => env('BEAM_TENANCY_REGISTER_MIGRATIONS', true),
];
