<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
}
