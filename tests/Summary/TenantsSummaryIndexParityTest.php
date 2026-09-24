<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tenancy\Data\TenantData;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The scope-leak property, asserted as a PROPERTY: the `total` figure equals the same actor's index
 * total, read through the frame socket that serves both (realm-dashboards 06a review).
 *
 * `TenantsSummaryProviderTest` pins the figures as numbers — six tenants, three active — which is what a
 * tile must say but not what the scope rule claims. The rule is a relation between two reads, and a
 * hand-written `2` on the summary side stays green if the INDEX drifts to a different reach: the twin
 * numbers agree with each other and with nothing. Here the index is asked, over the same declaration,
 * the same request and the same actor, and the two answers are compared.
 *
 * Exercised the way beam's own `ResourceSummaryTest` exercises it — both reads over HTTP, so the gate,
 * the realm defaults and the request bag are the real ones rather than a container call's.
 */
function parityTenant(string $id, string $status = 'active'): void
{
    Tenant::create([
        'id' => $id,
        'name' => ucfirst($id),
        'slug' => $id,
        'provisioning_status' => $status,
    ]);
}

/** The summary's `total` figure and the index's `total`, for whoever is acting. */
function figureAndIndexTotal(string $key): array
{
    $summary = test()->getJson("frame/resources/{$key}/summary")->assertOk()->json();
    $index = test()->getJson("frame/resources/{$key}")->assertOk()->json();

    return [collect($summary['figures'])->firstWhere('key', 'total')['value'], $index['total']];
}

beforeEach(function () {
    // The `tenants` declaration is registered by this package's provider, which the harness boots. The
    // SCOPED twin is registered here: the neutral declaration carries no `scope` closure, so without one
    // the property below would hold vacuously over the whole table.
    $neutral = AttributedParticleDiscovery::resourceFromAttribute(TenantData::class);

    app(ParticleResourceRegistry::class)->register(new ParticleResource(
        key: 'a-tenants',
        backing: Tenant::class,
        data: TenantData::class,
        // The neutral declaration's own row shape, verbatim: its includes and its `project` closure (the
        // convention-resolved `TenantData::project()`, without which a row cannot be built at all — the
        // Data class takes a `suspended` flag no column carries). The ONLY declared difference between
        // this twin and `tenants` is the `scope` below, or the comparison would be measuring the shape.
        includes: $neutral->includes,
        project: $neutral->project,
        frame: true,
        readOnly: true,
        label: 'A tenants',
        scope: fn (Builder $query) => $query->where('name', 'like', 'A%'),
        summaryProvider: $neutral->summaryProvider,
    ));

    // An operator holding the gate of the realm `tenants` lives in — the one reader a real host serves
    // it to. Neither declaration carries a policy or tenancy, so laravel-beam's read boundary (473fbfd,
    // 49a7612) admits the unscoped `tenants` only through that entitlement. The harness has no host
    // realm map, so it states the operator realm every host declares.
    config(['beam.core.realm_gates' => ['operator' => ['entitlement' => 'os.operate']]]);
    app(ParticleResourceRegistry::class)->loadRealmMap(['operator' => ['tenants', 'a-tenants']]);
    Gate::define('entitlement:os.operate', fn ($user) => $user !== null);

    test()->actingAs((new User)->forceFill(['id' => 1]));
});

it('answers a total figure equal to the same actor\'s index total, through the declared scope', function () {
    parityTenant('acme');
    parityTenant('apex', 'failed');
    parityTenant('globex');

    // The guard against a vacuous pass: the unscoped table must differ from the scoped reach.
    expect(Tenant::count())->toBe(3);

    [$figure, $total] = figureAndIndexTotal('a-tenants');

    expect($figure)->toBe(2)->and($figure)->toBe($total);
});

/**
 * The same property on the UNSCOPED declaration, where the two reads see everything — so a regression
 * that made the summary ignore the scope would be caught above, and one that made the INDEX ignore it
 * cannot hide behind a summary that also does.
 */
it('holds on the neutral declaration too, where both reads see the whole roster', function () {
    parityTenant('acme');
    parityTenant('apex', 'failed');
    parityTenant('globex');

    [$figure, $total] = figureAndIndexTotal('tenants');

    expect($figure)->toBe(3)->and($figure)->toBe($total);
});
