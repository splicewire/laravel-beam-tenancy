<?php

namespace Splicewire\Beam\Tenancy\Database\Seeders;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Jobs\CreateDatabase;

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
 * ## Why it PROVISIONS the tenant's storage, when all it needs is central rows
 *
 * Everything this seeder claims to establish — `tenant_users` seats — lives in the CENTRAL
 * database, so on the face of it the tenant's own schema is somebody else's problem. It is not,
 * and the first cut of this seeder shipped the defect that proves it: it created the tenant row
 * and stopped. At the flagship the host's `TenantCreated` pipeline is `shouldBeQueued(true)`
 * against a redis queue with no worker running, so it provisioned nothing. The row landed with no
 * `tenancy_db_name` and no schema, and `$tenant->run(...)` threw
 * `Database tenant_beam-demo does not exist.`
 *
 * That is not a cosmetic gap. The estate sweeps tenants with a bare `Tenant::all()` loop (Tower's
 * `ReevaluateRoleAssignmentsCommand` and `OperatorDashboardController` both do). A tenant that
 * throws on connect ABORTS such a loop, and the partial result it leaves behind reads exactly like
 * a complete one. Creating a row that cannot be connected to is the seeder handing every future
 * sweep a landmine in exchange for saving itself a schema.
 *
 * So it provisions, through stancl's OWN seam rather than a hand-rolled one:
 * {@see CreateDatabase} (which stamps `tenancy_db_name` via `makeCredentials()` and fires the
 * `CreatingDatabase`/`DatabaseCreated` events a host may be listening on) dispatched
 * **synchronously**, then `tenants:migrate` scoped to this one tenant key. Both are checked
 * first, so a host whose pipeline already provisioned normally reaches a no-op rather than a
 * `TenantDatabaseAlreadyExistsException`.
 *
 * Three things it deliberately does NOT do. It does not run the host's provisioning pipeline —
 * that is Tower's, it seeds permissions and search indices and subscriptions, and a package
 * seeder has no business firing it. It does not fan out: `tenants:migrate` is passed
 * `--tenants=<this key>`, never left to hit every tenant in the estate. And it does not throw
 * when the host has no tenant-database manager registered for its driver — that is a fact about
 * the HOST, not about this declaration, so it is a warning and the seats still land (this
 * package's own sqlite harness is exactly that host).
 *
 * `beam.tenancy.demo.tenant.provision` turns the whole step off for a host that provisions demo
 * tenants some other way.
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

        $this->provision($tenant);

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
        $slug = $this->slug();

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
     * The demo tenant's id/slug, validated as a bare identifier.
     *
     * This throws, where almost everything else in this class warns, because it is the one
     * question the DECLARATION's author can answer without knowing which host loads it: the slug
     * is not merely a key, it is spliced into a Postgres schema name (`tenancy.database.prefix` .
     * id), a `search_path`, a Redis `prefix_base` and a search-index name. A hyphen makes
     * `tenant_beam-demo`, which is not a legal bare Postgres identifier and works only for as long
     * as every one of those consumers remembers to quote it. stancl's `PostgreSQLSchemaManager`
     * happens to quote its `CREATE SCHEMA`; that is not a guarantee the rest of the chain does,
     * and the estate's other tenant ids (`system`, `demo`, `entreport`) are all bare single words.
     *
     * The default was `beam-demo` for exactly one day and one row, both of which this change
     * retires.
     */
    protected function slug(): string
    {
        $slug = (string) config('beam.tenancy.demo.tenant.slug', 'beam_demo');

        if (preg_match('/^[a-z0-9_]+$/', $slug) !== 1) {
            throw new InvalidArgumentException(
                "beam-tenancy: demo tenant slug [{$slug}] is not a bare identifier. The slug becomes a "
                .'Postgres schema name, a search_path entry and a cache/search key prefix, so it must match '
                .'/^[a-z0-9_]+$/ — use underscores, not hyphens (see beam.tenancy.demo.tenant.slug).'
            );
        }

        return $slug;
    }

    /**
     * Ensure the demo tenant's storage exists and is migrated, so the row this seeder created is
     * one a `Tenant::all()` sweep can actually connect to. See this class's docblock for why a
     * central-seats-only seeder provisions at all.
     *
     * Every early return here is a fact about the HOST, so every one of them is a warning rather
     * than a throw — including the missing `tenants:migrate`, which simply is not registered in a
     * harness that does not boot stancl's provider.
     */
    protected function provision(Model $tenant): void
    {
        if (! config('beam.tenancy.demo.tenant.provision', true)) {
            $this->command?->warn('beam-tenancy: demo tenant storage not provisioned (beam.tenancy.demo.tenant.provision is off).');

            return;
        }

        if (! $tenant instanceof TenantWithDatabase) {
            $this->command?->warn('beam-tenancy: demo tenant storage not provisioned — the configured tenant model has no database of its own.');

            return;
        }

        $manager = $this->databaseManager($tenant);

        if ($manager === null) {
            $this->command?->warn('beam-tenancy: demo tenant storage not provisioned — no tenancy.database.managers entry for this host\'s driver.');

            return;
        }

        if (! $manager->databaseExists((string) $tenant->database()->getName())) {
            // stancl's own job, run inline: it stamps `tenancy_db_name` through
            // `makeCredentials()` and fires the DatabaseCreated event the host may listen on.
            // Guarded by the existence check above because the job's own
            // `ensureTenantCanBeCreated()` throws rather than no-ops on an existing schema.
            //
            // ⚠️ `dispatchNow`, NOT `dispatchSync`. `CreateDatabase` is Queueable, so
            // `dispatchSync()` routes it through the SYNC QUEUE, which serializes it — and
            // `SerializesModels` then re-fetches the tenant, so `makeCredentials()` stamps
            // `tenancy_db_name` onto a DIFFERENT instance. Ours stays stale, and the very next
            // `$tenant->save()` in this seeder re-encodes the whole `data` column off the stale
            // attributes and silently DELETES the key that was just written. Measured here: the
            // schema was created, the row came back without `tenancy_db_name`, and nothing
            // errored — the same defect this seeder is fixing, reintroduced one layer down.
            app(Dispatcher::class)->dispatchNow(new CreateDatabase($tenant));
        }

        $this->migrate($tenant);

        // A host's provisioning listeners write to the tenant through their OWN instances (the
        // flagship's `markProvisioning()` does). `data` is a single re-encoded JSON column, so any
        // later save from this seeder would clobber whatever they wrote. Re-read before seating.
        $tenant->refresh();
    }

    /**
     * Migrate the demo tenant's schema — SCOPED to its own key.
     *
     * `tenants:migrate` with no `--tenants` fans out across every tenant in the estate, which is a
     * documented hazard and categorically not a seeder's business. The key is passed explicitly on
     * every call.
     */
    protected function migrate(Model $tenant): void
    {
        if (! array_key_exists('tenants:migrate', Artisan::all())) {
            $this->command?->warn('beam-tenancy: demo tenant schema not migrated — tenants:migrate is not registered on this host.');

            return;
        }

        Artisan::call('tenants:migrate', ['--tenants' => [(string) $tenant->getKey()]]);
    }

    /**
     * The tenant-database manager for this host's driver, or null when the host has none.
     *
     * Deliberately NOT `$tenant->database()->manager()`: that reads `config('tenancy.database.managers')`
     * and passes it straight to `array_key_exists`, which is a TypeError — not a catchable
     * "not registered" — on a host that never published stancl's config at all.
     */
    protected function databaseManager(TenantWithDatabase $tenant): ?TenantDatabaseManager
    {
        // Read the way stancl's own `getTemplateConnectionName()` reads, but WITHOUT its
        // `: string` return type: on a host that never published `config/tenancy.php` every one
        // of these is null, and calling stancl's accessor there is a TypeError rather than the
        // "this host has no tenant databases" answer we want.
        $connection = $tenant->getInternal('db_connection')
            ?? config('tenancy.database.template_tenant_connection')
            ?? config('tenancy.database.central_connection');

        if (! is_string($connection)) {
            return null;
        }

        $driver = config("database.connections.{$connection}.driver");
        $class = config('tenancy.database.managers.'.$driver);

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        /** @var TenantDatabaseManager $manager */
        $manager = app($class);
        $manager->setConnection($connection);

        return $manager;
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
