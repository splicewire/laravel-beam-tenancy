<?php

use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Tenancy\Data\TenantData;

// ux-walkthrough UX-09 (IA-10; lead 09:01Z ruling 2): Tenants sits in the operator rail's Tenants task section, which
// splicewire/tower declares, first in it. It was the generic 'platform' seat every starter's OperatorRailSeat drew.
it('places tenants first in the Tenants task section', function () {
    $resource = (new ReflectionClass(TenantData::class))->getAttributes(ParticleResource::class)[0]->newInstance();

    expect([$resource->section, $resource->navOrder])->toBe(['tenants', 1]);
});
