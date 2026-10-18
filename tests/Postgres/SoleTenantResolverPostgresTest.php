<?php

use Splicewire\Beam\Tenancy\Exceptions\SoleTenantNotIdentified;
use Splicewire\Beam\Tenancy\Resolvers\SoleTenantResolver;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The sole-tenant resolver's system-tenant exclusion on the engine production runs (orch-peer HIGH, 5fafc48d). The
 * exclusion is a JSON-path predicate on `data->role`, and JSON null semantics differ between SQLite and Postgres, so
 * the sqlite proof in SoleTenantIdentificationTest is not enough on its own.
 */
it('excludes every system tenant in the query, on Postgres', function () {
    foreach (['a-system', 'b-system'] as $id) {
        Tenant::create(['id' => $id, 'name' => $id, 'slug' => $id])->markAsSystem();
    }
    Tenant::create(['id' => 'c-eligible', 'name' => 'C', 'slug' => 'c-eligible']);

    expect(app(SoleTenantResolver::class)->resolve()->getTenantKey())->toBe('c-eligible');

    Tenant::create(['id' => 'd-eligible', 'name' => 'D', 'slug' => 'd-eligible']);

    expect(fn () => app(SoleTenantResolver::class)->resolve())
        ->toThrow(SoleTenantNotIdentified::class, 'more than one');
});
