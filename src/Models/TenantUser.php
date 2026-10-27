<?php

namespace Splicewire\Beam\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;

class TenantUser extends Authenticatable implements Syncable
{
    use HasRoles, HasUuids;
    use ResourceSyncing;

    protected $table = 'users';

    protected $guard_name = 'web';

    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Does the tenant being run hold the table a TenantUser lives in? Call it inside the tenant (`$tenant->run()`).
     *
     * A host that owns `users` CENTRAL-only (a Commerce-shaped starter: beam-accounts' `publish_auth_migrations`
     * 'absent') migrates no `users` into its tenants, so there is no mirror to read or seat. Asked of the CURRENT schema
     * explicitly on Postgres: never by resolving the bare name through the session's `search_path`, which on a
     * schema-per-tenant connection still reaches the central `public.users`.
     */
    public static function tableInCurrentTenant(): bool
    {
        $model = new static;
        $connection = $model->getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return $connection->getSchemaBuilder()->hasTable($model->getTable());
        }

        return $connection->selectOne(
            'select 1 from information_schema.tables where table_schema = current_schema() and table_name = ?',
            [$model->getTable()],
        ) !== null;
    }

    public function getGlobalIdentifierKey()
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'id';
    }

    public function getCentralModelName(): string
    {
        // User is app-owned (T22): resolve the configured central user model rather
        // than importing App\Models\User from a package.
        return config('auth.providers.users.model');
    }

    public function getSyncedAttributeNames(): array
    {
        return ['name', 'email', 'password'];
    }
}
