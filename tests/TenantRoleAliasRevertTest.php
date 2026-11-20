<?php

use Illuminate\Config\Repository;
use Splicewire\Beam\Tenancy\Features\TenantModelCatalog;
use Splicewire\Beam\Tenancy\Features\TenantRoleAlias;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Features\TenantConfig;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Tenancy;

/**
 * config-sections 15 (HIGH): a tenant's role alias must never leak into the CENTRAL config.
 *
 * The regression is credited to 07's evidence probe (Cs07RoleAliasLeakProbeTest, from 09's read-only prep). In the
 * flagship's feature order (stancl TenantConfig mapping llm_config.preferred_model -> app.chat.default_model, then
 * TenantModelCatalog, then TenantRoleAlias), a role-picking tenant left its role string ('fast') in central
 * app.chat.default_model after its hop. Both features reverted on RevertedToCentralContext in features order:
 * TenantConfig restored central first, then TenantRoleAlias wrote back the "original" it had captured, which TenantConfig
 * had already overwritten with the tenant's role.
 *
 * Every case runs stancl's real event flow (initialize / end, as a request, a queued job or an Octane request does).
 */
beforeEach(function () {
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);
    Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
    Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);

    config()->set('cache.default', 'array');
    config()->set('tenancy.bootstrappers', []);
    config()->set('app.chat.models', ['floor-model' => ['label' => 'Floor'], 'floor-fast' => ['label' => 'Floor fast']]);
    config()->set('app.chat.default_model', 'floor-model');

    $this->previousMap = TenantConfig::$storageToConfigMap;
    TenantConfig::$storageToConfigMap = ['llm_config.preferred_model' => 'app.chat.default_model'];
});

afterEach(function () {
    TenantConfig::$storageToConfigMap = $this->previousMap;
});

/** @param  list<class-string>  $features  in the host's list order */
function cs15Features(array $features): void
{
    foreach ($features as $feature) {
        app($feature)->bootstrap(tenancy());
    }
}

function cs15RoleTenant(string $id, string $resolvesTo = 'tenant-only-model'): Tenant
{
    return Tenant::create(['id' => $id, 'slug' => $id, 'llm_config' => [
        'preferred_model' => 'fast',
        'provider' => 'acme-llm',
        'models' => ['tenant-only-model' => ['label' => 'Tenant only']],
        'roles' => ['acme-llm' => ['fast' => $resolvesTo]],
    ]]);
}

function cs15PlainTenant(string $id): Tenant
{
    return Tenant::create(['id' => $id, 'slug' => $id, 'llm_config' => ['provider' => 'acme-llm']]);
}

/** What config() holds inside `$tenant`'s hop: [default_model, models keys]. */
function cs15Inside(Tenant $tenant): array
{
    tenancy()->initialize($tenant);

    try {
        return [config('app.chat.default_model'), array_keys((array) config('app.chat.models'))];
    } finally {
        tenancy()->end();
    }
}

const CS15_FLAGSHIP = [TenantConfig::class, TenantModelCatalog::class, TenantRoleAlias::class];

/** config-sections 09's feature list: stancl TenantConfig is gone; the ladder's tenant tier writes the default model. */
const CS09_LADDER_FEATURES = [TenantModelCatalog::class, TenantRoleAlias::class];

/** Stand in for 07's ConfigLadderTenancyBootstrapper: it lays `llm_config.ladder_default_model` in the bootstrap phase. */
function cs09Ladder(): void
{
    config()->set('tenancy.bootstrappers', [Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias\AppliesTenantDefaultModelBootstrapper::class]);
    Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias\AppliesTenantDefaultModelBootstrapper::$found = null;
}

/** A tenant whose ladder tier picks `$laddered` (a role or a model), with the role map that expands `fast`. */
function cs09LadderTenant(string $id, string $laddered = 'fast', ?string $legacyPreferred = null): Tenant
{
    return Tenant::create(['id' => $id, 'slug' => $id, 'llm_config' => array_filter([
        'ladder_default_model' => $laddered,
        'preferred_model' => $legacyPreferred,
        'provider' => 'acme-llm',
        'models' => ['tenant-only-model' => ['label' => 'Tenant only']],
        'roles' => ['acme-llm' => ['fast' => 'tenant-only-model']],
    ], fn ($v) => $v !== null)]);
}

