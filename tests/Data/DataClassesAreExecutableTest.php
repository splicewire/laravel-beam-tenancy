<?php

use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Support\DataConfig;

/**
 * The executability gate for beam-tenancy's declared Data classes (api-surface-coherence ticket 85).
 *
 * Testbench does not auto-discover, so a package harness boots exactly what `getPackageProviders()`
 * names while `src/` freely imports anything it can autoload. This package reaches
 * `spatie/laravel-data` and never booted its provider: `config('data')` was NULL inside this suite
 * and every `validateAndCreate()` fataled with `Trying to access array offset on null` rather than
 * failing. Ticket 84 measured the same omission in `splicewire/tower`.
 *
 * A declared particle is only worth what the declaration can be EXECUTED to prove — the generated
 * TypeScript, the OpenAPI document and the SDK all agree with a DTO that would throw in production
 * otherwise. So this walks every concrete Data class under `src/` and executes the two halves of the
 * hydration path that `validateAndCreate()` runs before it ever touches a payload:
 *
 *  1. `DataConfig::getDataClass()` — the full reflection analysis: every property's type, every
 *     attribute instantiated, casts / mappers / nested Data classes resolved.
 *  2. `::getValidationRules([])` — the rule inferrers plus any hand-written `rules()`, projected
 *     through the input name mapper.
 *
 * Neither needs a fixture payload, which is what lets it be data-driven across the whole set.
 */
function beamTenancyDataClasses(): array
{
    $root = dirname(__DIR__, 2).'/src';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $classes = [];

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (substr($path, -4) !== '.php') {
            continue;
        }

        // Namespace is the path under src/, PSR-4'd onto Splicewire\Beam\Tenancy\.
        $relative = substr($path, strlen($root) + 1, -4);
        $class = 'Splicewire\\Beam\\Tenancy\\'.str_replace('/', '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        if (! is_subclass_of($class, BaseData::class)) {
            continue;
        }

        if ((new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $classes[$class] = [$class];
    }

    ksort($classes);

    return $classes;
}

it('has the laravel-data provider booted, mirroring the host config', function () {
    // Guards the fix itself. Without the provider `config('data')` is null and every case below
    // fatals rather than fails, which is a much worse signal.
    expect(config('data'))->not->toBeNull();

    // And guards against the FALSE GREEN: the package default is `null`, only the host
    // (`~/Herd/splicewire-app/config/data.php`) sets the mapper, and a DTO that hydrates without it
    // can still fail to map under the host.
    expect(config('data.name_mapping_strategy.input'))
        ->toBe(CamelCaseMapper::class);
});

it('finds declared Data classes to check', function () {
    // A discovery bug that silently found zero classes would make every case below vacuous.
    expect(count(beamTenancyDataClasses()))->toBeGreaterThan(0);
});

it('can analyse and derive validation rules for every declared Data class', function (string $class) {
    $dataClass = app(DataConfig::class)->getDataClass($class);

    expect($dataClass->name)->toBe($class);

    expect($class::getValidationRules([]))->toBeArray();
})->with(beamTenancyDataClasses());
