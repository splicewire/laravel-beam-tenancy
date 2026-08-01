<?php

namespace Splicewire\Beam\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Tenancy\Tenant;

class TenantInvitation extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'token',
        'invited_by',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    /**
     * The single-use accept `token` is a bearer secret — anyone holding it can join
     * the tenant. Hide it from every array/JSON serialization so it never rides the
     * wire (the index listed all pending invites' tokens; the create response echoed
     * one). The accept flow queries by token in a WHERE clause and never serializes it.
     */
    protected $hidden = [
        'token',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function inviter()
    {
        // User is app-owned (T22): resolve via the configured central user model.
        return $this->belongsTo(config('auth.providers.users.model'), 'invited_by');
    }
}
