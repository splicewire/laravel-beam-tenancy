<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The harness's central user model — the one `Tenant::users()` resolves through
 * `config('auth.providers.users.model')`.
 *
 * UUID-keyed on purpose: `create_tenant_users_table.php.stub` declares `user_id` as a `uuid`,
 * so a bigint-keyed test user would seat fine here and not at any real host. It carries NO
 * spatie permission traits, which is the whole point — the demo seats it holds must be the only
 * authorization signal a policy can read off it.
 */
class User extends Authenticatable
{
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The user-side twin of `Tenant::users()`, mirroring the flagship's `App\Models\User::tenants()`.
     *
     * Present for one reason: stancl's `UpdateSyncedResource` reads `$centralModel->tenants` to
     * decide whether a tenant mapping exists, and `->tenants()->attach(...)` when it does not
     * (`vendor/stancl/tenancy/src/Listeners/UpdateSyncedResource.php:73-81`). Without this relation
     * the listener fatals rather than re-attaching, so the guard test could not observe the very
     * mechanism it exists to prove — it would go green for the wrong reason.
     *
     * ⚠️ Deliberately UNFILTERED, same as the flagship's, so it sees the rows the listener sees.
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(config('tenancy.tenant_model'), 'tenant_users')
            ->withPivot('role', 'invited_at', 'accepted_at', 'removed_at')
            ->withTimestamps();
    }
}
