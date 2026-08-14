<?php

namespace Splicewire\Beam\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The null object {@see Tenant::billingAccount()} degrades to when the
 * `beam.tenancy.billing_account_model` seam is unbound — i.e. when beam-tenancy is installed
 * without a billing engine behind it.
 *
 * Eloquent has no null relation: a relation method must return a Relation instance, so "no
 * billing account" has to be expressed as a MorphOne that resolves to nothing. This class is the
 * related side of that MorphOne, and it is deliberately never queried — `Tenant::billingAccount()`
 * hands the relation a local key no Tenant carries, so `MorphOne::getResults()` short-circuits on
 * its null-parent-key guard and returns the default without touching the database.
 *
 * That "never queried" property is the whole point, not an optimization. A host without a billing
 * engine has no `beam_billable` table, so a relation that ran SQL and found no rows would still
 * fatal on a missing relation. The table name below therefore documents what this stands in for;
 * nothing ever selects from it.
 */
class NullBillingAccount extends Model
{
    protected $table = 'beam_billable';

    protected $guarded = [];
}
