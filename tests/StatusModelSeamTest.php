<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\ModelStatus\Status;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * `Tenant` used to pin its spatie status timeline to a package-local `CentralStatus` subclass whose
 * only additions were `$connection = 'central'`, `HasUuids` and a microsecond `$dateFormat`. All
 * three reasons are gone:
 *
 *  - the **connection** pin is redundant. `statuses` is a SHARED migration (the table exists on both
 *    connections), and Eloquent's `newRelatedInstance()` copies the parent's connection onto a
 *    related model that declares none — so a status written through `$tenant->statuses()` follows the
 *    Tenant's own connection for free. That is what the assertions below pin.
 *  - `HasUuids` and the timestamp precision are the HOST's to decide, via
 *    `model-status.status_model`. splicewire-app already binds a uuid-capable model there.
 *  - the microsecond precision existed for the provisioning timeline, which moved to the ADR-0098
 *    Display/activity-log path entirely — `statusTimeline()` reads the activity log, not `statuses`.
 *
 * So the package stops naming a status model and defers to the host's config, which is the correct
 * layering: a package that hardcodes the host's status model can't be reconfigured by it.
 */
beforeEach(function () {
    // What a real host supplies. spatie's own fallback is a bare `config('model-status.status_model')`
    // with NO default, so this key is not optional for anyone calling the spatie status API — it is
    // the seam the package now defers to instead of answering for the host. splicewire-app binds
    // Splicewire\Tower\Models\ModelStatus (uuid-capable, unpinned) here; the `statuses` table's uuid
    // primary key is why a uuid-capable model is the host's requirement, not this package's choice.
    config(['model-status.status_model' => HostStatus::class]);
});

it('defers the status model to the host rather than naming its own', function () {
    $resolve = new ReflectionMethod(Tenant::class, 'getStatusModelClassName');
    $resolve->setAccessible(true);

    expect($resolve->invoke(new Tenant))->toBe(HostStatus::class)
        ->and($resolve->invoke(new Tenant))->not->toStartWith('Splicewire\Beam\Tenancy\\');

    // Config-driven, not hardcoded: re-point the key and the answer follows. This is the assertion
    // that would fail if someone reintroduced a package-owned override — checking the method's
    // declaring class cannot detect it, since PHP reports a trait's methods as declared by the class
    // that uses the trait.
    config(['model-status.status_model' => Status::class]);

    expect($resolve->invoke(new Tenant))->toBe(Status::class);
});

it('inherits the host\'s central connection instead of hardcoding one', function () {
    // The mechanism that made the retired `$connection = 'central'` pin unnecessary — and worse than
    // unnecessary. stancl resolves a Tenant's connection from `tenancy.database.central_connection`,
    // and Eloquent's newRelatedInstance() copies the parent's connection onto a related model that
    // declares none. So the status relation follows whatever the host NAMED its central connection.
    //
    // The retired subclass hardcoded the literal string 'central', so on a host that names it
    // anything else it would have written to a connection the Tenant itself does not use. Naming a
    // deliberately non-default value here is the point of the test.
    config([
        'database.connections.primary_central' => config('database.connections.testing'),
        'tenancy.database.central_connection' => 'primary_central',
    ]);

    $tenant = new Tenant;

    expect($tenant->getConnectionName())->toBe('primary_central')
        ->and($tenant->statuses()->getRelated()->getConnectionName())->toBe('primary_central');
});

it('keeps the class it retired actually gone', function () {
    expect(class_exists('Splicewire\Beam\Tenancy\Models\CentralStatus'))->toBeFalse();
});

/** Stands in for the host's configured status model — uuid-capable, no connection of its own. */
class HostStatus extends Status
{
    use HasUuids;
}
