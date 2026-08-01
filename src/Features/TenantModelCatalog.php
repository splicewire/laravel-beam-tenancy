<?php

namespace Splicewire\Beam\Tenancy\Features;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Stancl\Tenancy\Features\TenantConfig;
use Stancl\Tenancy\Tenancy;

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
 * config key — mapping `llm_config.models → app.chat.models` there would wipe the floor. Instance
 * state (`$originalModels`) mirrors TenantConfig's `$originalConfig`: the feature is resolved once,
 * so the bootstrap/revert listener pair shares it across one tenant hop.
 */
class TenantModelCatalog implements Feature
{
    /** @var array<string, mixed>|null */
    protected ?array $originalModels = null;

    public function __construct(protected Repository $config) {}

    public function bootstrap(Tenancy $tenancy): void
    {
        Event::listen(TenancyBootstrapped::class, function (TenancyBootstrapped $event) {
            $this->setTenantModels($event->tenancy->tenant);
        });

        Event::listen(RevertedToCentralContext::class, function () {
            $this->unsetTenantModels();
        });
    }

    public function setTenantModels(Tenant $tenant): void
    {
        $tenantModels = Arr::get($tenant, 'llm_config.models');

        if (! is_array($tenantModels) || $tenantModels === []) {
            return;
        }

        $floor = (array) $this->config->get('app.chat.models', []);
        $this->originalModels = $floor;

        // Extend-only: the platform floor is spread LAST so it wins on any key collision —
        // a tenant adds new models, it can never overwrite, narrow, or hide a floor model.
        $this->config->set('app.chat.models', array_merge($tenantModels, $floor));
    }

    public function unsetTenantModels(): void
    {
        if ($this->originalModels !== null) {
            $this->config->set('app.chat.models', $this->originalModels);
            $this->originalModels = null;
        }
    }
}