// Regression credited to 07's evidence probe (Cs07RoleAliasLeakProbeTest): the PROBE case, red on laravel-beam-tenancy ce8ed6f.
it('restores central default_model after a role-picking tenant\'s hop in the flagship feature order', function () {
    cs15Features(CS15_FLAGSHIP);

    [$inside, $models] = cs15Inside(cs15RoleTenant('acme'));
    expect($inside)->toBe('tenant-only-model', 'inside the hop the role still expands, after the catalog merge')
        ->and($models)->toContain('tenant-only-model', 'floor-model');

    expect(config('app.chat.default_model'))->toBe('floor-model', 'central after the hop')
        ->and(config('app.chat.models'))->toBe(['floor-model' => ['label' => 'Floor'], 'floor-fast' => ['label' => 'Floor fast']]);
});

it('CONTROL: without stancl TenantConfig the role alias also restores central', function () {
    // config-sections 09: with TenantConfig gone, the role reaches app.chat.default_model through the ladder's tenant tier.
    cs09Ladder();
    cs15Features(CS09_LADDER_FEATURES);

    expect(cs15Inside(cs09LadderTenant('acme'))[0])->toBe('tenant-only-model')
        ->and(config('app.chat.default_model'))->toBe('floor-model');
});

/*
 * config-sections 09 (review Q3): TenantConfig leaves tenancy.features, so the alias (a) boots without it and (b) expands
 * the EFFECTIVE app.chat.default_model, i.e. the value the ladder's tenant tier laid, not the tenant's legacy DATA.
 */
it('boots with no stancl TenantConfig in tenancy.features (09\'s feature list): the guard tolerates its absence', function () {
    config()->set('tenancy.features', CS09_LADDER_FEATURES);

    app(TenantRoleAlias::class)->bootstrap(tenancy());

    expect(true)->toBeTrue('no LogicException: there is no TenantConfig to come after');
});

it('expands a role the ladder\'s tenant tier laid, and restores central for the next tenant', function () {
    cs09Ladder();
    cs15Features(CS09_LADDER_FEATURES);

    expect(cs15Inside(cs09LadderTenant('alpha'))[0])->toBe('tenant-only-model', 'the laddered role expands')
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'central after the hop')
        ->and(cs15Inside(cs15PlainTenant('bravo'))[0])->toBe('floor-model', 'the next tenant never sees it')
        ->and(cs15Inside(cs09LadderTenant('gamma', 'floor-fast'))[0])->toBe('floor-fast', 'a laddered concrete model is left alone')
        ->and(config('app.chat.default_model'))->toBe('floor-model');
});

it('expands the EFFECTIVE value: a tenant\'s published model beats a stale legacy role left in its data', function () {
    // The tenant once picked the role `fast` (legacy data); its ladder tier now publishes a concrete model. Reading data,
    // the alias would overwrite the published choice with the expanded stale role.
    cs09Ladder();
    cs15Features(CS09_LADDER_FEATURES);

    expect(cs15Inside(cs09LadderTenant('acme', 'floor-fast', legacyPreferred: 'fast'))[0])->toBe('floor-fast')
        ->and(config('app.chat.default_model'))->toBe('floor-model');
});

it('restores central even when the role resolves to the central default model itself', function () {
    // An order-blind "restore only if the value is still mine" would leak here: the expanded model equals central's.
    config()->set('app.chat.default_model', 'floor-fast');
    cs15Features(CS15_FLAGSHIP);

    expect(cs15Inside(cs15RoleTenant('acme', 'floor-fast'))[0])->toBe('floor-fast')
        ->and(config('app.chat.default_model'))->toBe('floor-fast', 'never the role string');
});

