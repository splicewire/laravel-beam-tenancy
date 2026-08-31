<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\UUIDGenerator;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            // Testbench does NOT auto-discover — a package harness boots exactly what this method
            // names, while `src/` freely imports anything it can autoload. This package reaches
            // `spatie/laravel-data` (see `src/Data/TenantMemberRoleInputData.php`) without ever
            // booting its provider, so before this line `config('data')` was NULL inside this suite
            // and `TenantMemberRoleInputData::validateAndCreate()` died with
            // `ErrorException: Trying to access array offset on null` — a FATAL, not a failure.
            // Measured here before the fix; same omission and mechanism as `splicewire/tower`.
            // api-surface-coherence tickets 84 / 85.
            LaravelDataServiceProvider::class,

            // laravel-popcorn binds RegistryIndex as a SINGLETON, and testbench does NOT
            // auto-discover it — requiring the package is not enough. Without it the index is
            // auto-resolvable but UNSHARED, so every `describe()` lands on a throwaway and a
            // registry-conformance assertion passes vacuously over an empty index
            // (registry-kernel 27 D3). `MachineIdentityKindRegistryConformanceTest` pins that
            // tripwire directly rather than trusting this line.
            PopcornServiceProvider::class,

            BeamTenancyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // beam-core BINDS the seed manifest as a singleton in its REGISTER phase, and this harness
        // does not boot beam-core. Bound here (before the package providers register) so
        // BeamTenancyServiceProvider's own seed registration has the same container to land in that
        // it has at a real host — otherwise the registration silently no-ops and the test that
        // asserts it would be measuring an empty room.
        $app->singleton(BeamSeedManifest::class);

        // ⚠️ stancl's `GeneratesIds::getIncrementing()` returns `! app()->bound(UniqueIdentifierGenerator)`
        // — so with the generator UNBOUND, a Tenant is treated as AUTO-INCREMENTING no matter that the
        // model declares `$incrementing = false`. The row still lands with the right string id, and the
        // in-memory model's key is then overwritten with sqlite's rowid (`1`), silently. Anything
        // reading `$tenant->getKey()` after a save — every `belongsToMany` write, so every seat this
        // package's seeders create — writes `tenant_id = '1'`. stancl's own TenancyServiceProvider
        // binds this off `tenancy.id_generator`; this harness does not boot that provider, so it binds
        // the same default here. Measured: without this line the demo-tenant seats insert against a
        // tenant that does not exist, and `members()` reads back empty with no error anywhere.
        $app->bind(UniqueIdentifierGenerator::class, UUIDGenerator::class);

        // stancl resolves its Tenant/Domain models off config, and its published config always sets
        // them. Without them, anything touching HasDomains fatals on `new null`.
        $app['config']->set('tenancy.tenant_model', Tenant::class);
        $app['config']->set('tenancy.domain_model', Domain::class);

        // The tenant-storage half of stancl's config, which its published `config/tenancy.php`
        // always sets and this harness (which does not boot stancl's provider) otherwise leaves
        // null. Mirrors the flagship's values. Without `central_connection` there is no template
        // connection to resolve a driver from, so nothing can tell whether the host provisions
        // tenant databases at all — the question `DemoTenantSeeder::provision()` turns on.
        // `managers` is deliberately left EMPTY here: the default harness host is one with no
        // tenant databases, and a test that wants provisioning registers its own recorder.
        $app['config']->set('tenancy.database.central_connection', 'testing');
        $app['config']->set('tenancy.database.prefix', 'tenant_');
        $app['config']->set('tenancy.database.suffix', '');
        $app['config']->set('tenancy.database.managers', []);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Booting LaravelDataServiceProvider alone would be a FALSE GREEN. The package ships
        // `name_mapping_strategy.input => null`, but the only host that runs this code
        // (`~/Herd/splicewire-app/config/data.php`) sets it to CamelCaseMapper — the ONLY semantic
        // delta between the two files. A DTO hydrates fine under testbench defaults and can still
        // stop mapping under the host's mapper, so the harness mirrors the host, not the default.
        $app['config']->set('data.name_mapping_strategy.input', CamelCaseMapper::class);

        // Structure caching points at `app_path('Data')` by default, which does not exist here, and
        // a cached reflection analysis carried across runs is exactly what a harness should not have.
        $app['config']->set('data.structure_caching.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Mirrors create_tenants_table.php.stub. `slug` and `parent_tenant_id` are REAL columns,
        // not data-> keys — Tenant::getCustomColumns() names them — so a harness without them
        // fails any test that touches the actual Tenant rather than the TestTenant fixture.
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->string('parent_tenant_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        // Mirrors the central user table a host owns (T22) — UUID-keyed, because
        // `create_tenant_users_table.php.stub` declares `tenant_users.user_id` as a `uuid`.
        // Deliberately carries no spatie permission tables alongside it: the seats on
        // `tenant_users` must be the ONLY authorization signal available in this harness, or a
        // gate test cannot tell a seat apart from an ambient central grant.
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        // Mirrors create_tenant_users_table.php.stub (FKs omitted — sqlite in-memory, and the
        // shape under test is the pivot's columns, not its referential integrity).
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->string('tenant_id');
            $table->uuid('user_id');
            $table->string('role')->default('member');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->primary(['tenant_id', 'user_id']);
        });

        // Mirrors create_tenant_machine_identities_table.php.stub (FKs omitted — sqlite in-memory,
        // and the shape under test is the columns, not referential integrity).
        //
        // ⚠️ Carries NO `role` and NO `accepted_at`, exactly like the stub. That is not an
        // abbreviation of the real table — it IS the real table's design, and a harness that added
        // them "for symmetry" with `tenant_users` above would make the billing-exclusion property
        // untestable and quietly licence someone to add them to the stub too.
        Schema::create('tenant_machine_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->uuid('user_id');
            $table->string('kind');
            $table->string('label')->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'kind']);
        });
    }
}
