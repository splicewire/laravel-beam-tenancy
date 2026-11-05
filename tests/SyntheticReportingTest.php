<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tenancy\Data\SyntheticReportingData;
use Splicewire\Beam\Tenancy\Data\SyntheticReportingInputData;
use Splicewire\Beam\Tenancy\Data\TenantData;
use Splicewire\Beam\Tenancy\Synthetic\Ops\SetSyntheticReporting;
use Splicewire\Beam\Tenancy\Synthetic\SyntheticReporting;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\CacheManager as TenantCacheManager;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

/**
 * The reporting toggle: `Tenant::reportable()` drops Synthetic Tenants only while
 * `SyntheticReporting::excludes()` is true, and an operator can flip that at runtime through the cache, with
 * config untouched (no redeploy), with every flip on the central activity trail.
 */
beforeEach(function () {
    config(['cache.default' => 'array']);

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

    Tenant::create(['id' => 'real-a', 'name' => 'Real A']);
    Tenant::create(['id' => 'real-b', 'name' => 'Real B', 'synthetic' => false]);
    Tenant::create(['id' => 'synth-a', 'name' => 'Synth A'])->markSynthetic()->save();
    Tenant::create(['id' => 'synth-b', 'name' => 'Synth B'])->markSynthetic()->save();
});

function reportableIds(): array
{
    return Tenant::query()->reportable()->orderBy('id')->pluck('id')->all();
}

function allTenantIds(): array
{
    return Tenant::query()->orderBy('id')->pluck('id')->all();
}

it('CONTROL: the fixture really holds two synthetic and two real tenants', function () {
    expect(Tenant::query()->synthetic()->orderBy('id')->pluck('id')->all())->toBe(['synth-a', 'synth-b'])
        ->and(Tenant::query()->notSynthetic()->orderBy('id')->pluck('id')->all())->toBe(['real-a', 'real-b']);
});

it('reads config when no override is set, with the flagship env name', function () {
    expect(config('beam.tenancy.synthetic.exclude_from_reporting'))->toBeFalse()
        ->and(app(SyntheticReporting::class)->excludes())->toBeFalse();

    config(['beam.tenancy.synthetic.exclude_from_reporting' => true]);

    expect(app(SyntheticReporting::class)->excludes())->toBeTrue()
        ->and(app(SyntheticReporting::class)->state()->source)->toBe('config');

    expect(file_get_contents(dirname(__DIR__).'/config/beam/tenancy.php'))
        ->toContain("env('SYNTHETIC_EXCLUDE_FROM_REPORTING', false)");
});

it('makes reportable() equal every tenant while the toggle is off', function () {
    expect(app(SyntheticReporting::class)->excludes())->toBeFalse()
        ->and(reportableIds())->toBe(allTenantIds())
        ->and(reportableIds())->toHaveCount(4);
});

it('drops synthetic tenants from reportable() while the toggle is on', function () {
    config(['beam.tenancy.synthetic.exclude_from_reporting' => true]);

    expect(reportableIds())->toBe(['real-a', 'real-b']);
});

it('flips through the cache with config untouched, and the next query sees it', function () {
    $reporting = app(SyntheticReporting::class);

    $reporting->set(true);

    // Config still says include: the flip is the cache override, not a redeploy.
    expect(config('beam.tenancy.synthetic.exclude_from_reporting'))->toBeFalse()
        ->and(cache()->store()->get(SyntheticReporting::CACHE_KEY))->toBeTrue()
        ->and(reportableIds())->toBe(['real-a', 'real-b']);

    // A fresh instance reads the same answer: nothing is held in the object.
    app()->forgetInstance(SyntheticReporting::class);
    expect(app(SyntheticReporting::class)->excludes())->toBeTrue();

    // An explicit include beats an exclude in config.
    config(['beam.tenancy.synthetic.exclude_from_reporting' => true]);
    $reporting->set(false);
    expect(reportableIds())->toBe(allTenantIds())
        ->and($reporting->state()->source)->toBe('override');

    // Clearing hands the answer back to config.
    $state = $reporting->set(null);
    expect($state->override)->toBeNull()
        ->and($state->source)->toBe('config')
        ->and($state->excludes)->toBeTrue()
        ->and(reportableIds())->toBe(['real-a', 'real-b']);
});

it('logs every flip to the central activity trail, before and after', function () {
    $operator = SyntheticReportingOperator::create(['id' => (string) Str::uuid(), 'email' => 'op@beam.test']);

    app(SyntheticReporting::class)->set(true, $operator);
    app(SyntheticReporting::class)->set(null);

    $rows = CentralActivityLog::query()->where('log_name', SyntheticReporting::LOG_NAME)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->event)->toBe('set')
        ->and($rows[0]->causer_id)->toBe((string) $operator->getKey())
        ->and($rows[0]->properties['before']['excludes'])->toBeFalse()
        ->and($rows[0]->properties['after']['excludes'])->toBeTrue()
        ->and($rows[0]->properties['after']['source'])->toBe('override')
        ->and($rows[1]->event)->toBe('cleared')
        ->and($rows[1]->causer_id)->toBeNull()
        ->and($rows[1]->properties['after']['source'])->toBe('config');
});

