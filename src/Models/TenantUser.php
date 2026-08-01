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