it('keeps each tenant on one worker to its own value: a role tenant, a plain tenant, a concrete tenant, then central', function () {
    // A queue worker runs exactly this sequence: stancl initializes tenancy for each job and ends it after.
    cs15Features(CS15_FLAGSHIP);
    $concrete = Tenant::create(['id' => 'gamma', 'slug' => 'gamma', 'llm_config' => ['preferred_model' => 'floor-fast', 'provider' => 'acme-llm']]);

    expect(cs15Inside(cs15RoleTenant('alpha'))[0])->toBe('tenant-only-model')
        ->and(cs15Inside(cs15PlainTenant('bravo'))[0])->toBe('floor-model', 'the next tenant never sees another tenant\'s role')
        ->and(cs15Inside($concrete)[0])->toBe('floor-fast')
        ->and(cs15Inside(cs15RoleTenant('delta'))[0])->toBe('tenant-only-model')
        ->and(cs15Inside(cs15PlainTenant('echo'))[0])->toBe('floor-model')
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'central after the worker\'s jobs');
});

/*
 * Review 05:44Z (AC4): the feature-order variants. Relative to stancl's TenantConfig, AFTER is required: listed before it,
 * the hop shows the raw role and TenantConfig captures the expanded model as its own "original" (the vendor capture the
 * PREDICTED LEAK case below also hits). So that order is REFUSED at boot. Relative to TenantModelCatalog the revert is
 * correct either way. In the variants the role resolves to a FLOOR model, since the alias can only validate a tenant-only
 * model after the catalog merge.
 */
it('refuses, at boot, a feature list that puts TenantRoleAlias BEFORE stancl TenantConfig', function () {
    config()->set('tenancy.features', [TenantRoleAlias::class, TenantConfig::class, TenantModelCatalog::class]);

    expect(fn () => app(TenantRoleAlias::class)->bootstrap(tenancy()))
        ->toThrow(LogicException::class, 'the role alias must come after it');

    config()->set('tenancy.features', CS15_FLAGSHIP);
    app(TenantRoleAlias::class)->bootstrap(tenancy());
    expect(true)->toBeTrue('the flagship order boots');
});

it('refuses the same order when the host lists its own SUBCLASS of TenantConfig', function () {
    config()->set('tenancy.features', [TenantRoleAlias::class, Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias\HostTenantConfig::class, TenantModelCatalog::class]);

    expect(fn () => app(TenantRoleAlias::class)->bootstrap(tenancy()))
        ->toThrow(LogicException::class, 'the role alias must come after it');

    config()->set('tenancy.features', [Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias\HostTenantConfig::class, TenantModelCatalog::class, TenantRoleAlias::class]);
    app(TenantRoleAlias::class)->bootstrap(tenancy());
    expect(true)->toBeTrue('the subclass listed first boots');
});

it('shows why that order is refused: listed before TenantConfig, the alias never sees the role, so the hop runs on it raw', function () {
    // tenancy.features unset, so nothing refuses: the features are bootstrapped by hand in the refused order.
    cs15Features([TenantRoleAlias::class, TenantConfig::class, TenantModelCatalog::class]);

    // config-sections 09: the alias expands the EFFECTIVE value, and listed first it reads it before TenantConfig maps
    // the role. So it expands nothing: the hop runs on the raw role (still broken, hence the refusal), but TenantConfig
    // no longer captures an expanded model as "central" (before 09 it did: central became 'floor-fast').
    expect(cs15Inside(cs15RoleTenant('acme', 'floor-fast'))[0])->toBe('fast', 'inside: the raw role, never expanded')
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'after: central intact');
});

it('restores central with TenantRoleAlias listed BEFORE TenantModelCatalog', function () {
    cs15Features([TenantConfig::class, TenantRoleAlias::class, TenantModelCatalog::class]);

    expect(cs15Inside(cs15RoleTenant('acme', 'floor-fast'))[0])->toBe('floor-fast', 'a floor-model role still expands')
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'central after the hop')
        ->and(cs15Inside(cs15PlainTenant('bravo'))[0])->toBe('floor-model')
        ->and(config('app.chat.models'))->toBe(['floor-model' => ['label' => 'Floor'], 'floor-fast' => ['label' => 'Floor fast']]);
});

