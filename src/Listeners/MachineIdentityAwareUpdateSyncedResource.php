<?php

namespace Splicewire\Beam\Tenancy\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Tenancy\Models\TenantMachineIdentity;
use Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase;
use Stancl\Tenancy\Listeners\UpdateSyncedResource;

/**
 * ⛔ The reason moving machine identities off `tenant_users` is safe at all.
 *
 * ## The mechanism
 *
 * `Stancl\Tenancy\Listeners\UpdateSyncedResource:73-81` re-attaches a missing tenant↔user pivot
 * mapping **with no attributes**:
 *
 *     $mappingExists = $centralModel->tenants->contains($currentTenantMapping);
 *
 *     if (! $mappingExists) {
 *         Pivot::withoutEvents(function () use ($centralModel, $event) {
 *             $centralModel->tenants()->attach($event->tenant->getTenantKey());
 *         });
 *     }
 *
 * An attach with no attributes takes the column DEFAULT, and `create_tenant_users_table.php.stub`
 * declares `role` as `->default('member')`.
 *
 * {@see \Splicewire\Beam\Tenancy\Models\TenantUser} is `Syncable`, and every machine-provisioning
 * path in the estate ends in a `TenantUser::updateOrCreate` inside `$tenant->run()` — tower's
 * `SyncServiceUser:37` and the flagship's `EngineConsumerToken` both do exactly that. Each of those
 * saves fires `SyncedResourceSaved`, which lands here.
 *
 * So the moment machine rows leave `tenant_users`, the very next sync for that user finds no mapping
 * and **silently re-attaches it as `role = 'member'`**.
 *
 * ## Why that is strictly worse than the thing being fixed
 *
 * `service` was wrong, but it ANNOUNCED itself: it is outside {@see \Splicewire\Beam\Accounts\Enums\Role},
 * so `memberRole()` raised a `ValueError` on it and a human eventually looked. A re-attached `member`
 * row is *parseable*, sorts into every seat list, satisfies every role read, and is indistinguishable
 * from a human seat by construction. It would also be counted by per-seat billing the instant it
 * acquired an `accepted_at`. A migration that ended here would have replaced a loud defect with a
 * silent one.
 *
 * ## ⚠️ Corroboration that this fires in reality, not just on paper
 *
 * `system@app.splicewire.com` already sits on the `demo` tenant with `role = 'member'` and a null
 * `accepted_at` — and **no writer in the estate attaches it**. Every deliberate machine writer sets
 * `role` explicitly (`SyncServiceUser` writes `'service'`). A seat nobody wrote, carrying the column
 * default, on a user that is synced into tenant schemas, is this listener's signature. The bug is
 * already in the data.
 *
 * ## What this class changes
 *
 * Exactly one decision: **skip the attach** when the user holds a machine identity in that tenant.
 * Everything else — the central upsert, the foreign-database fan-out, the returned tenant set, the
 * `withoutEvents` guards — is the parent's behaviour, reproduced verbatim, because this is a
 * surgical override of an inlined branch and not a reimplementation.
 *
 * Bound over the stancl class in beam-tenancy's provider, so a host keeps listing
 * `Stancl\Tenancy\Listeners\UpdateSyncedResource::class` in its own `$listen` map (the flagship's
 * `TenancyServiceProvider:117` does) and the container hands it this one. Laravel resolves a
 * class-string listener through the container, so no host edit is required and no host can miss it.
 *
 * ## Deliberately NOT here
 *
 * This does not remove, rewrite or migrate any existing `tenant_users` row. No rows move in this
 * pass. It only stops NEW machine-shaped seats from being manufactured, which is the precondition
 * for a later pass being able to move the existing ones and have them stay moved.
 */
class MachineIdentityAwareUpdateSyncedResource extends UpdateSyncedResource
{
    /**
     * Reproduces the parent verbatim except for the attach branch, which now consults
     * {@see holdsMachineIdentity()} first.
     *
     * The whole method is copied rather than hooked because the attach is INLINE in the parent with
     * no seam of its own. That is a real maintenance cost and it is recorded here rather than hidden:
     * if stancl changes this method, this override must be re-read against it.
     */
    protected function updateResourceInCentralDatabaseAndGetTenants($event, $syncedAttributes)
    {
        /** @var Model|\Stancl\Tenancy\Contracts\SyncMaster $centralModel */
        $centralModel = $event->model->getCentralModelName()::where($event->model->getGlobalIdentifierKeyName(), $event->model->getGlobalIdentifierKey())
            ->first();

        // Events disabled for this call, to avoid re-triggering this listener. (Parent's comment.)
        $event->model->getCentralModelName()::withoutEvents(function () use (&$centralModel, $syncedAttributes, $event) {
            if ($centralModel) {
                $centralModel->update($syncedAttributes);
                event(new SyncedResourceChangedInForeignDatabase($event->model, null));
            } else {
                // If the resource doesn't exist at all in the central DB, we create the record with
                // all attributes, not just the synced ones.
                $centralModel = $event->model->getCentralModelName()::create($event->model->getAttributes());
                event(new SyncedResourceChangedInForeignDatabase($event->model, null));
            }
        });

        $currentTenantMapping = function ($model) use ($event) {
            return ((string) $model->pivot->tenant_id) === ((string) $event->tenant->getTenantKey());
        };

        $mappingExists = $centralModel->tenants->contains($currentTenantMapping);

        // ⛔ THE ONE CHANGE. A machine's presence in a tenant is recorded in
        // `tenant_machine_identities`, so the absence of a `tenant_users` row is CORRECT for it —
        // not a gap to repair. Attaching here would mint a `role = 'member'` seat indistinguishable
        // from a human one. See the class docblock.
        if (! $mappingExists && ! $this->holdsMachineIdentity($centralModel, $event->tenant)) {
            // Here we should call TenantPivot, but we call general Pivot, so that this works
            // even if people use their own pivot model that is not based on our TenantPivot.
            Pivot::withoutEvents(function () use ($centralModel, $event) {
                $centralModel->tenants()->attach($event->tenant->getTenantKey());
            });
        }

        return $centralModel->tenants->filter(function ($model) use ($currentTenantMapping) {
            // Remove the mapping for the current tenant.
            return ! $currentTenantMapping($model);
        });
    }

    /**
     * Whether this central user holds a live machine identity in this tenant.
     *
     * ## Fails OPEN, on purpose
     *
     * Returns false — i.e. lets the parent attach as before — when the table does not exist. A host
     * that has not yet published this package's new migration must keep syncing exactly as it did;
     * this class must never be the reason a sync starts throwing on a host that never opted in.
     *
     * The `Schema::hasTable()` check is the estate's own standing rule applied here: an answer that
     * depends on the HOST's schema is not something this code's author could have gotten right, so
     * it degrades rather than throws.
     *
     * Revoked grants deliberately do NOT count. A revoked machine identity is a machine that no
     * longer has business in the tenant, so if something is still syncing under it, the ordinary
     * membership rules should apply again.
     */
    protected function holdsMachineIdentity(Model $centralModel, $tenant): bool
    {
        if (! Schema::hasTable('tenant_machine_identities')) {
            return false;
        }

        return TenantMachineIdentity::query()
            ->where('tenant_id', (string) $tenant->getTenantKey())
            ->where('user_id', (string) $centralModel->getKey())
            ->whereNull('revoked_at')
            ->exists();
    }
}
