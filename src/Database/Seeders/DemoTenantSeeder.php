<?php

namespace Splicewire\Beam\Tenancy\Database\Seeders;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * Seats beam-accounts' role-differentiated demo roster on a **tenant**.
 *
 * ## Why this exists at all
 *
 * beam-accounts' {@see DemoTeamSeeder} already provisions `demo-owner` / `demo-admin` /
 * `demo-member` and seats them on a beam-accounts `Team`. It cannot seat them on a tenant:
 * beam-tenancy REQUIRES beam-accounts, so beam-accounts must never learn tenants exist. The
 * contribution therefore reaches DOWN from here — the estate's contribution-seam direction —
 * and this is beam-tenancy's first seeder.
 *
 * The gap it closes was measured, not assumed. At the flagship the four demo users existed with
 * **zero** central spatie roles (exactly the role-less actor an authorization regression test
 * wants), and **every** `tenant_users` seat belonged to a root account. So the roster existed and
 * was not in the tenancy at all, and no test could construct "an ordinary tenant member". After
 * this seeder runs, `manageInvitations` — beam-accounts' `MembershipPolicy` ability, which reads
 * the actor's seat through {@see TeamContract::memberRole()} — is *allowed* for the Admin seat and
 * *denied* for the Member seat on the same tenant, off nothing but seeded data.
 *
 * ## Which tenant
 *
 * A **dedicated** tenant (`beam.tenancy.demo.tenant.slug`, default `beam-demo`), created here,
 * not an existing one. Seating into a live tenant exercises more of the real thing and buys two
 * known hazards with it: seats whose `role` is outside the {@see Role} enum (`service` rows exist
 * in the estate, and `memberRole()` raises a `ValueError` on them), and hosts whose per-tenant
 * `model_has_roles` is never read because the role models pin the central connection. A tenant
 * this seeder owns end to end has neither. It is created through the same `firstOrNew`-then-save
 * idiom as `Tenant::provisionSystem()`, so on a host with the stancl creation pipeline wired it
 * provisions like any other tenant, and in a listener-free harness it is just a row.
 *
 * This seeder never iterates *existing* seats, deliberately: reading an out-of-enum `role` off a
 * tenant it did not create is the `ValueError` above, and repairing those rows is a separate
 * defect, not this seeder's business.
 *
 * ## Which seats
 *
 * The **shared** half of {@see BeamDemo::subjects()} — the role-derived roster — so Owner, Admin
 * and Member map straight onto the tenant, and adding a `Role` case seats a new demo member with
 * no edit here. `solo` is excluded on purpose: it models the *team-of-one shape*, not a role, and
 * a solo subject sharing a tenant with three others is the one thing it is not.
 *
 * Idempotent: users are `firstOrCreate`d, seats go through {@see TeamContract::assignMember()}
 * (a `syncWithoutDetaching` upsert), and `accepted_at` is stamped only when still null. Running
 * it twice changes no row.
 *
 * The user `firstOrCreate` is deliberately redundant with `DemoTeamSeeder`'s — the manifest runs
 * that one first (order 10 vs. 20) — so that `db:seed --class=DemoTenantSeeder` on its own is
 * still a complete act rather than a silent no-op on a fresh database.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (! BeamDemo::enabled()) {
            $this->command?->warn('beam-tenancy: demo tenant skipped (demo affordances disabled in this environment).');

            return;
        }

        $tenant = $this->tenant();

        $userModel = BeamAccounts::userModel();
        $password = Hash::make((string) config('beam.accounts.demo.password', 'password'));

        $shared = array_filter(BeamDemo::subjects(), fn (array $subject): bool => $subject['shared']);

        foreach ($shared as $key => $subject) {
            $user = $userModel::query()->firstOrCreate(
                ['email' => BeamDemo::email($key)],
                ['name' => BeamDemo::name($key), 'password' => $password, 'email_verified_at' => now()],
            );

            $tenant->assignMember($user, $subject['role']);
            $this->accept($tenant, $user);

            if ($subject['role'] === Role::Owner) {
                $this->recordOwner($tenant, $user);
            }
        }

        $this->command?->info(
            'beam-tenancy: demo tenant ready — '.implode('/', Role::values())." seated on \"{$tenant->getKey()}\"."
        );
    }

    /**
     * Find-or-create the dedicated demo tenant. Mirrors `Tenant::provisionSystem()`: `firstOrNew`
     * on the configured id, fill only what is missing, save. The model comes from
     * `tenancy.tenant_model` so a host that substitutes its own Tenant is seated on ITS model —
     * the same seam every other tenancy consumer reads.
     */
    protected function tenant(): TeamContract&Model
    {
        $slug = (string) config('beam.tenancy.demo.tenant.slug', 'beam-demo');

        /** @var class-string<Tenant> $model */
        $model = config('tenancy.tenant_model') ?: Tenant::class;

        $tenant = $model::firstOrNew(['id' => $slug]);

        if (empty($tenant->name)) {
            $tenant->name = (string) config('beam.tenancy.demo.tenant.name', 'Beam Demo');
        }

        if (empty($tenant->slug)) {
            $tenant->slug = $slug;
        }

        $tenant->save();

        return $tenant;
    }

    /**
     * Stamp the seat as accepted — a seeded demo member is a real member, not a pending invite —
     * and only when it is not already, so a second run is a no-op rather than a fresh timestamp.
     */
    protected function accept(Model $tenant, Authenticatable $user): void
    {
        $relation = $tenant->users();
        $pivot = $relation->wherePivot($relation->getRelatedPivotKeyName(), $user->getKey())->first()?->pivot;

        if ($pivot !== null && $pivot->getAttribute('accepted_at') === null) {
            $tenant->users()->updateExistingPivot($user->getKey(), ['accepted_at' => now()]);
        }
    }

    /**
     * Point the tenant's `owner_email` at the Owner-role subject.
     *
     * Deliberately NOT `Tenant::assignOwner()`, which additionally `run()`s inside the tenant's
     * own schema to upsert a per-tenant `TenantUser`. That requires a provisioned tenant database;
     * a seeder must work on a host that has not provisioned one, and the central seat is the whole
     * of what this seeder claims to establish.
     */
    protected function recordOwner(Model $tenant, Authenticatable $user): void
    {
        $email = $user->getAttribute('email');

        if ($tenant->getAttribute('owner_email') !== $email) {
            $tenant->setAttribute('owner_email', $email);
            $tenant->save();
        }
    }
}
