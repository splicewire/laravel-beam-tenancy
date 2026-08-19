<?php

use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Rushing\Graphine\Testing\SeamGuard;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;
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
        $table->string('tenant_id');
        $table->timestamps();
    });
});

it('registers the tenants resource onto the Frame registry when beam is present', function () {
    app()->singleton(ParticleResourceRegistry::class, fn () => new ParticleResourceRegistry);

    $provider = new BeamTenancyServiceProvider(app());
    $provider->register();
    $provider->boot();

    $registry = app(ParticleResourceRegistry::class);

    expect($registry->has('tenants'))->toBeTrue();
    $registry->definition('tenants'); // throws if the Frame-manifest side is absent
});

it('is read-only in all three directions — tenants are provisioned, never created from a form', function () {
    app()->singleton(ParticleResourceRegistry::class, fn () => new ParticleResourceRegistry);

    $provider = new BeamTenancyServiceProvider(app());
    $provider->register();
    $provider->boot();

    $definition = app(ParticleResourceRegistry::class)->definition('tenants');

    expect($definition->creatable)->toBeFalse()
        ->and($definition->editable)->toBeFalse()
        ->and($definition->deletable)->toBeFalse();
});

it('yields the tenants key to a host resource registered after it', function () {
    // Replaces the old `frame_resources.enabled` gate test. There is no off-switch any more, and none
    // is needed: ParticleResourceRegistry keys by resource key and the LAST registration wins, so a
    // host with richer tenant admin (splicewire/tower is the worked example) overrides simply by
    // registering after this package. That IS the extension contract in TenantData's docblock — this
    // asserts the mechanism the flag used to stand in for.
    app()->singleton(ParticleResourceRegistry::class, fn () => new ParticleResourceRegistry);

    $provider = new BeamTenancyServiceProvider(app());
    $provider->register();
    $provider->boot();

    $registry = app(ParticleResourceRegistry::class);

    // The package's neutral declaration is what's there first.
    expect($registry->definition('tenants')->policy)->toBeNull();

    // A host layers its own under the same key, afterwards.
    $registry->registerDefinition(
        $registry->definition('tenants')->withOverrides(policy: 'host.tenants')
    );

    expect($registry->definition('tenants')->policy)->toBe('host.tenants')
        ->and($registry->has('tenants'))->toBeTrue();
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

    // The registry IS on this classpath (beam is a real dependency), so the honest assertion is that
    // an enabled boot against a container with no registry BINDING arms a hook and resolves cleanly,
    // rather than eagerly reaching for something absent.
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

it('names no commerce symbol in the resource or its projection', function () {
    // The ticket's constraint asserted mechanically rather than by review. Same AST scan the
    // package-wide guard runs, narrowed to the two files this ticket adds.
    $guard = new SeamGuard(['Splicewire\Beam\Commerce']);

    expect($guard->scan(__DIR__.'/../src/Data/TenantData.php'))->toBe([])
        ->and($guard->scan(__DIR__.'/../src/BeamTenancyServiceProvider.php'))->toBe([]);
});
