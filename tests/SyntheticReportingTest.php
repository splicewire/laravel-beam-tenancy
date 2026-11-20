<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Cache;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Illuminate\Support\Facades\DB;
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
 * `SyntheticReporting::excludes()` is true. config-sections 09: an operator flips it through the OPERATOR tier of the
 * Override Ladder (`beam.tenancy.synthetic` `/exclude_from_reporting`, a live key), from the Config area, the op or the
 * command, with no redeploy, and every flip, whichever surface made it, is on the central activity trail. The retired
 * cache override is honoured read-only until the first operator-tier flip.
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

    // The Override Ladder's store (laravel-beam's shared stubs), on the default and central connections.
    foreach ([null, 'central'] as $connection) {
        $default = DB::getDefaultConnection();
        if ($connection !== null) {
            DB::setDefaultConnection($connection);
        }
        try {
            foreach (['create_beam_versions_table', 'create_beam_config_overrides_table', 'create_beam_config_locks_table'] as $stub) {
                if (! Schema::hasTable(Beam::table($stub === 'create_beam_versions_table' ? 'versions' : ($stub === 'create_beam_config_locks_table' ? 'config_locks' : 'config_scopes')))) {
                    (require dirname(__DIR__).'/vendor/splicewire/laravel-beam/database/migrations/shared/'.$stub.'.php.stub')->up();
                }
            }
        } finally {
            DB::setDefaultConnection($default);
        }
    }
    foreach ([ConfigOverrideStore::class, ConfigOverlay::class] as $singleton) {
        app()->forgetInstance($singleton);
    }

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

it('flips through the operator tier of the ladder, live, and the next query sees it', function () {
    $reporting = app(SyntheticReporting::class);

    $state = $reporting->set(true);

    // The flip is an operator-tier override published through the ladder (a live key): the effective config follows.
    expect($state->source)->toBe('override')
        ->and($reporting->override())->toBeTrue()
        ->and($state->configured)->toBeFalse('the deploy-time answer is unchanged: no redeploy')
        ->and(config('beam.tenancy.synthetic.exclude_from_reporting'))->toBeTrue()
        ->and(reportableIds())->toBe(['real-a', 'real-b']);

    // A fresh instance reads the same answer: nothing is held in the object.
    app()->forgetInstance(SyntheticReporting::class);
    expect(app(SyntheticReporting::class)->excludes())->toBeTrue();

    // An explicit include.
    expect($reporting->set(false)->excludes)->toBeFalse()
        ->and(reportableIds())->toBe(allTenantIds())
        ->and($reporting->state()->source)->toBe('override');

    // Clearing hands the answer back to config.
    $state = $reporting->set(null);
    expect($state->override)->toBeNull()
        ->and($state->source)->toBe('config')
        ->and($state->excludes)->toBeFalse()
        ->and(reportableIds())->toBe(allTenantIds());
});

it('honours a value the retired cache override left, read-only, until the first flip clears it', function () {
    cache()->store()->forever(SyntheticReporting::CACHE_KEY, true);
    $reporting = app(SyntheticReporting::class);

    expect($reporting->state()->source)->toBe('legacy-cache')
        ->and($reporting->excludes())->toBeTrue()
        ->and(reportableIds())->toBe(['real-a', 'real-b']);

    $reporting->set(false);
    expect(cache()->store()->get(SyntheticReporting::CACHE_KEY))->toBeNull('the flip clears the retired key')
        ->and($reporting->state()->source)->toBe('override')
        ->and(reportableIds())->toBe(allTenantIds());
});

it('audits a flip published straight through beam-config, with no SyntheticReporting call', function () {
    $operator = SyntheticReportingOperator::create(['id' => (string) Str::uuid(), 'email' => 'op@beam.test']);
    $this->actingAs($operator);

    // The publish is the store saving the scope MODEL with a new published_version (the observer's assumption).
    $saves = 0;
    ConfigOverrideScope::updated(function (ConfigOverrideScope $scope) use (&$saves): void {
        if ($scope->section === SyntheticReporting::ROOT && $scope->wasChanged('published_version')) {
            $saves++;
        }
    });

    $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator);
    $store->saveDraft(SyntheticReporting::ROOT, [['pointer' => SyntheticReporting::POINTER, 'op' => 'set', 'value' => true]]);
    $store->publish(SyntheticReporting::ROOT);

    $row = CentralActivityLog::query()->where('log_name', SyntheticReporting::LOG_NAME)->sole();
    expect($saves)->toBe(1, 'publish saved the scope model, firing updated')
        ->and($row->event)->toBe('set')
        ->and($row->causer_id)->toBe((string) $operator->getKey())
        ->and($row->properties['before']['excludes'])->toBeFalse()
        ->and($row->properties['after']['excludes'])->toBeTrue()
        ->and($row->properties['after']['source'])->toBe('override');
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

it('sees the operator override from inside a tenant, where the cache manager is tenant-tagged', function () {
    app(SyntheticReporting::class)->set(true);

    // Stand up stancl's tenant cache exactly as its bootstrapper does at a host: the harness does not boot
    // stancl's TenancyServiceProvider, so the bootstrapper is run by hand against a current tenant.
    $tenant = Tenant::find('real-a');
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);
    tenancy()->tenant = $tenant;
    tenancy()->initialized = true;
    $bootstrapper = new CacheTenancyBootstrapper(app());
    $bootstrapper->bootstrap($tenant);

    try {
        // CONTROL: the cache manager is now the tenant-tagged one; the answer no longer depends on a cache.
        expect(app('cache'))->toBeInstanceOf(TenantCacheManager::class)
            ->and(app(SyntheticReporting::class)->excludes())->toBeTrue()
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
