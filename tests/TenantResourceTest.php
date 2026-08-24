<?php

use Carbon\CarbonInterface;
use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\Graphine\Testing\SeamGuard;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;
use Splicewire\Beam\Tenancy\Data\CreateTenantData;
use Splicewire\Beam\Tenancy\Data\TenantData;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The neutral `tenants` particle resource, exercised through the registry and the projection —
 * what a resource resolves to on a live boot and what it emits for a given row, never the internal
 * shape of a projection method.
 */
beforeEach(function () {
    // stancl's HasDomains relation; the base harness builds only `tenants`.
    Schema::create('domains', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('domain')->unique();
        $table->boolean('is_primary')->default(false);
        $table->string('tenant_id');
        $table->timestamps();
    });

    // The ADR-0098 Display timeline substrate. CentralActivityLog pins the `central` CONNECTION (the
    // audit trail must stay readable outside any tenant boundary), so the harness has to give it one —
    // a second in-memory database, which is also the honest shape: `statusEvents` is a cross-connection
    // relation, and an eager load that works here works because it is two queries, not a join.
    config(['database.connections.central' => config('database.connections.testing')]);

    Schema::connection('central')->create('activity_log', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('log_name')->nullable();
        $table->text('description')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->json('properties')->nullable();
        $table->string('event')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });
});

/**
 * Create a tenant and read it back.
 *
 * ⚠️ The re-fetch is not ceremony. The harness's `tenants.id` is a string primary key on SQLite, so the
 * model instance `create()` hands back has its `id` clobbered to the rowid (`1`) even though the ROW
 * holds `acme`. Anything keyed off the returned instance — a morph subject id, a relation — would key
 * off `1` and silently match nothing.
 */
function makeTenant(array $attributes): Tenant
{
    Tenant::create($attributes);

    return Tenant::find($attributes['id']);
}

