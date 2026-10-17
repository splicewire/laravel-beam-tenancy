<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A central user with NO `tenants()` relation — the shape of a starter host's `App\Models\User` (Tower's, launch G6
 * 2026-10-17), which is not a stancl `SyncMaster`.
 *
 * Same table and key as {@see User}; the only difference is the missing relation. Stancl's `UpdateSyncedResource`
 * reads `$centralModel->tenants` unconditionally, so a `TenantUser` saved in a tenant on such a host fataled with
 * "Call to a member function contains() on null".
 */
class UserWithoutTenants extends Authenticatable
{
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
