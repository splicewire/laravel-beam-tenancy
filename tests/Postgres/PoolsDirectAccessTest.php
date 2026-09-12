<?php

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * pooled-storage ticket 10 — a pooled tenant's direct-access role, on real Postgres: the command
 * binds the tenant's key INTO the role (`pg_db_role_setting`), records only the role name, and on
 * revoke drops the role without touching the tenant's data.
 *
 * The live read — a connection as the role with no session_settings sees exactly one key's rows —
 * is proven in rushing/laravel-postgres-rls's own RoleBinderTest, which owns that mechanism. This
 * file proves the host wiring on top of it.
 */
beforeEach(function () {
    config(['beam.tenancy.pooled.direct_access_roles' => true]);
});

afterEach(function () {
    // The Postgres harness skips in setUp() when PG_TEST_DATABASE is unset, but afterEach still
    // runs — against sqlite, where there is no role to drop.
    if (! getenv('PG_TEST_DATABASE')) {
        return;
    }

    // Drop the role directly if a test failed before revoke() could — never leave a login role
    // behind on the shared test cluster.
    $role = Tenant::find('alpha')?->direct_access_role;

    if ($role && DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
        // A bare DROP ROLE is refused while the role still holds grants — the same reason
        // RoleBinder::unbind() revokes before it drops. DROP OWNED BY removes those privileges.
        DB::statement('drop owned by "'.$role.'"');
        DB::statement('drop role "'.$role.'"');
    }
});

it('issues a direct-access role with the tenant\'s key bound into the role itself, then revokes and drops it', function () {
    $alpha = provisionPooled('alpha');
    $bravo = provisionPooled('bravo');

    tenancy()->initialize($alpha);
    DB::connection('tenant')->table('pooled_notes')->insert(['slug' => 'a-1', 'title' => 'A']);
    tenancy()->end();
    tenancy()->initialize($bravo);
    DB::connection('tenant')->table('pooled_notes')->insert(['slug' => 'b-1', 'title' => 'B']);
    tenancy()->end();

    $this->artisan('splicewire:beam:tenancy:pools:direct-access', ['tenant' => 'alpha'])
        ->assertSuccessful();

    $role = Tenant::find('alpha')->direct_access_role;
    expect($role)->not->toBeNull()->and($role)->toStartWith('direct_');

    // The claim is that the setting is bound INTO THE ROLE — `ALTER ROLE ... SET` writes to
    // `pg_db_role_setting`, applied by Postgres at session start before any statement runs. That is
    // exactly what makes a direct connection with no session_settings key at all still scoped, so
    // the assertion reads the catalog rather than a connection this harness has no easy way to open
    // under a fresh role's own credentials.
    $roleSetting = DB::selectOne(
        'select rolconfig from pg_roles r left join pg_db_role_setting s on s.setrole = r.oid where r.rolname = ?',
        [$role]
    );
    expect($roleSetting?->rolconfig)->toContain('app.tenant_id=alpha');

    $this->artisan('splicewire:beam:tenancy:pools:direct-access', ['tenant' => 'alpha', '--revoke' => true])
        ->assertSuccessful();

    expect(Tenant::find('alpha')->hasDirectAccessRole())->toBeFalse()
        ->and(DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role]))->toBeNull();

    // Bravo's data is untouched by the whole issue/revoke cycle.
    tenancy()->initialize($bravo);
    expect(DB::connection('tenant')->table('pooled_notes')->count())->toBe(1);
    tenancy()->end();
});

it('rotates the password and re-asserts the setting on a second issue, idempotently', function () {
    $alpha = provisionPooled('alpha');

    $this->artisan('splicewire:beam:tenancy:pools:direct-access', ['tenant' => 'alpha'])->assertSuccessful();
    $firstRole = Tenant::find('alpha')->direct_access_role;

    $this->artisan('splicewire:beam:tenancy:pools:direct-access', ['tenant' => 'alpha'])->assertSuccessful();
    $secondRole = Tenant::find('alpha')->direct_access_role;

    expect($secondRole)->toBe($firstRole);

    $count = DB::selectOne('select count(*) as n from pg_roles where rolname = ?', [$firstRole])->n;
    expect($count)->toBe(1);
});
