<?php

namespace Splicewire\Beam\Tenancy\Features;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\RevertingToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Stancl\Tenancy\Features\TenantConfig;
use Stancl\Tenancy\Tenancy;
use WeakMap;

/**
 * TLC-03 — a tenant's curated model catalog (`llm_config.models`) **deep-merged** into
 * `app.chat.models`, extend-only. The tenant's entries ADD to the platform floor; the floor
 * always wins on a key collision, so a tenant can never hide or narrow any platform model —
 * including the only credentialed `global` fallback the resolution cascade lands on.
 *
 * Because the four static-map chokepoints (`Tenant::setLlmConfig`, the admin `Rule::in`,
 * `ThreadService::defaultModel()`, `ThreadController::models()`) all read
 * `config('app.chat.models')`, this one merge makes the tenant-context ones dynamic for free —
 * the loud-fail guard survives, now validating against the tenant-resolved map. A tenant that
 * curates nothing leaves the map byte-identical to today.
 *
 * Sibling to stancl's {@see TenantConfig}, which can only *replace* a
 * config key — mapping `llm_config.models → app.chat.models` there would wipe the floor.
 *
 * **Reverting (config-sections 15).** The same discipline as {@see TenantRoleAlias}: it undoes only its own layer, on
 * stancl's `RevertingToCentralContext`, so a feature applied before it (stancl's TenantConfig) restores after it, last
 * applied, first undone. It does not listen to `RevertedToCentralContext`: a revert there could only do nothing or undo
 * in the wrong order. It holds no config repository (HANDBACK-03): `app('config')` is resolved on every call, so an Octane request
 * merges into and restores its own clone, the one TenantRoleAlias validates the expanded model against. What it found is
 * kept PER repository in a WeakMap.
 */
class TenantModelCatalog implements Feature
{
    /** @var WeakMap<Repository, array<string, mixed>> the floor found in each repository this feature merged into */
    protected WeakMap $originalModels;

    public function __construct()
    {
        $this->originalModels = new WeakMap;
    }

    public function bootstrap(Tenancy $tenancy): void
    {
        Event::listen(TenancyBootstrapped::class, function (TenancyBootstrapped $event) {
            $this->setTenantModels($event->tenancy->tenant);
        });

        Event::listen(RevertingToCentralContext::class, function () {
            $this->unsetTenantModels();
        });
    }

    public function setTenantModels(Tenant $tenant): void
    {
        $tenantModels = Arr::get($tenant, 'llm_config.models');

        if (! is_array($tenantModels) || $tenantModels === []) {
            return;
        }

        $config = app('config');
        $floor = (array) $config->get('app.chat.models', []);

        if (! isset($this->originalModels[$config])) {
            $this->originalModels[$config] = $floor;
        }

        // Extend-only: the platform floor is spread LAST so it wins on any key collision —
        // a tenant adds new models, it can never overwrite, narrow, or hide a floor model.
        $config->set('app.chat.models', array_merge($tenantModels, $floor));
    }

    public function unsetTenantModels(): void
    {
        $config = app('config');

        if (isset($this->originalModels[$config])) {
            $config->set('app.chat.models', $this->originalModels[$config]);
            unset($this->originalModels[$config]);
        }
    }
}
