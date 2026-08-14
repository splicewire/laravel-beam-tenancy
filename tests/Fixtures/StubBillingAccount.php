<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for `laravel-beam-commerce`'s billing-account model, which this package cannot name
 * (commerce requires tenancy, so the edge would be a cycle). The seam only cares that the bound
 * class is an Eloquent model over the polymorphic `beam_billable` table.
 */
class StubBillingAccount extends Model
{
    protected $table = 'beam_billable';

    protected $guarded = [];
}
