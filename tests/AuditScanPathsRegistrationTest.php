<?php

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Splicewire\Beam\Surgeon\AuditScanPaths;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;

/**
 * The audit scan-path contribution: when a host binds beam-core's {@see AuditScanPaths} singleton,
 * this provider pushes its controllers directory at boot, so `src/Http` joins the
 * bypass/redundancy/house-style sweeps.
 *
 * This is a regression guard with a specific miss behind it. Until this registration existed,
 * `TenantMemberController` — a REST survivor of the ADR-0156 fold, sitting right next to a registered
 * `members` resource — was structurally invisible to `particle.controller-redundant`: the sweep only
 * ever sees what a booted provider contributes, so a package that never registers is silently exempt
 * rather than silently clean. Deleting the registration would restore that blind spot.
 *
 * Unlike beam-commerce's equivalent, the registered routes dir does not exist — this package declares
 * controllers and the consuming host mounts them. `AuditScanPaths::register()` documents that as fine
 * (the audits treat an absent dir as empty), and the assertion below pins it deliberately so nobody
 * later "fixes" it by pointing at another package's routes.
 */
beforeEach(function () {
    // Stands in for a host that composes beam-core: binds only the accumulator singleton. The base
    // TestCase does not boot beam-core, so this also proves the guard — registration keys purely on
    // the singleton being bound.
    $this->app->singleton(AuditScanPaths::class);

    $provider = new BeamTenancyServiceProvider($this->app);
    $provider->register();
    $provider->boot();
});

it('contributes its Http dir to the audit seam', function () {
    $ours = array_values(array_filter(
        $this->app->make(AuditScanPaths::class)->paths(),
        fn (array $p) => $p['package'] === 'splicewire/laravel-beam-tenancy',
    ));

    expect($ours)->toHaveCount(1)
        ->and(realpath($ours[0]['controllersDir']))->toBe(realpath(__DIR__.'/../src/Http'))
        ->and($ours[0]['controllersDir'])->toBeDirectory()
        // Registered but absent, and deliberately so — see the class docblock.
        ->and($ours[0]['routesDir'])->toBe(dirname(__DIR__).'/routes')
        ->and(is_dir($ours[0]['routesDir']))->toBeFalse();
});

it('registers nothing when the host has not bound the seam', function () {
    // A host predating the seam must still boot. Re-run the hook against a container with no binding.
    $app = new Application(dirname(__DIR__));
    $app->instance('config', new Repository([]));

    $provider = new BeamTenancyServiceProvider($app);

    expect(fn () => $provider->packageBooted())->not->toThrow(Throwable::class)
        ->and($app->bound(AuditScanPaths::class))->toBeFalse();
});
