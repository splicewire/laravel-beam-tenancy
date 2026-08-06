<?php

namespace Splicewire\Beam\Tenancy\Features;

use Splicewire\Beam\Tenancy\Tenant as AppTenant;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Stancl\Tenancy\Tenancy;

/**
 * TLC-04 — the single sanctioned live-indirection point. When a tenant pins a **role**
 * (`fast`/`reasoning`/`cheap`) as its `preferred_model`, this expands it to a concrete model at the
 * Tenant-hop, in tenant context, keyed by the tenant's *current* provider — so `reasoning` resolves
 * to *this provider's* reasoning-tier model and **re-points automatically** when the tenant swaps
 * providers, instead of stranding a frozen `gpt-4o`. Message/Chat/Agent slots stay
 * concrete-model-only; the cascade and its loud-fail guards learn no role vocabulary — the role is
 * gone by the time `app.chat.default_model` is read.
 *
 * Runs AFTER stancl's TenantConfig (which has set `app.chat.default_model` to the raw role string)
 * and AFTER {@see TenantModelCatalog} (which has merged the tenant catalog, so the expanded model
 * can be validated against it). A role that resolves to no catalog model fails loudly here — the
 * one place the merged map exists.
 */
class TenantRoleAlias implements Feature
{
    protected ?string $originalDefaultModel = null;

    protected bool $applied = false;

    public function __construct(protected Repository $config) {}

    public function bootstrap(Tenancy $tenancy): void
    {
        Event::listen(TenancyBootstrapped::class, function (TenancyBootstrapped $event) {
            $this->expandRole($event->tenancy->tenant);
        });

        Event::listen(RevertedToCentralContext::class, function () {
            $this->revert();
        });
    }

    public function expandRole(Tenant $tenant): void
    {
        $preferred = Arr::get($tenant, 'llm_config.preferred_model');

        // Only a pinned role triggers expansion; a concrete preferred_model already passed through
        // TenantConfig untouched, and a tenant with no roles behaves exactly as before.
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

        if (! array_key_exists($model, (array) $this->config->get('app.chat.models', []))) {
            throw new \InvalidArgumentException(
                "Role [{$preferred}] resolves to [{$model}], which is not in the tenant model catalog."
            );
        }

        $this->originalDefaultModel = $this->config->get('app.chat.default_model');
        $this->applied = true;
        $this->config->set('app.chat.default_model', $model);
    }

    public function revert(): void
    {
        if ($this->applied) {
            $this->config->set('app.chat.default_model', $this->originalDefaultModel);
            $this->applied = false;
            $this->originalDefaultModel = null;
        }
    }
}
