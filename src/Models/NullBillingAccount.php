<?php

namespace Splicewire\Beam\Tenancy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The null object {@see Tenant::billingAccount()} degrades to when the
 * `beam.tenancy.billing_account_model` seam is unbound — i.e. when beam-tenancy is installed
 * without a billing engine behind it.
 *
 * Eloquent has no null relation: a relation method must return a Relation instance, so "no billing
 * account" has to be expressed as a MorphOne that resolves to nothing. This class is the related
 * side of that MorphOne, and it **never executes a query** — its builder returns an empty result
 * without going to the database at all.
 *
 * That is the whole point, not an optimization. A host with no billing engine has no
 * `beam_billable` table, so a relation that ran SQL and found no rows would still fatal on the
 * missing relation. Suppressing it in the model rather than at the relation's call site is what
 * makes it hold on EVERY path — `$tenant->billingAccount` and `Tenant::with('billingAccount')`
 * alike. Eager loading was the case that caught this: it constrains on the parent keys and would
 * otherwise reach for the table regardless of what the lazy path does.
 *
 * The table name below documents what this stands in for. Nothing ever selects from it.
 */
class NullBillingAccount extends Model
{
    protected $table = 'beam_billable';

    protected $guarded = [];

    /**
     * Return a builder that resolves to no models without touching the connection.
     *
     * `getModels()` is the single chokepoint every read funnels through — `get()`, `first()`, and
     * the relation's eager `getEager()` all call it — so overriding it here covers them together
     * rather than one path at a time.
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            /** @return list<Model> */
            public function getModels($columns = ['*'])
            {
                return [];
            }
        };
    }
}
