<?php

use Illuminate\Database\Eloquent\Builder;
use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Tenancy\Data\TenantData;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The `tenants` resource's summary — five figures grouped by provisioning state (realm-dashboards 06).
 *
 * Exercised the way frame's summary route exercises it: the declaration is discovered into the particle
 * registry, its frame definition names a provider, the container makes it, and the wire it answers is
 * asserted whole. Nothing here names the provider class — the declaration does, and a test that spelled
 * it again would stay green through the one failure that matters (the slot no longer reaching the
 * definition).
 *
 * The harness does not boot beam-core, so the two collaborators `ScopedIndexQuery` takes are bound here
 * exactly as beam binds them: the registry as a pinned instance (a fresh one per `app()` call would make
 * discovery write into an object the query never reads) and the shipped degenerate reader as the hydrator.
 */
beforeEach(function () {
    app()->instance(ParticleResourceRegistry::class, new ParticleResourceRegistry);
    app()->bind(ParticleHydrator::class, PayloadParticleReader::class);

    app(AttributedParticleDiscovery::class)->discover([TenantData::class]);
});

/** A tenant row with the provisioning state and suspension the figures group by. */
function tenantRow(string $id, string $status, bool $suspended = false): void
{
    Tenant::create([
        'id' => $id,
        'name' => ucfirst($id),
        'slug' => $id,
        'provisioning_status' => $status,
        'suspended_at' => $suspended ? now()->toIso8601String() : null,
    ]);
}

/** The summary the declaration's OWN provider answers for a definition, resolved as frame resolves it. */
function summaryOf(ResourceDefinition $definition): array
{
    $provider = app($definition->summaryProvider);

    expect($provider)->toBeInstanceOf(ResourceSummaryProvider::class);

    return $provider->summary($definition)->toArray();
}

it('groups the roster into total, active, provisioning, suspended and failed', function () {
    tenantRow('acme', 'active');
    tenantRow('globex', 'active');
    tenantRow('initech', 'active', suspended: true);
    tenantRow('hooli', 'pending');
    tenantRow('umbrella', 'provisioning');
    tenantRow('wonka', 'failed');

    $summary = summaryOf(app(ParticleResourceRegistry::class)->definition('tenants'));

    expect($summary['key'])->toBe('tenants')
        ->and($summary['label'])->toBe('Tenants')
        ->and($summary['icon'])->toBe('building')
        ->and($summary['overview'])->toBeNull()
        // The whole wire, in display order, with the tones a stat tile maps to its tokens. `suspended`
        // is orthogonal to the provisioning axis (initech is BOTH active and suspended), which is why
        // the four state figures do not sum to `total` and why it is its own figure.
        ->and($summary['figures'])->toBe([
            ['key' => 'total', 'label' => 'Tenants', 'value' => 6, 'tone' => null],
            ['key' => 'active', 'label' => 'Active', 'value' => 3, 'tone' => 'active'],
            ['key' => 'provisioning', 'label' => 'Provisioning', 'value' => 2, 'tone' => 'busy'],
            ['key' => 'suspended', 'label' => 'Suspended', 'value' => 1, 'tone' => 'warn'],
            ['key' => 'failed', 'label' => 'Failed', 'value' => 1, 'tone' => 'danger'],
        ]);
});

it('reports honest zeroes on an empty platform', function () {
    $figures = collect(summaryOf(app(ParticleResourceRegistry::class)->definition('tenants'))['figures']);

    expect($figures->pluck('value', 'key')->all())
        ->toBe(['total' => 0, 'active' => 0, 'provisioning' => 0, 'suspended' => 0, 'failed' => 0]);
});

/**
 * The scope-leak property: the figures are counted through the declaration's `scope()` gate — the same
 * builder the index reads — not off the bare table. The neutral `tenants` declaration carries no scope,
 * so a copy of it that does is registered under its own key; if the provider ever counted
 * `Tenant::query()` directly, this declaration's tile would show the global roster.
 */
it('counts through the declared scope, never the bare table', function () {
    tenantRow('acme', 'active');
    tenantRow('apex', 'failed');
    tenantRow('globex', 'active');

    $neutral = AttributedParticleDiscovery::resourceFromAttribute(TenantData::class);

    app(ParticleResourceRegistry::class)->register(new ParticleResource(
        key: 'a-tenants',
        backing: Tenant::class,
        data: TenantData::class,
        frame: true,
        label: 'A tenants',
        scope: fn (Builder $query) => $query->where('name', 'like', 'A%'),
        summaryProvider: $neutral->summaryProvider,
    ));

    expect(Tenant::count())->toBe(3, 'The unscoped table must differ from the scoped reach, or this test cannot fail.');

    $figures = collect(summaryOf(app(ParticleResourceRegistry::class)->definition('a-tenants'))['figures'])
        ->pluck('value', 'key');

    expect($figures['total'])->toBe(2)
        ->and($figures['active'])->toBe(1)
        ->and($figures['failed'])->toBe(1);
});
