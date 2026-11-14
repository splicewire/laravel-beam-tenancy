<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tenancy\Data\CreateTenantData;
use Splicewire\Beam\Tenancy\Data\TenantData;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantSlug;

beforeEach(function () {
    config(['app.debug' => false, 'beam.localization.pseudo' => true, 'beam.localization.supported' => ['en', 'en-XA'], 'beam.localization.http_profile' => true, 'beam.core.realm_gates' => ['operator' => ['entitlement' => 'os.operate']]]);
    app(ParticleResourceRegistry::class)->loadRealmMap(['operator' => ['tenants', 'localization-tenant-mechanism']]);
    Gate::define('entitlement:os.operate', fn ($user) => $user !== null);
    $this->actingAs((new User)->forceFill(['id' => 1]));
});

it('renders the actual summary socket with exact English and localized state labels without altering figures', function () {
    foreach ([['acme', 'active', false], ['globex', 'active', false], ['initech', 'active', true], ['hooli', 'pending', false], ['umbrella', 'provisioning', false], ['wonka', 'failed', false]] as [$id, $status, $suspended]) {
        Tenant::create(['id' => $id, 'slug' => $id, 'name' => ucfirst($id), 'provisioning_status' => $status, 'suspended_at' => $suspended ? now()->toIso8601String() : null]);
    }
    expect(Tenant::count())->toBe(6);
    $english = $this->getJson('frame/resources/tenants/summary', ['Accept-Language' => 'en'])->assertOk()->json();
    expect($english)->toBe([
        'key' => 'tenants', 'label' => 'Tenants', 'icon' => 'building',
        'figures' => [
            ['key' => 'total', 'label' => 'Tenants', 'value' => 6, 'tone' => null],
            ['key' => 'active', 'label' => 'Active', 'value' => 3, 'tone' => 'active'],
            ['key' => 'provisioning', 'label' => 'Provisioning', 'value' => 2, 'tone' => 'busy'],
            ['key' => 'suspended', 'label' => 'Suspended', 'value' => 1, 'tone' => 'warn'],
            ['key' => 'failed', 'label' => 'Failed', 'value' => 1, 'tone' => 'danger'],
        ], 'overview' => null,
    ]);
    $pseudo = $this->getJson('frame/resources/tenants/summary', ['Accept-Language' => 'en-XA'])->assertOk()->json();
    expect(array_column($pseudo['figures'], 'key'))->toBe(['total', 'active', 'provisioning', 'suspended', 'failed']);
    foreach ($pseudo['figures'] as $index => $figure) {
        expect($figure['value'])->toBe($english['figures'][$index]['value'])
            ->and($figure['tone'])->toBe($english['figures'][$index]['tone']);
        if ($figure['key'] !== 'total') {
            expect(PseudoLocale::isMarked($figure['label']))->toBeTrue();
        }
    }
});

it('proves generic Particle input aggregation preserves translated grammar and explicit codes', function () {
    // TEST-ONLY mechanism fixture: production tenants is read-only and uses editData for host schemas.
    // Current Beam 6a740e1 ParticleController:738-746 preserves nonempty coded metadata.
    // This generic fixture and the owning REST DTO are distinct from production read-only tenants.
    app(ParticleResourceRegistry::class)->register(new ParticleResource(
        key: 'localization-tenant-mechanism', backing: Tenant::class, data: TenantData::class,
        input: CreateTenantData::class, frame: true, label: 'Tenant input mechanism',
    ));
    Gate::before(fn () => true);
    $payload = ['slug' => 'bad.slug', 'storage' => 'isolated'];
    $english = $this->postJson('frame/resources/localization-tenant-mechanism', $payload, ['Accept-Language' => 'en'])->assertUnprocessable()->json();
    expect(array_keys($english))->toBe(['message', 'errors', 'codes'])
        ->and(array_keys($english['errors']))->toBe(['slug', 'storage'])
        ->and($english['errors']['slug'])->toBe([TenantSlug::RULE])
        ->and($english['codes'])->toBe(['slug' => ['tenancy_invalid_slug']]);
    $pseudo = $this->postJson('frame/resources/localization-tenant-mechanism', $payload, ['Accept-Language' => 'en-XA'])->assertUnprocessable()->json();
    expect(array_keys($pseudo['errors']))->toBe(['slug', 'storage'])
        ->and(PseudoLocale::isMarked($pseudo['errors']['slug'][0]))->toBeTrue()
        ->and($pseudo['codes'])->toBe(['slug' => ['tenancy_invalid_slug']])
        ->and(Tenant::count())->toBe(0);
    $plain = $this->postJson('frame/resources/localization-tenant-mechanism', ['slug' => 'valid-slug', 'storage' => 'isolated'], ['Accept-Language' => 'en'])->assertUnprocessable()->json();
    expect(array_keys($plain))->toBe(['message', 'errors'])
        ->and(array_keys($plain['errors']))->toBe(['storage'])
        ->and($plain)->not->toHaveKey('codes')
        ->and(Tenant::count())->toBe(0);
});

it('keeps the actual production tenants declaration read-only rather than turning its schema DTO into a writer', function () {
    $resource = AttributedParticleDiscovery::resourceFromAttribute(TenantData::class);
    expect($resource->readOnly)->toBeTrue()->and($resource->editData)->toBe(CreateTenantData::class);
    $this->postJson('frame/resources/tenants', ['slug' => 'new-tenant'])->assertStatus(403);
    expect(Tenant::count())->toBe(0);
});
