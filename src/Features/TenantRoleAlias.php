<?php

namespace Splicewire\Beam\Tenancy\Features;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Splicewire\Beam\Tenancy\Tenant as AppTenant;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\RevertingToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Stancl\Tenancy\Tenancy;
use WeakMap;

/**
 * TLC-04 — the single sanctioned live-indirection point. When a tenant pins a **role**
 * (`fast`/`reasoning`/`cheap`) as its `preferred_model`, this expands it to a concrete model at the
 * Tenant-hop, in tenant context, keyed by the tenant's *current* provider — so `reasoning` resolves
 * to *this provider's* reasoning-tier model and **re-points automatically** when the tenant swaps
 * providers, instead of stranding a frozen `gpt-4o`. Message/Chat/Agent slots stay
 * concrete-model-only; the cascade and its loud-fail guards learn no role vocabulary — the role is
 * gone by the time `app.chat.default_model` is read.
 *
 * It expands the EFFECTIVE `app.chat.default_model` (config-sections 09): the role the tenant tier of the Override Ladder
 * laid there in the bootstrap phase, or, on a host that still lists stancl's TenantConfig, the raw role it mapped. It
 * runs AFTER {@see TenantModelCatalog} (which has merged the tenant catalog, so the expanded model can be validated
 * against it). A role that resolves to no catalog model fails loudly here — the one place the merged map exists.
 *
 * **Reverting (config-sections 15, HIGH).** It undoes ONLY its own layer, and does so FIRST: on stancl's
 * `RevertingToCentralContext`, which fires before the bootstrappers revert and before `TenantConfig` restores central on
 * `RevertedToCentralContext`. So it writes back exactly the value it found (the tenant's role string, when TenantConfig
 * set one), and TenantConfig then restores central: last applied, first undone. It used
 * to revert on `RevertedToCentralContext` AFTER TenantConfig, writing the tenant's role string back into central
 * `app.chat.default_model`, so the next tenant with no preferred model inherited another tenant's choice. It does not
 * listen to `RevertedToCentralContext` at all: a revert there could only do nothing or bring the bug back.
 *
 * Order: when a host lists stancl's TenantConfig at all, AFTER it is REQUIRED and checked at boot
 * ({@see refuseRunningBeforeTenantConfig()}); a host without it (the ladder's hosts, config-sections 09) boots. Listed
 * before it, the hop shows the raw role, and TenantConfig captures the expanded model as its own "original". After
 * TenantModelCatalog is needed only for a role that resolves to a tenant-only model; the revert is correct in either
 * order there, and in any position among the bootstrappers.
 *
 * It holds no config repository (HANDBACK-03's rule): `app('config')` is resolved on every call, so an Octane request
 * writes and restores its own clone, never the worker's base. What it found is kept PER repository in a WeakMap.
 */
class TenantRoleAlias implements Feature
{
    /** @var WeakMap<Repository, array{0: mixed}> the value found in each repository this feature wrote, before writing */
    protected WeakMap $applied;

    public function __construct()
    {
        $this->applied = new WeakMap;
    }

    public function bootstrap(Tenancy $tenancy): void
    {
        $this->refuseRunningBeforeTenantConfig();

        Event::listen(TenancyBootstrapped::class, function (TenancyBootstrapped $event) {
            $this->expandRole($event->tenancy->tenant);
        });

        Event::listen(RevertingToCentralContext::class, function () {
            $this->revert();
        });
    }

    public function expandRole(Tenant $tenant): void
    {
        // config-sections 09 (review Q3): the role is read from the EFFECTIVE app.chat.default_model, whoever wrote it:
        // the tenant tier of the Override Ladder (ConfigLadderTenancyBootstrapper and the cutover source, which lay a
        // tenant's legacy preferred_model there), or stancl's TenantConfig on a host that still maps it. Reading tenant
        // DATA instead would expand a stale legacy role over a tenant's newer published override.
        $preferred = $this->config()->get('app.chat.default_model');

        // Only a pinned role triggers expansion; a concrete model is left exactly as it is, and a tenant with no roles
        // behaves exactly as before.
        if (! is_string($preferred) || ! in_array($preferred, AppTenant::LLM_ROLES, true)) {
            return;
        }

        // preferred_model roles are a TEXT-modality concern. Read the per-modality text provider
        // (ticket 07) falling back to the legacy single provider, and the nested text roles falling
        // back to the legacy flat roles map.
        $provider = Arr::get($tenant, 'llm_config.providers.text') ?? Arr::get($tenant, 'llm_config.provider');
        $roles = Arr::get($tenant, 'llm_config.roles', []);
        $textRoles = is_array($roles) && isset($roles['text']) && is_array($roles['text']) ? $roles['text'] : $roles;
        $model = is_string($provider) && is_array($textRoles)
            ? ($textRoles[$provider][$preferred] ?? null)
            : null;

        if (! is_string($model) || $model === '') {
            throw new \InvalidArgumentException(
                "Role [{$preferred}] has no model for the tenant's current provider [".(is_string($provider) ? $provider : 'none').'].'
            );
        }

        $config = $this->config();

        if (! array_key_exists($model, (array) $config->get('app.chat.models', []))) {
            throw new \InvalidArgumentException(
                "Role [{$preferred}] resolves to [{$model}], which is not in the tenant model catalog."
            );
        }

        // The first value found in this repository is the one to restore, even if a hop expands twice.
        if (! isset($this->applied[$config])) {
            $this->applied[$config] = [$config->get('app.chat.default_model')];
        }

        $config->set('app.chat.default_model', $model);
    }

    public function revert(): void
    {
        $config = $this->config();

        if (isset($this->applied[$config])) {
            $config->set('app.chat.default_model', $this->applied[$config][0]);
            unset($this->applied[$config]);
        }
    }

    /**
     * Listed BEFORE stancl's TenantConfig in `tenancy.features`, this feature cannot be correct (review 05:44Z). Inside the
     * hop TenantConfig overwrites the expanded model with the raw role. Worse, TenantConfig captures its "original" when
     * it runs, so it takes the expanded model as "central" and writes it back after every hop (it keeps that first
     * original for the whole process; vendor/stancl/tenancy/src/Features/TenantConfig.php:53-58). A silent cross-tenant
     * leak becomes a loud configuration error at boot instead. Its position relative to TenantModelCatalog and to the
     * bootstrappers is free.
     */
    protected function refuseRunningBeforeTenantConfig(): void
    {
        $features = array_values((array) config('tenancy.features', []));
        // By class lineage, not exact name: a host's subclass of either feature is the same feature (review 05:49Z).
        $position = function (string $class) use ($features): ?int {
            foreach ($features as $i => $feature) {
                if (is_string($feature) && is_a($feature, $class, true)) {
                    return $i;
                }
            }

            return null;
        };
        $alias = $position(self::class);
        $tenantConfig = $position(\Stancl\Tenancy\Features\TenantConfig::class);

        if ($alias !== null && $tenantConfig !== null && $alias < $tenantConfig) {
            throw new \LogicException(
                'tenancy.features lists '.static::class.' before '.\Stancl\Tenancy\Features\TenantConfig::class
                .': the role alias must come after it, or every role-picking hop leaks its model into the central config.'
            );
        }
    }

    protected function config(): Repository
    {
        return app('config');
    }
}
