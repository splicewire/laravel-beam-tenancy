<?php

namespace Splicewire\Beam\Tenancy\Synthetic\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Tenancy\Data\SyntheticReportingData;
use Splicewire\Beam\Tenancy\Data\SyntheticReportingInputData;
use Splicewire\Beam\Tenancy\Synthetic\SyntheticReporting;

/**
 * Flip whether reporting leaves Synthetic Tenants out, at runtime, with no redeploy. Logged to the central
 * activity trail. Scaffolded with `splicewire:beam:make:particle-op`.
 *
 * `subject: NoSubject::class`: the toggle is one install-wide fact, not a fact about one tenant, so the op mounts
 * at `{uri}/synthetic-reporting` with no `{id}`. Mount it ABOVE the resource's `{id}` routes (the particle
 * doctrine's subject-free-op trap):
 *
 *     Particle::ops('tenants', 'tenants', [SetSyntheticReporting::class]);
 *
 * Gated by `os.operate`, the estate's staff entitlement, on the entitlement plane (`abilityModel: false`). The host
 * decides who holds it. From the command line, `splicewire:beam:tenancy:synthetic-reporting` does the same.
 */
#[ParticleOp(
    resource: 'tenants',
    name: 'synthetic-reporting',
    summary: 'Set whether reporting excludes synthetic tenants',
    description: 'Sets or clears the runtime override behind Tenant::reportable(). Takes effect on the next read, with no redeploy, and is logged to the central activity trail.',
    kind: OperationKind::Write,
    ability: 'os.operate',
    abilityModel: false,
    affordance: false,
    input: SyntheticReportingInputData::class,
    output: SyntheticReportingData::class,
    subject: NoSubject::class,
)]
class SetSyntheticReporting
{
    public static function handle(mixed $model, Request $request, mixed $actor): SyntheticReportingData
    {
        $input = SyntheticReportingInputData::validateAndCreate($request->all());

        return app(SyntheticReporting::class)->set(
            $input->excludeFromReporting,
            $actor instanceof Model ? $actor : null,
        );
    }
}
