<?php

use Illuminate\Config\Repository;
use Splicewire\Beam\Tenancy\ConfigLadderTenancyBootstrapper;
use Splicewire\Beam\Tenancy\Features\TenantModelCatalog;
use Splicewire\Beam\Tenancy\Features\TenantRoleAlias;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Testing\InteractsWithTenancy;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Features\TenantConfig;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Tenancy;

/**
 * config-sections ticket 07: adding `ConfigLadderTenancyBootstrapper` to the bootstrapper list leaves the tenancy
 * FEATURES as they were. `TenantModelCatalog` and `TenantRoleAlias` run on `TenancyBootstrapped`, which stancl fires after
 * every bootstrapper; the role alias still runs after the catalog merge, so a role that resolves only to a TENANT
 * catalog model expands, and both revert at the end of the hop.
 */
beforeEach(function () {
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);
    Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
    Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);

    config()->set('cache.default', 'array');
    config()->set('tenancy.cache.tag_base', 'tenant');
    config()->set('app.chat.models', ['floor-model' => ['label' => 'Floor']]);
    config()->set('app.chat.default_model', 'floor-model');
});

it('lists the ladder bootstrapper after the cache bootstrapper in the shared harness', function () {
    $harness = new class
    {
        use InteractsWithTenancy;

        /** @return list<class-string> */
        public function list(): array
        {
            return $this->tenancyBootstrappers();
        }
    };
    $list = $harness->list();

    expect(array_search(ConfigLadderTenancyBootstrapper::class, $list, true))
        ->toBe(array_search(CacheTenancyBootstrapper::class, $list, true) + 1);
});

it('keeps the role alias running after the catalog merge with the ladder bootstrapper listed', function () {
    config()->set('tenancy.bootstrappers', [ConfigLadderTenancyBootstrapper::class]);
    foreach ([TenantModelCatalog::class, TenantRoleAlias::class] as $feature) {
        app($feature)->bootstrap(tenancy());
    }

    $tenant = Tenant::create(['id' => 'acme', 'slug' => 'acme', 'llm_config' => [
        'preferred_model' => 'fast',
        'provider' => 'acme-llm',
        'models' => ['tenant-only-model' => ['label' => 'Tenant only']],
        'roles' => ['acme-llm' => ['fast' => 'tenant-only-model']],
    ]]);

    tenancy()->initialize($tenant);

    // `tenant-only-model` is in the tenant catalog alone: the alias could only validate it after the merge.
    expect(config('app.chat.models'))->toHaveKeys(['tenant-only-model', 'floor-model'])
        ->and(config('app.chat.default_model'))->toBe('tenant-only-model');

    tenancy()->end();

    expect(config('app.chat.models'))->toBe(['floor-model' => ['label' => 'Floor']])
        ->and(config('app.chat.default_model'))->toBe('floor-model');
});

/*
 * Ticket 15's AC4 variant deferred to 07 (lead 11:36Z): 15 landed on main, so its role-alias revert and boot-time order
 * refusal now run beside 07's ladder bootstrapper. With the bootstrapper listed AND stancl's TenantConfig mapping
 * preferred_model, as the flagship lists the features (TenantConfig, TenantModelCatalog, TenantRoleAlias), the boot does
 * not refuse, the role expands in the hop, central's default model is restored after it, and the next tenant sees the
 * floor. The shared harness's own feature list (InteractsWithTenancy) passes the same order check.
 */
it('keeps a tenant\'s role alias out of the central config with the ladder bootstrapper AND stancl TenantConfig listed', function () {
    $features = [TenantConfig::class, TenantModelCatalog::class, TenantRoleAlias::class];
    config()->set('tenancy.bootstrappers', [ConfigLadderTenancyBootstrapper::class]);
    config()->set('tenancy.features', $features);
    $previousMap = TenantConfig::$storageToConfigMap;
    TenantConfig::$storageToConfigMap = ['llm_config.preferred_model' => 'app.chat.default_model'];

    try {
        // 15's boot-time order check passes for the flagship order (it throws a LogicException otherwise).
        foreach ($features as $feature) {
            app($feature)->bootstrap(tenancy());
        }

        $role = Tenant::create(['id' => 'acme', 'slug' => 'acme', 'llm_config' => [
            'preferred_model' => 'fast',
            'provider' => 'acme-llm',
            'models' => ['tenant-only-model' => ['label' => 'Tenant only']],
            'roles' => ['acme-llm' => ['fast' => 'tenant-only-model']],
        ]]);
        $plain = Tenant::create(['id' => 'plain', 'slug' => 'plain', 'llm_config' => ['provider' => 'acme-llm']]);

        tenancy()->initialize($role);
        expect(config('app.chat.default_model'))->toBe('tenant-only-model', 'inside: the role expands after the catalog merge');
        tenancy()->end();

        expect(config('app.chat.default_model'))->toBe('floor-model', 'central after the hop: no leak with the ladder bootstrapper listed')
            ->and(config('app.chat.models'))->toBe(['floor-model' => ['label' => 'Floor']]);

        tenancy()->initialize($plain);
        expect(config('app.chat.default_model'))->toBe('floor-model', 'the next tenant sees the floor');
        tenancy()->end();
    } finally {
        TenantConfig::$storageToConfigMap = $previousMap;
    }
});

it('lists the features in the order 15\'s boot check requires, in the shared harness', function () {
    // InteractsWithTenancy::defineTenancyConfig() writes the shared harness's tenancy config; capture it in a scratch
    // repository and boot the role alias against that feature list (15 refuses at boot if TenantConfig comes after it).
    $harness = new class
    {
        use InteractsWithTenancy;

        public function define(Repository $config): void
        {
            $this->defineTenancyConfig(['config' => $config]);
        }
    };
    $scratch = new Repository;
    $harness->define($scratch);
    $features = (array) $scratch->get('tenancy.features');

    expect(array_search(TenantConfig::class, $features, true))
        ->toBeLessThan(array_search(TenantRoleAlias::class, $features, true))
        ->and(array_search(ConfigLadderTenancyBootstrapper::class, (array) $scratch->get('tenancy.bootstrappers'), true))->not->toBeFalse();

    config()->set('tenancy.features', $features);
    app(TenantRoleAlias::class)->bootstrap(tenancy());
});
