<?php

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Tenancy\Models\NullBillingAccount;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\StubBillingAccount;

/**
 * `Tenant::billingAccount()` is the one place beam-tenancy reaches at a model it must never
 * declare: `laravel-beam-commerce` REQUIRES `laravel-beam-tenancy`, so naming a commerce class
 * here closes a dependency cycle. The relation therefore resolves its related model through the
 * `beam.tenancy.billing_account_model` seam, and these tests pin both ends of that seam — the
 * bound case (behaviour identical to the old hard binding) and the unbound case (degrades to no
 * billing account, never fatals).
 */
beforeEach(function () {
    Schema::create('beam_billable', function ($table) {
        $table->increments('id');
        $table->morphs('billable');
        $table->string('stripe_id')->nullable();
        $table->timestamps();
    });
});

it('resolves the model bound to the seam', function () {
    config(['beam.tenancy.billing_account_model' => StubBillingAccount::class]);

    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    expect($tenant->billingAccount())->toBeInstanceOf(MorphOne::class)
        ->and($tenant->billingAccount()->getRelated())->toBeInstanceOf(StubBillingAccount::class);
});

it('resolves the same rows the hard binding did', function () {
    config(['beam.tenancy.billing_account_model' => StubBillingAccount::class]);

    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    $other = Tenant::create(['id' => 'globex', 'name' => 'Globex']);

    StubBillingAccount::create([
        'billable_type' => $tenant->getMorphClass(),
        'billable_id' => $tenant->getKey(),
        'stripe_id' => 'cus_acme',
    ]);

    expect($tenant->billingAccount)->not->toBeNull()
        ->and($tenant->billingAccount->stripe_id)->toBe('cus_acme')
        ->and($other->billingAccount)->toBeNull();
});

it('degrades to no billing account when the seam is unbound', function () {
    config(['beam.tenancy.billing_account_model' => null]);

    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    expect($tenant->billingAccount())->toBeInstanceOf(MorphOne::class)
        ->and($tenant->billingAccount()->getRelated())->toBeInstanceOf(NullBillingAccount::class)
        ->and($tenant->billingAccount)->toBeNull();
});

it('issues no query at all when the seam is unbound', function () {
    // The load-bearing half of the degradation: a host without beam-commerce has no
    // `beam_billable` table, so "returns nothing" has to mean "never asks", not "asks and
    // finds none". Dropping the table proves the relation never reaches for it.
    Schema::drop('beam_billable');

    config(['beam.tenancy.billing_account_model' => null]);

    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expect($tenant->billingAccount)->toBeNull()
        ->and($queries)->toBe([]);
});

it('issues no query when eager loading with the seam unbound', function () {
    // Eager loading is a SEPARATE path from the lazy read above and fails differently: it constrains
    // on the parent keys and reaches for the table regardless of what a lazy read would do. Suppressing
    // the query at the relation's call site only ever fixed the lazy case — this is the assertion that
    // forces the guarantee to live in NullBillingAccount, where it covers both.
    Schema::drop('beam_billable');

    config(['beam.tenancy.billing_account_model' => null]);

    Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    $tenants = Tenant::with('billingAccount')->get();

    expect($tenants)->toHaveCount(1)
        ->and($tenants->first()->billingAccount)->toBeNull();
});

it('degrades rather than fataling when the seam names a class that is not installed', function () {
    config(['beam.tenancy.billing_account_model' => 'Splicewire\Beam\Commerce\BillingAccount']);

    $tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);

    expect($tenant->billingAccount()->getRelated())->toBeInstanceOf(NullBillingAccount::class)
        ->and($tenant->billingAccount)->toBeNull();
});
