<?php

use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;

/**
 * config-sections 09, review S-c: a laravel-beam below the tenant-tier hook (no ConfigTenantOverlay::contribute()) must
 * degrade to "no cutover", never fail at the overlay's first resolution.
 */
it('resolves an overlay without contribute() (a laravel-beam below the hook) without failing', function () {
    app()->forgetInstance(ConfigTenantOverlay::class);
    app()->bind(ConfigTenantOverlay::class, fn () => new class {});

    expect(fn () => app(ConfigTenantOverlay::class))->not->toThrow(Error::class);
});

it('registers the cutover source on an overlay that has contribute()', function () {
    app()->forgetInstance(ConfigTenantOverlay::class);
    $overlay = new class
    {
        public array $contributors = [];

        public function contribute(callable $contributor): void
        {
            $this->contributors[] = $contributor;
        }
    };
    app()->bind(ConfigTenantOverlay::class, fn () => $overlay);

    app(ConfigTenantOverlay::class);

    expect($overlay->contributors)->toHaveCount(1);
});
