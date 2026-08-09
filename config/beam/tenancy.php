<?php

return [
    'system_tenant' => [
        'slug' => env('SPLICEWIRE_SYSTEM_TENANT_SLUG', 'system'),
        'name' => 'System',
        'role' => 'system',
    ],

    /*
     * The package's CENTRAL tenancy substrate migrations
     * (tenants/domains/tenant_users/tenant_invitations + tenant-row ALTERs) are
     * PUBLISH-ONLY .stub files, registered via spatie/laravel-package-tools'
     * `->hasMigrations([...])` under the `beam-tenancy-migrations` tag
     * (`vendor:publish --tag=beam-tenancy-migrations`), which re-stamps them to the
     * install moment; the host's `migrate` pass then runs the copies. No
     * `register_migrations` knob — a host that owns the substrate itself simply
     * doesn't publish the tag.
     */
];
