<?php

use Splicewire\Beam\Tenancy\ConfigLadderTenancyBootstrapper;
use Splicewire\Beam\Tenancy\Features\TenantModelCatalog;
use Splicewire\Beam\Tenancy\Features\TenantRoleAlias;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Testing\InteractsWithTenancy;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Events;
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