it('sees a central override from inside a tenant, where the cache manager is tenant-tagged', function () {
    // A persistent store, as a host has. stancl's bootstrapper builds a NEW cache manager, so an `array` store
    // (in-memory per manager) would lose every key at the swap whatever the toggle did; a database or Redis
    // store reads the same backend from either manager. The package pins the database store to central.
    Schema::connection('testing')->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    config(['cache.default' => 'database']);
    Cache::forgetDriver('database');

    app(SyntheticReporting::class)->set(true);

    // Stand up stancl's tenant cache exactly as its bootstrapper does at a host: the harness does not boot
    // stancl's TenancyServiceProvider, so the bootstrapper is run by hand against a current tenant.
    // stancl's provider makes Tenancy a singleton and binds the current-tenant contract; mirror both.
    $tenant = Tenant::find('real-a');
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);
    tenancy()->tenant = $tenant;
    tenancy()->initialized = true;
    $bootstrapper = new CacheTenancyBootstrapper(app());
    $bootstrapper->bootstrap($tenant);

    try {
        // CONTROL: every proxied call is now tagged with the tenant. The database store cannot tag at all, so a
        // proxied read does not even reach the key.
        expect(app('cache'))->toBeInstanceOf(TenantCacheManager::class)
            ->and(fn () => Cache::get(SyntheticReporting::CACHE_KEY))->toThrow(BadMethodCallException::class);

        // The toggle reads a named store, which the tenant manager does not tag.
        expect(app(SyntheticReporting::class)->excludes())->toBeTrue()
            ->and(reportableIds())->toBe(['real-a', 'real-b']);
    } finally {
        $bootstrapper->revert();
        tenancy()->tenant = null;
        tenancy()->initialized = false;
    }
});

it('flips from the command line, and refuses two choices at once', function () {
    $this->artisan('splicewire:beam:tenancy:synthetic-reporting')
        ->expectsOutputToContain('INCLUDED in reporting (source: config')
        ->assertSuccessful();

    $this->artisan('splicewire:beam:tenancy:synthetic-reporting', ['--exclude' => true])
        ->expectsOutputToContain('EXCLUDED from reporting (source: override')
        ->assertSuccessful();
    expect(reportableIds())->toBe(['real-a', 'real-b']);

    $this->artisan('splicewire:beam:tenancy:synthetic-reporting', ['--include' => true, '--clear' => true])
        ->assertFailed();

    $this->artisan('splicewire:beam:tenancy:synthetic-reporting', ['--clear' => true])->assertSuccessful();
    expect(reportableIds())->toBe(allTenantIds())
        ->and(CentralActivityLog::query()->where('log_name', SyntheticReporting::LOG_NAME)->count())->toBe(2);
});

it('declares a subject-free, os.operate-gated write op with every shape slot', function () {
    $resources = new ParticleResourceRegistry;
    $operations = new ParticleOperationRegistry;
    $discovery = new AttributedParticleDiscovery($resources, $operations);
    $discovery->registerClass(TenantData::class);
    $discovery->registerClass(SetSyntheticReporting::class);

    $op = $operations->get('tenants', 'synthetic-reporting');

    expect($op->kind)->toBe(OperationKind::Write)
        ->and($op->ability)->toBe('os.operate')
        ->and($op->abilityModel)->toBeFalse()
        ->and($op->input)->toBe(SyntheticReportingInputData::class)
        ->and($op->output)->toBe(SyntheticReportingData::class);
});

describe('over HTTP', function () {
    beforeEach(function () {
        // Base beam is not in this suite's provider list; the operation controller needs what it binds.
        $this->app->register(BeamServiceProvider::class);

        Schema::create('sessions', function (Blueprint $t): void {
            $t->string('id')->primary();
        });
        config()->set('auth.providers.users.model', SyntheticReportingOperator::class);

        app(ParticleResourceRegistry::class)->registerClass(TenantData::class);
        Particle::ops('beam/tenants', 'tenants', [SetSyntheticReporting::class]);

        Gate::define('entitlement:os.operate', fn (?SyntheticReportingOperator $user) => $user?->email === 'op@beam.test');
    });

    it('refuses a caller without os.operate, and changes nothing', function () {
        $member = SyntheticReportingOperator::create(['id' => (string) Str::uuid(), 'email' => 'member@beam.test']);

        $this->actingAs($member)
            ->postJson('/beam/tenants/synthetic-reporting', ['excludeFromReporting' => true])
            ->assertForbidden();

        expect(app(SyntheticReporting::class)->override())->toBeNull()
            ->and(reportableIds())->toBe(allTenantIds());
    });

    it('lets an operator flip it, with no redeploy, and logs the operator as causer', function () {
        $operator = SyntheticReportingOperator::create(['id' => (string) Str::uuid(), 'email' => 'op@beam.test']);

        $this->actingAs($operator)
            ->postJson('/beam/tenants/synthetic-reporting', ['excludeFromReporting' => true])
            ->assertOk()
            ->assertJsonPath('data.excludes', true)
            ->assertJsonPath('data.source', 'override');

        expect(reportableIds())->toBe(['real-a', 'real-b'])
            ->and(CentralActivityLog::query()->where('log_name', SyntheticReporting::LOG_NAME)->value('causer_id'))
            ->toBe((string) $operator->getKey());

        $this->actingAs($operator)
            ->postJson('/beam/tenants/synthetic-reporting', [])
            ->assertUnprocessable();
    });
});

class SyntheticReportingOperator extends AuthUser
{
    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}
