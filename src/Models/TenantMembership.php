<?php

namespace Splicewire\Beam\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The `tenant_users` pivot — the human membership seat, as an addressable model.
 *
 * ## ⚠️ Name it right or the next reader picks the wrong one
 * {@see TenantUser} is NOT this table. It maps `users` (`protected $table = 'users'`) and is the
 * tenant-side synced user model. This is the tenant↔user PIVOT. The two names are one word apart
 * and the mistake is silent, so the vocabulary here is deliberately `Membership`, matching the
 * names the estate already reads this table under —
 * {@see \Splicewire\Beam\Accounts\Contracts\MembershipContract},
 * {@see \Splicewire\Tower\Frame\Sources\MembershipSource},
 * {@see \Splicewire\Beam\Accounts\Authorization\MembershipPolicy} — rather than the table's name.
 *
 * ## This is a second entry point, not a replacement for {@see Tenant::users()}
 * The premise that `tenant_users` "has no model" was only ever half true, and the half that was
 * false is the important one. {@see Tenant::users()} is a `belongsToMany` over this table that
 * already carries `withPivot('role', 'invited_at', 'accepted_at', 'removed_at')`, already resolves
 * on `central` (stancl pins `BaseTenant` itself, measured), and is already what **every write in
 * the estate** goes through — `attach`, `updateExistingPivot`, `syncWithoutDetaching`, and
 * `HasMembers::assignMember()`/`removeMember()` across beam-tenancy, tower and the flagship.
 * Nothing here displaces that, and a caller holding a `Tenant` should still use the relation.
 *
 * What the relation cannot serve is a read that is **not scoped to one tenant instance** — a
 * host-wide sweep with no `Tenant` in hand — and that is the gap this model fills.
 *
 * ## ⚠️ One such reader is knowingly still on the default connection
 * {@see \Splicewire\Beam\Tenancy\Doctor\MachineIdentityOnMembershipPivotAudit} reaches this table
 * through a bare `DB::table('tenant_users')` plus a bare `Schema::hasTable()` — **no connection pin
 * at all**, which is why it is the one `tenant_users` reader that never appears in the
 * central-pin census. It survives only because this estate runs schema-per-tenant on ONE Postgres
 * database, so the `public` search_path catches it: measured 2026-08-30, inside an initialized
 * tenant context with `database.default` set to `tenant`, the bare read still returned all 42
 * central rows. On a database-per-tenant host the same line asks a schema that has no such table.
 *
 * It was NOT moved onto this model, and the reason is worth recording rather than retrying blind:
 * this package's own test harness (`tests/TestCase.php`) names its single sqlite connection
 * `testing` and defines no `central` connection at all, so routing the audit here makes its schema
 * probe answer "no such table" and the audit degrades to a self-describing pass — three of its
 * tests flip from Warn to Pass. Making that read honest needs the harness to model a real central
 * connection, which is a separate change with its own blast radius. The defect is real, latent, and
 * filed rather than smuggled in here.
 *
 * ## ⛔ There is no shared scope here, and that is a measured decision
 * The four raw readers this model consolidates do NOT ask the same question, so this class
 * deliberately declares **no global scope and no default filter**. Measured 2026-08-30 against the
 * flagship's live central database — 42 rows across 17 tenants:
 *
 * - 18 rows carry `accepted_at IS NULL` (17 of them the `role = 'service'` machine rows that
 *   predate {@see TenantMachineIdentity}), and
 * - **all 17 tenants diverge** between an unfiltered read and an accepted-only one (2 vs 1 on
 *   fifteen tenants, 9 vs 8 on `billing-demo`, 3 vs 1 on `demo`).
 *
 * So the three live predicates are genuinely three:
 *
 * 1. **Unfiltered** — {@see \Splicewire\Tower\QueryBuilders\ActivityQuery} (every user_id ever
 *    seated, INCLUDING soft-removed and never-accepted seats), the flagship seeders' existence
 *    probe, and the machine-identity audit.
 * 2. **Accepted and live during a billing period** — {@see \Splicewire\Beam\Commerce\Billing\Components\PerSeatFees}.
 * 3. **Active seat only (`removed_at IS NULL`)** — `HasMembers::members()`, `MembershipSource`,
 *    `User::activeTenants()`.
 *
 * Giving this model a default scope would silently move one of the three. Each caller keeps its own
 * `where`; what they share is the table, the connection and the pin — not the predicate.
 *
 * ## ⚠️ Deliberately NOT wired as `Tenant::users()->using(self::class)`
 * That would look like the tidy finish and is a live behaviour change. `withPivot()` hands back RAW
 * attribute values; `using()` a model with {@see $casts} turns `$user->pivot->accepted_at` into a
 * `Carbon` for every existing reader — and there are readers:
 * `TenantMemberController:53` reads it straight, and {@see \Splicewire\Tower\Frame\Sources\MembershipSource}
 * carries a `pivotTimestamp()` normalizer that exists precisely because the value arrives as a
 * string today. Consolidating ACCESS does not license changing the type the relation yields, so the
 * relation is untouched and this model is a second, additive entry point.
 */
class TenantMembership extends Model
{
    use AsPivot;

    /**
     * @central-floor tenant-isolation — this pivot IS the membership half of the tenant-isolation
     * record. It answers "which tenants may this user enter" in ONE central join, before any tenant
     * schema has been booted — the bootstrap paradox in its plainest form, since the question is
     * asked to decide whether a tenant context may be entered at all. It spans users × tenants, so
     * no single tenant schema could hold it, and it FKs both central substrates. Pointer-only: ids,
     * a role string and timestamps, no secrets.
     */
    protected $connection = 'central';

    protected $table = 'tenant_users';

    /**
     * Composite `(tenant_id, user_id)` primary key with no surrogate — see
     * `create_tenant_users_table.php.stub`. {@see TenantMachineIdentity} is the counterpart that
     * DOES carry a surrogate key, and its docblock records why that difference is deliberate.
     */
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'invited_at',
        'accepted_at',
        'removed_at',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user()
    {
        // User is app-owned (T22): resolve the configured central user model rather
        // than importing App\Models\User from a package.
        return $this->belongsTo(config('auth.providers.users.model'), 'user_id');
    }
}
