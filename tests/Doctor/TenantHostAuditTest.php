<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Tenancy\Doctor\TenantHostAudit;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The read-only look at rows written before the slug grammar (security review, 2026-10-19): which tenants fail it,
 * what is wrong with each, and where their links go now. It reports; it never rewrites.
 */
beforeEach(function () {
    config(['app.url' => 'https://app.splicewire.test', 'tenancy.central_domains' => ['app.splicewire.test']]);

    // stancl's `domains`, as the stub declares it — plus the `verified_at` stamp a custom domain is honoured by.
    Schema::create('domains', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('domain')->unique();
        $table->string('tenant_id');
        $table->boolean('is_primary')->default(false);
        $table->timestamp('verified_at')->nullable();
        $table->timestamps();
    });
});

/** A tenant as the provisioning pipeline leaves it: the row, and its slug bound as the primary domain. */
function auditedTenant(string $slug, ?string $binding = null, bool $verified = false): void
{
    Tenant::create(['id' => $slug, 'name' => 'Audited', 'slug' => $slug]);

    DB::table('domains')->insert([
        'domain' => $binding ?? $slug,
        'tenant_id' => $slug,
        'is_primary' => true,
        'verified_at' => $verified ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('is inconclusive over zero tenants — nothing here is not measured clean', function () {
    $finding = (new TenantHostAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeFalse();
});

it('passes when every slug and binding is one lowercase DNS label', function () {
    auditedTenant('acme');
    auditedTenant('legacy-broker-shop');
    auditedTenant('vanity', 'shop.example.com', verified: true);

    $finding = (new TenantHostAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeTrue()
        ->and($finding->detail)->toContain('3 tenant(s)');
});

it('lists each failing tenant with what is wrong and where its links go now', function () {
    auditedTenant('acme');
    auditedTenant('brk-x@evil.example');
    auditedTenant('brk-x.evil.example');
    auditedTenant('beam_demo');
    auditedTenant('planted', 'planted.evil.example');

    ['checked' => $checked, 'defects' => $defects] = (new TenantHostAudit)->measure();

    expect($checked)->toBe(5)
        ->and(array_keys($defects))->toBe(['beam_demo', 'brk-x.evil.example', 'brk-x@evil.example', 'planted'])
        ->and($defects['brk-x@evil.example'])->toBe([
            "slug 'brk-x@evil.example' is not one lowercase DNS label",
            "binding 'brk-x@evil.example' is a custom domain nothing has verified, so it is never linked",
            'no safe host can be built — its links point at unroutable.invalid',
        ])
        ->and($defects['brk-x.evil.example'])->toContain('no safe host can be built — its links point at unroutable.invalid')
        ->and($defects['beam_demo'])->toBe([
            "slug 'beam_demo' is not one lowercase DNS label",
            "binding 'beam_demo' is not one lowercase DNS label",
            'still linked at beam_demo.app.splicewire.test',
        ])
        ->and($defects['planted'])->toBe([
            "binding 'planted.evil.example' is a custom domain nothing has verified, so it is never linked",
            'still linked at planted.app.splicewire.test',
        ]);

    $finding = (new TenantHostAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Warn)
        ->and($finding->conclusive)->toBeTrue()
        ->and($finding->detail)->toContain('4 of 5 tenant(s)')
        ->and($finding->detail)->not->toContain('acme:');
});

it('reads only — the rows it reports are left exactly as they were', function () {
    auditedTenant('brk-x@evil.example');
    $before = [DB::table('tenants')->get()->toArray(), DB::table('domains')->get()->toArray()];

    (new TenantHostAudit)->run();

    expect([DB::table('tenants')->get()->toArray(), DB::table('domains')->get()->toArray()])->toEqual($before);
});

it('is registered with the doctor as advisory, so host data can never fail a run', function () {
    $registration = collect(app(BeamDoctorManifest::class)->registrations())
        ->firstWhere(fn ($registration) => $registration->audit === TenantHostAudit::class);

    expect($registration)->not->toBeNull()
        ->and($registration->gate)->toBeFalse();
});