/** A tenant domain, inserted directly: stancl's Domain model fires a resolver-cache hook the bare harness cannot satisfy. */
function domainRow(Tenant $tenant, string $domain, bool $isPrimary = true): void
{
    DB::table('domains')->insert([
        'domain' => $domain,
        'is_primary' => $isPrimary,
        'tenant_id' => (string) $tenant->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * One status row on the tenant's Display timeline, written straight to the substrate — the projection
 * reads the log, so the test writes the log rather than driving the emitter.
 */
function statusRow(Tenant $tenant, string $state, string $message, ?CarbonInterface $at = null): CentralActivityLog
{
    $at ??= now();

    return CentralActivityLog::create([
        'log_name' => 'status',
        'description' => $message,
        'subject_type' => $tenant->getMorphClass(),
        'subject_id' => (string) $tenant->getKey(),
        'event' => $state,
        'properties' => ['state' => $state, 'ref' => null, 'progress' => null],
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

it('registers the tenants resource onto the particle registry, unconditionally', function () {
    // This assertion has been inverted twice, and both flips are the point. It first passed for a reason
    // that never held in a real host: it resolved the registry AFTER booting the provider, the one order
    // in which the old `afterResolving` hook fires — beam resolves that singleton during its OWN boot, so
    // in production the hook never ran and this declaration was silently absent everywhere it was ever
    // measured (ticket 07). Ticket 07 fixed the idiom but left the registration OFF, because under
    // last-write-wins a 9-prop OOTB `tenants` at provider position 13 was simply overwritten by tower's
    // 22-prop declaration at 19. Ticket 04 §A1 retired that contract — the CONTRIBUTOR declares its own
    // slice and the owner is never overwritten — so the hold-back has nothing left to protect.
    app()->singleton(ParticleResourceRegistry::class, fn () => new ParticleResourceRegistry);

    $provider = new BeamTenancyServiceProvider(app());
    $provider->register();
    $provider->boot();

    $registry = app(ParticleResourceRegistry::class);

    expect($registry->has('tenants'))->toBeTrue();
    $registry->definition('tenants'); // throws if the Frame-manifest side is absent
});

it('declares a model backing, read-only, non-filterable, with both includes', function () {
    $resource = AttributedParticleDiscovery::resourceFromAttribute(TenantData::class);

    expect($resource->key)->toBe('tenants')
        ->and($resource->modelClass())->toBe(Tenant::class)
        ->and($resource->readOnly)->toBeTrue()
        // The eager-loads that make the widening free rather than per-row (ticket 03 §A3).
        ->and($resource->includes)->toBe(['domains', 'statusEvents'])
        // Load-bearing, and invisible for as long as the declaration sat unregistered: `filterable`
        // DEFAULTS to true, and a filterable index routes through ParticleHydrator::query(), whose
        // shipped default (PayloadParticleReader::query()) throws. No data-filters query is registered
        // for `tenants` anywhere in the estate, so true would 500 a bare beam host's tenants list.
        ->and($resource->filterable)->toBeFalse();

    $definition = $resource->toResourceDefinition();

    expect($definition->creatable)->toBeFalse()
        ->and($definition->editable)->toBeFalse()
        ->and($definition->deletable)->toBeFalse();
});

it('carries the nav placement that descended from tower with the teardown', function () {
    $resource = AttributedParticleDiscovery::resourceFromAttribute(TenantData::class);

    // Ticket 20 step 2. These say where the resource sits in the admin nav, not what it projects, so
    // they descend as DEFAULTS rather than as a fixed answer — a host that disagrees overlays them
    // per realm, because nav is read off `definitions($realm)`, the arm RealmResourceRegistry::apply()
    // actually reaches. (`editData` below could not be re-homed that way: the resource path passes
    // `$realm === null` and gets the base back untouched.)
    expect($resource->section)->toBe('platform')
        ->and($resource->navOrder)->toBe(1)
        ->and($resource->routeName)->toBe('tenants.index');
});

it('declares a create form of THREE props, and the three it does not carry are the point', function () {
    $resource = AttributedParticleDiscovery::resourceFromAttribute(TenantData::class);

    expect($resource->editData)->toBe(CreateTenantData::class);

    $props = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        (new ReflectionClass(CreateTenantData::class))->getConstructor()->getParameters(),
    );

    // ⚠️ A DELIBERATE capability loss, asserted so re-adding is a conscious act (ticket 17 §A5).
    // Tower's `CreateTenantData` fused three owners' fields into one class — the tenancy core, plus
    // beam-commerce's `planSlug`/`commitmentMonths` and tower's `scaffoldPackSlugs` — for exactly one
    // reason: a declaration carries a SINGLE `editData` slot. That is the god-projection's third
    // instance, after `tenants` (last-registration-wins on a key) and `TowerAuthUserData`
    // (last-bind-wins on a binding), and the read-side contribution seam cannot cure it: it folds onto
    // an already-projected row and `ResourceContribution` has no write arm at all.
    //
    // So the operator create form no longer selects a plan or a scaffold pack, and a tenant is created
    // plan-less and subscribed separately. The loss is the evidence that graduates the write-side seam
    // out of the map's fog — do not quietly restore it.
    expect($props)->toBe(['slug', 'name', 'ownerEmail']);
});

it('names no commerce symbol in the create form either', function () {
    // The read projection's seam guard has a twin here, because `editData` is the other place a
    // commerce concept could descend into this package and close the dependency cycle
    // (`laravel-beam-commerce` REQUIRES `laravel-beam-tenancy`).
    $source = file_get_contents(__DIR__.'/../src/Data/CreateTenantData.php');

    expect($source)->not->toContain('Splicewire\\Beam\\Commerce');
});

it('registers nothing and does not fatal when beam\'s particle registry is absent', function () {
    // The structural guard — the one gate that survives, because it is not policy: a host that
    // installs beam-tenancy without beam has no registry to register into. It must get nothing —
    // not a partial registration, and not a fatal on a missing class.
    $app = new Application(dirname(__DIR__));
    $app->instance('config', new Repository);

    $provider = new BeamTenancyServiceProvider($app);
    $boot = new ReflectionMethod($provider, 'bootFrameResources');
    $boot->setAccessible(true);

    $boot->invoke($provider);

    expect($app->resolved(ParticleResourceRegistry::class))->toBeFalse();
});

it('projects only what Tenant itself knows', function () {
    Tenant::create([
        'id' => 'acme',
        'name' => 'Acme',
        'slug' => 'acme',
        'provisioning_status' => 'active',
        'owner_email' => 'owner@example.test',
    ]);

    $data = TenantData::project(Tenant::find('acme'));

    expect($data->id)->toBe('acme')
        ->and($data->name)->toBe('Acme')
        ->and($data->slug)->toBe('acme')
        ->and($data->provisioningStatus)->toBe('active')
        ->and($data->ownerEmail)->toBe('owner@example.test')
        ->and($data->suspended)->toBeFalse();

    // No commerce axis on the projection, by construction.
    $props = array_map(
        fn ($p) => $p->getName(),
        (new ReflectionClass(TenantData::class))->getConstructor()->getParameters()
    );
    expect($props)->not->toContain('plan')
        ->not->toContain('billStatus')
        ->not->toContain('billTotal')
        ->not->toContain('entitlements');
});

it('distinguishes a suspended tenant from a broken one', function () {
    // suspended_at is orthogonal to provisioning_status: an operator has to be able to tell a tenant
    // that is deliberately inactive from one whose provisioning fell over. A single conflated status
    // field cannot say which, which is why `suspended` is its own column.
    Tenant::create([
        'id' => 'acme',
        'name' => 'Acme',
        'provisioning_status' => 'active',
        'suspended_at' => '2026-01-01T00:00:00+00:00',
    ]);

    $data = TenantData::project(Tenant::find('acme'));

    expect($data->suspended)->toBeTrue()
        ->and($data->provisioningStatus)->toBe('active');
});

it('emits ISO-8601 strings, not raw Carbon', function () {
    Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    $data = TenantData::project(Tenant::find('acme'));

    expect($data->createdAt)->toBeString()
        ->and($data->createdAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('carries exactly 17 props — the 9 OOTB plus ticket 03 §A1\'s 8 owner-local', function () {
    $props = array_map(
        fn ($p) => $p->getName(),
        (new ReflectionClass(TenantData::class))->getConstructor()->getParameters()
    );

    expect($props)->toHaveCount(17)
        // The 8 the fold added, named so a future deletion has to argue with this list.
        ->and($props)->toContain('parentTenantId', 'llmConfig', 'scaffoldPackSlugs', 'statusChannel', 'primaryHost', 'statuses', 'isBusy', 'isStalled')
        // Still not the commerce 5 — those arrive as a contribution, and a seam guard in this suite
        // asserts the package names no commerce symbol. And still not `period`, which is a request
        // facet echoed back rather than projection data (ticket 03 §A1).
        ->and($props)->not->toContain('plan', 'commitmentMonths', 'entitlements', 'billStatus', 'billTotal', 'period');
});

it('projects the 8 owner-local props off the Tenant alone', function () {
    // Ticket 03 §A1 read tower's 22-prop TenantData prop by prop and found 8 of the 14 extras are backed
    // by `Tenant` itself. They belong here — a package widening its own declaration of its own model is
    // not a contribution question at all — and this is the assertion that they arrived.
    $tenant = makeTenant([
        'id' => 'acme',
        'name' => 'Acme',
        'slug' => 'acme',
        'parent_tenant_id' => 'broker',
        'provisioning_status' => 'provisioning',
        'llm_config' => ['preferred_model' => 'claude-opus-5'],
        'scaffold_pack_slugs' => ['crm', 'billing'],
    ]);
    domainRow($tenant, 'acme.test');
    statusRow($tenant, 'queued', 'Queued', now()->subMinutes(2));
    statusRow($tenant, 'running', 'Provisioning schema', now()->subMinute());

    $data = TenantData::project(Tenant::with(['domains', 'statusEvents'])->find('acme'));

    expect($data->parentTenantId)->toBe('broker')
        ->and($data->llmConfig)->toBe(['preferred_model' => 'claude-opus-5'])
        ->and($data->scaffoldPackSlugs)->toBe(['crm', 'billing'])
        ->and($data->statusChannel)->toBeString()
        ->and($data->primaryHost)->toStartWith('acme.test')
        ->and($data->isBusy)->toBeTrue()
        ->and($data->isStalled)->toBeFalse();

    // Oldest-first, and the shared State vocabulary rather than a decoded name suffix.
    expect($data->statuses)->toHaveCount(2)
        ->and($data->statuses[0]->state)->toBe('queued')
        ->and($data->statuses[1]->state)->toBe('running')
        ->and($data->statuses[1]->message)->toBe('Provisioning schema');
});

it('costs zero queries per row once the includes are eager-loaded', function () {
    // The whole justification for the widening. `primaryHost`, `statuses`, `isBusy` and `isStalled` each
    // used to reach a fresh query per row — ticket 03 measured ~2 queries per row on the tenants list
    // against a docblock claiming there was no N+1. Declared as `includes`, the page pays once.
    foreach (['acme', 'globex', 'initech'] as $id) {
        $tenant = makeTenant(['id' => $id, 'name' => ucfirst($id), 'slug' => $id, 'provisioning_status' => 'provisioning']);
        domainRow($tenant, "{$id}.test");
        statusRow($tenant, 'running', 'Provisioning schema');
    }

    $countQueries = function (callable $work): int {
        foreach (['testing', 'central'] as $name) {
            DB::connection($name)->flushQueryLog();
            DB::connection($name)->enableQueryLog();
        }

        $work();

        $total = 0;
        foreach (['testing', 'central'] as $name) {
            $total += count(DB::connection($name)->getQueryLog());
            DB::connection($name)->disableQueryLog();
        }

        return $total;
    };

    // BEFORE — what the declaration cost without the includes: `domains` lazy-loads for `primaryHost`,
    // and the timeline is queried for `statuses` plus `isBusy`/`isStalled`. Measured, not asserted at an
    // exact figure: the point is that it scales with the row count.
    $lazy = Tenant::all();
    $before = $countQueries(fn () => $lazy->each(fn (Tenant $tenant) => TenantData::project($tenant)));

    // AFTER — the same three rows through the declared includes.
    $eager = Tenant::with(['domains', 'statusEvents'])->get();
    $projected = null;
    $after = $countQueries(function () use ($eager, &$projected) {
        $projected = $eager->map(fn (Tenant $tenant) => TenantData::project($tenant));
    });

    expect($projected)->toHaveCount(3)
        ->and($projected->first()->statuses)->toHaveCount(1)
        ->and($projected->first()->isBusy)->toBeTrue()
        // Zero, and the contrast is the ticket's whole justification: per-row cost becomes two queries
        // for the page, paid by the eager load before projection starts.
        ->and($after)->toBe(0)
        // Exactly 3 per row without the includes — `domains` for `primaryHost`, the timeline for
        // `statuses`, and one more for `isBusy`/`isStalled` (mutually exclusive, so exactly one of them
        // reaches `latestStatusEvent()`). Ticket 03 read ~2 per row off the call chain without being
        // able to run it; this is the figure measured.
        ->and($before)->toBe(3 * 3);
});

it('answers isBusy and isStalled from the loaded relation, and from a query when it is not loaded', function () {
    // latestStatusEvent() reads the loaded relation when there is one — that is what makes the two flags
    // free on a list read — and falls back to a one-row descending query otherwise, rather than loading a
    // whole timeline to read its last row.
    $tenant = makeTenant(['id' => 'acme', 'name' => 'Acme', 'provisioning_status' => 'provisioning']);
    statusRow($tenant, 'running', 'Started', now()->subMinutes(30));

    // Unloaded: the flags still answer, at the cost of a query.
    expect($tenant->isBusy())->toBeTrue()
        ->and($tenant->provisioningIsStalled())->toBeTrue(); // 30m > the 10m stall threshold

    $loaded = Tenant::with('statusEvents')->find('acme');

    DB::connection('central')->enableQueryLog();

    expect($loaded->isBusy())->toBeTrue()
        ->and($loaded->provisioningIsStalled())->toBeTrue()
        ->and(DB::connection('central')->getQueryLog())->toBe([]);
});

it('keeps purging the timeline through a query, not the ordered relation', function () {
    // `statusEvents()` carries the timeline's orderBy, and DELETE … ORDER BY is MySQL-only syntax — so
    // the purge deliberately stays on the unordered query. This asserts the behaviour that guards it.
    $tenant = makeTenant(['id' => 'acme', 'name' => 'Acme']);
    statusRow($tenant, 'complete', 'Done');

    expect($tenant->statusTimeline())->toHaveCount(1);

    $tenant->purgeStatusTimeline();

    expect(Tenant::find('acme')->statusTimeline())->toHaveCount(0);
});

it('names no commerce symbol in the resource or its projection', function () {
    // The ticket's constraint asserted mechanically rather than by review. Same AST scan the
    // package-wide guard runs, narrowed to the two files this ticket adds.
    $guard = new SeamGuard(['Splicewire\Beam\Commerce']);

    expect($guard->scan(__DIR__.'/../src/Data/TenantData.php'))->toBe([])
        ->and($guard->scan(__DIR__.'/../src/BeamTenancyServiceProvider.php'))->toBe([]);
});
