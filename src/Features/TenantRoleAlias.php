<?php

namespace Splicewire\Beam\Tenancy\Features;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Splicewire\Beam\Tenancy\Tenant as AppTenant;
use Splicewire\Beam\Config\Events\TenantConfigLayerLaid;
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
    /**
     * The container key {@see \Splicewire\Beam\Tenancy\Config\TenantConfigOverridePublisher::setChatDefaultsForTenant()}
     * binds to the tenant key for the length of ITS OWN hop (config-sections 16). That hop exists to change the chat
     * defaults, so a role that cannot expand must not stop it: the expansion is skipped there, and only there. It is a
     * container instance, not a static: the publisher forgets it in a `finally`, and under Octane it lives in the
     * request's sandbox container, so nothing outlives the call.
     */
    public const WRITER_HOP = 'beam.tenancy.role-alias.writer-hop';

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
            try {
                $this->expandRole($event->tenancy->tenant);
            } catch (\InvalidArgumentException $refused) {
                // Every hop refuses loudly, except the chat-defaults writer's own hop for this tenant, which is there to
                // repair or clear the very pin that cannot expand. The role stays unexpanded for that hop alone.
                if (! self::isWriterHop($event->tenancy->tenant)) {
                    throw $refused;
                }
            }
        });

        // config-sections 09 (review S3): the tenant tier is RE-laid mid-hop (laravel-beam's operator re-sync lifts the
        // layer, writes the operator tier and lays it again; a tenant publish re-applies it), which hands the raw role back.
        // Expand again on every lay. Inside the hop's own bootstrap the catalog may not be merged yet, so a role that
        // resolves to nothing here is left for the loud TenancyBootstrapped expansion above.
        Event::listen(TenantConfigLayerLaid::class, function (TenantConfigLayerLaid $event): void {
            $tenant = tenancy()->tenant;

            if ($tenant === null || ! $event->touches('app.chat')) {
                return;
            }

            try {
                $this->expandRole($tenant);
            } catch (\InvalidArgumentException) {
                // Not resolvable against this layer yet: TenancyBootstrapped expands (or refuses) after the catalog.
            }
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

        $config = $this->config();
        $model = self::modelFor($tenant, $preferred, $config->get('app.chat.default_provider'), (array) $config->get('app.chat.models', []));

        // The first value found in this repository is the one to restore, even if a hop expands twice.
        if (! isset($this->applied[$config])) {
            $this->applied[$config] = [$config->get('app.chat.default_model')];
        }

        $config->set('app.chat.default_model', $model);
    }

    /**
     * The concrete model a role expands to for `$tenant`, given the effective `app.chat.default_provider` and model
     * catalog, or an {@see \InvalidArgumentException} naming why it cannot. Pure: it reads only its arguments, so the hop
     * ({@see expandRole()}) and the chat-defaults writer (which checks the pin it is about to publish) share one rule.
     *
     * preferred_model roles are a TEXT-modality concern. Read the per-modality text provider (ticket 07), then the
     * EFFECTIVE app.chat.default_provider — the SAME ladder the role is read from — and only then the legacy single
     * provider. config-sections ticket 16 moved the operator's provider writer off `llm_config.provider` onto the ladder
     * (TenantConfigOverridePublisher), so a tenant can keep a stale legacy provider while the operator publishes a new
     * one; reading tenant DATA for the provider would then expand the role through the stale provider (or, with no legacy
     * provider at all, throw). The legacy fallback stays live for a host that does not project its provider onto the
     * ladder. The nested text roles fall back to the legacy flat roles map.
     *
     * @param  array<string, mixed>  $catalog  the effective `app.chat.models`
     */
    public static function modelFor(Tenant $tenant, string $role, mixed $defaultProvider, array $catalog): string
    {
        $provider = Arr::get($tenant, 'llm_config.providers.text')
            ?? $defaultProvider
            ?? Arr::get($tenant, 'llm_config.provider');
        $roles = Arr::get($tenant, 'llm_config.roles', []);
        $textRoles = is_array($roles) && isset($roles['text']) && is_array($roles['text']) ? $roles['text'] : $roles;
        $model = is_string($provider) && is_array($textRoles)
            ? ($textRoles[$provider][$role] ?? null)
            : null;

        if (! is_string($model) || $model === '') {
            throw new \InvalidArgumentException(
                "Role [{$role}] has no model for the tenant's current provider [".(is_string($provider) ? $provider : 'none').'].'
            );
        }

        if (! array_key_exists($model, $catalog)) {
            throw new \InvalidArgumentException(
                "Role [{$role}] resolves to [{$model}], which is not in the tenant model catalog."
            );
        }

        return $model;
    }

    /** Whether `$tenant`'s hop is the chat-defaults writer's own ({@see WRITER_HOP}). */
    public static function isWriterHop(Tenant $tenant): bool
    {
        return app()->bound(self::WRITER_HOP) && app(self::WRITER_HOP) === $tenant->getTenantKey();
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
