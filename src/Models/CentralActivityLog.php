<?php

namespace Splicewire\Beam\Tenancy\Models;

use Spatie\Activitylog\Models\Activity;

/**
 * The central audit trail — a spatie Activity pinned to the central connection, where
 * central-scoped subjects (personal access tokens, users, tenants) live. The default Activity
 * model would land on the tenant-swapped connection and the tenant-local `activity_log`; this
 * one is deliberately central and generic (`log_name` names the domain, e.g. 'tokens').
 *
 * Entries are retained even after their subject is hard-deleted — nothing here cascades — so the
 * `properties` snapshot is the durable record. Morph id columns are strings so a bigint token id,
 * a uuid user id, and a string tenant id can all be a subject/causer.
 */
class CentralActivityLog extends Activity
{
    /**
     * @central-floor auth — the audit trail follows its subjects, and those subjects are floor
     * records: personal access tokens and users (auth floor) plus tenants (isolation record).
     * The trail must survive any one tenant's churn and stay readable in one central join —
     * a tenant-local log could not durably record the floor's own lifecycle.
     */
    protected $connection = 'central';

    protected $table = 'central_activity_log';
}
