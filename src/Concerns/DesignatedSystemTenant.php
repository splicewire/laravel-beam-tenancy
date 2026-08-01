<?php

namespace Splicewire\Beam\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves and stamps the one "designated system tenant" of a stancl
 * multi-tenant app.
 *
 * Framework-generic on purpose: it reasons only over config + Eloquent and
 * references no stancl class. On a stancl Tenant model backed by VirtualColumn,
 * assigning `$this->role = 'x'` folds into the `data` JSON column, and the
 * resolver reads it back with `where('data->role', ...)`.
 *
 * @mixin Model
 */
trait DesignatedSystemTenant
{
    /**
     * Resolve the designated system tenant by its role marker, or null.
     */
    public static function system(): ?static
    {
        return static::query()->where('data->role', static::systemRole())->first();
    }

    /**
     * The configured slug/id for the system tenant (default 'system').
     */
    public static function systemSlug(): string
    {
        return config('beam-tenancy.system_tenant.slug', 'system');
    }

    /**
     * The configured display name for the system tenant (default 'System').
     */
    public static function systemName(): string
    {
        return config('beam-tenancy.system_tenant.name', 'System');
    }

    /**
     * The configured role marker that identifies the system tenant (default 'system').
     */
    public static function systemRole(): string
    {
        return config('beam-tenancy.system_tenant.role', 'system');
    }

    /**
     * Whether this tenant carries the system role marker.
     */
    public function isSystem(): bool
    {
        return $this->role === static::systemRole();
    }

    /**
     * Stamp this tenant with the system role and persist it.
     */
    public function markAsSystem(): void
    {
        $this->role = static::systemRole();
        $this->save();
    }

    /**
     * Idempotently seed the designated system tenant.
     *
     * Finds-or-news by the configured slug/id, fills the given attributes,
     * defaults the name to systemName() when absent, stamps the system role,
     * saves, and returns the model. In a real app this fires stancl's creation
     * pipeline; in a listener-free test it simply inserts a row. Calling twice
     * updates the same row rather than duplicating it.
     */
    public static function provisionSystem(array $attributes = []): static
    {
        $tenant = static::firstOrNew(['id' => static::systemSlug()]);

        $tenant->fill($attributes);

        if (empty($tenant->name)) {
            $tenant->name = static::systemName();
        }

        $tenant->role = static::systemRole();
        $tenant->save();

        return $tenant;
    }
}