/*
 * Review 05:44Z (AC5): a PREDICTED leak, tied to 07 + 09, documented here as it stands. stancl's TenantConfig keeps the
 * FIRST "original" it ever sees, for the whole process (`$originalConfig[$key] ??= …`, never cleared;
 * vendor/stancl/tenancy/src/Features/TenantConfig.php:53-58), and writes every one back on every revert. Once 07's
 * ConfigLadderTenancyBootstrapper applies a tenant's tier to a key TenantConfig also maps (app.chat.default_model), the
 * bootstrapper writes it in the BOOTSTRAP phase, before TenantConfig captures on TenancyBootstrapped. TenantConfig then
 * takes the TENANT's value as "central" and restores it after every hop. This case DOCUMENTS stancl's capture behaviour
 * (it asserts the leak as stancl produces it, with a fixture bootstrapper standing in for 07's). It cannot enforce 09's
 * fix, since it lists TenantConfig itself; the 07 + 09 interaction is carried as an acceptance item on ticket 09, whose
 * removal of TenantConfig's mapping closes it.
 *
 * config-sections 09: this case is KEPT unchanged, as the record of what stancl's TenantConfig does to any host that still
 * lists it beside the ladder. The CLOSED case after it runs the same tenant on 09's feature list (no TenantConfig) and
 * pins central clean; the app's AC6 host test checks the real list.
 */
it('PREDICTED LEAK (07 + 09): stancl TenantConfig captures a bootstrapper-applied tenant value as central, and restores it', function () {
    config()->set('tenancy.bootstrappers', [Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias\AppliesTenantDefaultModelBootstrapper::class]);
    Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias\AppliesTenantDefaultModelBootstrapper::$found = null;
    cs15Features(CS15_FLAGSHIP);

    // A tenant whose config tier sets the default model (the ladder's job) AND whose legacy preferred_model is mapped by
    // TenantConfig: both write app.chat.default_model.
    $tenant = Tenant::create(['id' => 'ladder', 'slug' => 'ladder', 'llm_config' => ['ladder_default_model' => 'ladder-tenant-model', 'preferred_model' => 'floor-fast', 'provider' => 'acme-llm']]);

    expect(cs15Inside($tenant)[0])->toBe('floor-fast', 'inside: TenantConfig\'s mapped value');
    expect(config('app.chat.default_model'))->toBe('ladder-tenant-model', 'THE LEAK: TenantConfig restored the tenant\'s ladder value as "central"')
        ->and(cs15Inside(cs15PlainTenant('bravo'))[0])->toBe('ladder-tenant-model', 'and the next tenant inherits it');
});

it('CLOSED (09): with stancl TenantConfig out of tenancy.features, the same ladder tenant leaves central untouched', function () {
    // The PREDICTED LEAK case above, on 09's feature list: nothing captures the bootstrapper's value as "central".
    cs09Ladder();
    cs15Features(CS09_LADDER_FEATURES);

    $tenant = Tenant::create(['id' => 'ladder', 'slug' => 'ladder', 'llm_config' => ['ladder_default_model' => 'ladder-tenant-model', 'preferred_model' => 'floor-fast', 'provider' => 'acme-llm']]);

    expect(cs15Inside($tenant)[0])->toBe('ladder-tenant-model', 'inside: the ladder\'s value, the legacy data unmapped')
        ->and(config('app.chat.default_model'))->toBe('floor-model', 'central after the hop: no leak')
        ->and(cs15Inside(cs15PlainTenant('bravo'))[0])->toBe('floor-model', 'and the next tenant sees central');
});

it('writes the CURRENT request\'s config, never the worker base, under Octane-style app reuse', function () {
    // Octane hands each request a CLONE of the worker's config. The features are bootstrapped once, at boot, against the
    // base. The role alias and the catalog must act on the request's clone and leave the base exactly as it was.
    // (stancl's own TenantConfig still holds the base repository; it is left out here and named in the audit.)
    cs09Ladder();
    cs15Features(CS09_LADDER_FEATURES);
    $base = app('config');
    $before = $base->all();

    $request = new Repository($base->all());
    app()->instance('config', $request);

    try {
        expect(cs15Inside(cs09LadderTenant('acme'))[0])->toBe('tenant-only-model', 'the request\'s clone expands the role')
            ->and($request->get('app.chat.default_model'))->toBe('floor-model', 'and is restored after the hop');
    } finally {
        app()->instance('config', $base);
    }

    expect($base->all())->toBe($before, 'the worker base is byte-for-byte untouched');
});
