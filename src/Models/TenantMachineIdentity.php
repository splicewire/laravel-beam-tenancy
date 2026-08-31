<?php

namespace Splicewire\Beam\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One machine's grant to enter one tenant — a sync daemon, a broker, an engine consumer.
 *
 * ## ⚠️ This is the model `tenant_users` never had, and that absence is the real defect
 *
 * `tenant_users` has NO Eloquent model. It is reached raw, from at least two places that each had to
 * re-derive the pivot's meaning in SQL:
 *
 * - `laravel-beam-commerce/src/Billing/Components/PerSeatFees.php:50-59` — counts billable seats by
 *   querying the table directly and filtering on `accepted_at`.
 * - `splicewire/tower/src/QueryBuilders/ActivityQuery.php` — joins it raw to scope activity.
 *
 * Because there is no model there is no one place that knows what a row MEANS, so every reader
 * invents its own answer and none of them agree. That is how `role = 'service'` survived: a value
 * outside {@see \Splicewire\Beam\Accounts\Enums\Role} sitting in a column typed as a role, with no
 * class anywhere positioned to reject it. (`Tenant::memberRole()` raises a raw `ValueError` when it
 * meets one — the closest thing to a guard, and it fires at the wrong end.)
 *
 * So the point of this class is not merely that machines get a table. It is that the machine axis
 * gets a MODEL from the start: one place that says what a row means, what a `kind` is, and when a
 * grant is live. Do not reach this table raw, and do not let the next reader do it either.
 *
 * `tenant_users` itself is out of scope for this pass — no rows move here yet, and the readers above
 * are untouched. This is the destination being built, not the migration.
 */
class TenantMachineIdentity extends Model
{
    use HasUuids;

    protected $table = 'tenant_machine_identities';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * The tenant this grant is scoped to. Resolved through stancl's configured tenant model rather
     * than naming {@see \Splicewire\Beam\Tenancy\Tenant} directly, so a host that substitutes its own
     * tenant model keeps this relation pointed at it.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.tenant_model'), 'tenant_id');
    }

    /**
     * The central user row the machine authenticates as. User is app-owned (T22), so the model is
     * resolved off config exactly as {@see \Splicewire\Beam\Tenancy\Tenant::users()} does.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'user_id');
    }

    /**
     * Whether this grant is live — granted, and not since revoked.
     *
     * Deliberately NOT a check that `kind` is registered. A kind that this host has never heard of
     * is still a real, un-revoked grant somebody made; whether the host can INTERPRET it is a
     * separate question the registry answers, and folding the two together would make a row's
     * validity depend on provider boot order.
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** Scope to grants that have not been revoked. */
    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
