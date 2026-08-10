<?php

namespace Splicewire\Beam\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Splicewire\Beam\Accounts\Concerns\HasMembers;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Enums\LlmCapability;
use Splicewire\Beam\Enums\Modality;
use Splicewire\Beam\Models\HasStatuses;
use Splicewire\Beam\Tenancy\Concerns\DesignatedSystemTenant;
use Splicewire\Beam\Tenancy\Models\CentralActivityLog;
use Splicewire\Beam\Tenancy\Models\CentralStatus;
use Splicewire\Beam\Tenancy\Models\TenantInvitation;
use Splicewire\Beam\Tenancy\Models\TenantUser;
use Splicewire\Beam\Workflows\Display\Concerns\HasStatusChannel;
use Splicewire\Beam\Workflows\Display\State;
use Splicewire\Beam\Workflows\Display\Status;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * @property string|null $provisioning_status One of TenantProvisioningStatus values (stored in data column)
 * @property string|null $status_run_id Current provisioning/pack-apply run id — groups one run's ADR-0098 Display status events (stored in data column)
 * @property string|null $owner_email Email of the tenant owner (stored in data column)
 * @property list<string>|null $scaffold_pack_slugs Scaffold packs to provision (stored in data column)
 * @property string|null $plan_slug Subscription plan slug (stored in data column)
 * @property string|null $suspended_at Suspension timestamp; null = active (stored in data column, orthogonal to provisioning_status)
 * @property bool|null $synthetic Whether this is a Synthetic Tenant — generated, not organically accrued (stored in data column; see ADR-0026)
 * @property string|null $parent_tenant_id Broker tenant this is a Brokered Tenant of; null = a direct tenant (real column, self-referential; see ADR-0043)
 * @property array{endpoint: string, token?: string|null}|null $provisioning_webhook Broker callback for terminal provisioning status (stored in data column; see ADR-0043)
 * @property string|null $doctrine_publisher_tenant_id The single finality center this subscriber rolls its sign-offs up to; distinct from the many corpus_grants it reads (stored in data column; dealer-network B4)
 * @property array{preferred_model?: string, provider?: string, providers?: array<string, string>, models?: array<string, array<string, mixed>>, roles?: array<string, mixed>, capabilities?: array<string, string>, embedding_space?: array{provider: string, model: string, dimensions: int}}|null $llm_config Per-tenant LLM preference — a chat model or role (and optional Prism provider) that sits in the model-resolution cascade below an explicit Message/Thread/Agent choice and above the platform default, plus an extend-only `models` catalog deep-merged onto the platform floor and a provider-keyed `roles` alias map expanded at the Tenant-hop, all in tenant context (stored in data column; see tenant-llm-pricing PRD + TLC-03/04)
 * @property array{channels?: list<array{id: string, label: string, order: int, color?: string}>}|null $calendar Per-tenant calendar config carrier — the ordered Channel registry every calendar lanes off (slice 05, ADR-0070 / big-calendar-surface PRD §2). Read through CalendarChannels (Splicewire\Tower\Composition\Calendar\CalendarChannels), which falls back to a single `'default'` channel when unset — so this being null is byte-identical to a single-lane calendar, zero migration (stored in data column)
 */
class Tenant extends BaseTenant implements TeamContract, TenantWithDatabase
{
    use DesignatedSystemTenant, HasDatabase, HasDomains, HasMembers, HasStatusChannel, HasStatuses;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Resolve the status timeline against the central connection so provisioning
     * statuses are written centrally even when a tenant context is initialized.
     */
    protected function getStatusModelClassName(): string
    {
        return CentralStatus::class;
    }

    /**
     * Mint a fresh run id so every Display status event of this attempt (provisioning or pack-apply)
     * groups together. Persisted on the data column so it survives across the pipeline's jobs.
     */
    private function beginStatusRun(): void
    {
        $this->status_run_id = (string) Str::uuid();
    }

    /**
     * Record a provisioning step on the tenant's ADR-0098 Display timeline (emitted centrally, so it
     * is readable outside any tenant boundary) and keep the coarse provisioning_status Control mirror
     * in the `provisioning` state (unless already terminal-active). Entering a fresh provisioning run
     * mints a run id so every event of the attempt groups together.
     */
    public function markProvisioning(string $step, ?string $reason = null): void
    {
        if ($this->provisioning_status !== TenantProvisioningStatus::Active->value) {
            if ($this->provisioning_status !== TenantProvisioningStatus::Provisioning->value) {
                $this->beginStatusRun();
            }
            $this->provisioning_status = TenantProvisioningStatus::Provisioning->value;
            $this->save();
        }

        Status::running($this, $reason ?? $step, ref: $step, runId: $this->status_run_id);
    }

    /**
     * Terminal success: coarse mirror -> active, emit a terminal `complete` Display event. Terminal
     * events are whole-run (ADR-0098: null ref = the whole run), so they carry no sub-part ref.
     */
    public function markActive(): void
    {
        $this->provisioning_status = TenantProvisioningStatus::Active->value;
        $this->save();

        Status::complete($this, 'Provisioning complete.', runId: $this->status_run_id);
    }

    /**
     * Terminal failure: coarse mirror -> failed, emit a terminal `failed` Display event with reason.
     */
    public function markFailed(string $reason): void
    {
        $this->provisioning_status = TenantProvisioningStatus::Failed->value;
        $this->save();

        Status::failed($this, $reason, runId: $this->status_run_id);
    }

    /**
     * Emit an in-progress Display event for a live Scaffold Pack apply. Orthogonal to provisioning —
     * does NOT touch the coarse provisioning_status mirror, so an already-active tenant stays active
     * while a pack is applied. Starts a fresh run so the pack-apply events group on their own.
     */
    public function markPackApplyStarting(string $slug): void
    {
        $this->beginStatusRun();
        $this->save();

        Status::running($this, "Applying scaffold pack '{$slug}'", ref: "pack:{$slug}", runId: $this->status_run_id);
    }

    public function markPackApplyComplete(string $slug): void
    {
        Status::complete($this, "Applied scaffold pack '{$slug}'", runId: $this->status_run_id);
    }

    public function markPackApplyFailed(string $reason): void
    {
        Status::failed($this, $reason, runId: $this->status_run_id);
    }

    /**
     * The tenant's ADR-0098 Display status timeline — central-scoped so it is readable outside any
     * tenant boundary — oldest-first. `id` breaks same-instant ties from a fast synchronous
     * provisioning pipeline (the legacy CentralStatus used microsecond precision for the same reason).
     *
     * @return Collection<int, CentralActivityLog>
     */
    public function statusTimeline(): Collection
    {
        return $this->statusTimelineQuery()->orderBy('created_at')->orderBy('id')->get();
    }

    public function latestStatusEvent(): ?CentralActivityLog
    {
        return $this->statusTimelineQuery()->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    protected function statusTimelineQuery(): Builder
    {
        return CentralActivityLog::query()
            ->where('log_name', config('beam.workflows.status_log_name', 'status'))
            ->where('subject_type', $this->getMorphClass())
            ->where('subject_id', (string) $this->getKey());
    }

    /**
     * Delete the tenant's Display status timeline. The central status rows have no FK to the tenant
     * (the central audit table is deliberately FK-free), so they would orphan on a hard delete.
     */
    public function purgeStatusTimeline(): void
    {
        $this->statusTimelineQuery()->delete();
    }

    /**
     * True while provisioning or pack-apply work is still in flight. Drives the UI's live status
     * subscribe/poll stop condition. Two cases:
     *  - Provisioning lifecycle: queued-but-not-started (pending) or running (provisioning) — the 202
     *    create response lands here, so the UI knows to watch the feed.
     *  - Pack-apply on an already-active tenant: provisioning_status stays active, so fall through to
     *    the Display timeline — busy unless the latest event's state is terminal. No timeline -> not busy.
     */
    public function isBusy(): bool
    {
        if (in_array($this->provisioning_status, [
            TenantProvisioningStatus::Pending->value,
            TenantProvisioningStatus::Provisioning->value,
        ], true)) {
            return true;
        }

        $latest = $this->latestStatusEvent();

        if ($latest === null) {
            return false;
        }

        $state = State::tryFrom((string) $latest->event);

        return $state !== null && ! $state->isTerminal();
    }

    /**
     * How long a provisioning run may sit on a non-terminal Display event before the operator
     * surface treats it as stalled (admin-surface-polish ticket 05). Generous enough not to trip
     * on a legitimately-live pipeline, short enough that a worker that died mid-step surfaces a
     * Retry affordance rather than wedging the tenant in `isBusy` forever.
     */
    private const PROVISIONING_STALL_MINUTES = 10;

    /**
     * A provisioning run that has gone quiet without reaching a terminal status — the wedge case:
     * the coarse mirror is still `pending`/`provisioning` but the newest Display event is a
     * non-terminal `running`/`queued` older than {@see PROVISIONING_STALL_MINUTES} (or there is no
     * event and the row itself hasn't moved). An active/failed tenant, or one with recent progress,
     * is never stalled. Lets the surface offer "provisioning stalled — retry" without a false
     * positive on a genuinely in-flight provision.
     */
    public function provisioningIsStalled(): bool
    {
        if (! in_array($this->provisioning_status, [
            TenantProvisioningStatus::Pending->value,
            TenantProvisioningStatus::Provisioning->value,
        ], true)) {
            return false;
        }

        $threshold = now()->subMinutes(self::PROVISIONING_STALL_MINUTES);
        $latest = $this->latestStatusEvent();

        if ($latest === null) {
            return $this->updated_at === null || $this->updated_at->lt($threshold);
        }

        $state = State::tryFrom((string) $latest->event);
        if ($state !== null && $state->isTerminal()) {
            return false;
        }

        return $latest->created_at !== null && $latest->created_at->lt($threshold);
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'parent_tenant_id',
        ];
    }

    /**
     * Compose a Brokered Tenant's globally-unique slug from its broker and the
     * child's local name: `{brokerSlug}-{childSlug}` (ADR-0043 / D11). Parent-prefixed
     * so it can never collide with a future direct tenant of the same child name nor
     * with another broker's child of that name. Dot-free, so it stays schema-name safe
     * on the wildcard host and as a Postgres schema identifier.
     */
    public static function brokeredSlug(string $brokerSlug, string $childSlug): string
    {
        return $brokerSlug.'-'.$childSlug;
    }

    /** True when this tenant is a Brokered Tenant (attributed to a broker). */
    public function isBrokered(): bool
    {
        return $this->parent_tenant_id !== null;
    }

    /** The broker this Brokered Tenant rolls up to; null for a direct tenant. */
    public function broker()
    {
        return $this->belongsTo(self::class, 'parent_tenant_id');
    }

    /** The Brokered Tenants attributed to this broker. */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_tenant_id');
    }

    protected $casts = [
        'settings' => 'json',
    ];

    /**
     * Mark this tenant as a Synthetic Tenant (generated, not organically accrued).
     * Stored in the central `data` column — the shared model-flags `flags` table is
     * tenant-scoped with a uuid morph key, incompatible with the string-keyed
     * central Tenant (see ADR-0026).
     */
    public function markSynthetic(bool $synthetic = true): self
    {
        $this->synthetic = $synthetic;

        return $this;
    }

    public function isSynthetic(): bool
    {
        return (bool) $this->synthetic;
    }

    /** Only Synthetic Tenants. */
    public function scopeSynthetic(Builder $query): void
    {
        $query->where('data->synthetic', true);
    }

    /**
     * Exclude Synthetic Tenants. The reporting seam: financial-truth reads call
     * this when config('synthetic.exclude_from_reporting') is on. Treats absent
     * and false alike as non-synthetic.
     */
    public function scopeNotSynthetic(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->whereNull('data->synthetic')
                ->orWhere('data->synthetic', false);
        });
    }

    /**
     * Set this tenant's LLM config carrier (stored in the central `data` column): a
     * preferred chat model and optional Prism provider. It sits in the model-resolution
     * cascade below an explicit Message/Thread/Agent choice and above the platform
     * default. Passing both nulls clears the carrier. The preferred model must be a
     * registered chat model — a typo must not silently degrade to the platform default
     * (see tenant-llm-pricing PRD).
     */
    public function setLlmConfig(?string $preferredModel, ?string $provider = null): self
    {
        // Read-modify-write: preferred_model/provider and the models catalog (see setLlmModels)
        // are independent carriers under the same llm_config bag — setting one must not clobber
        // the other.
        $config = $this->llm_config ?? [];

        if ($preferredModel === null && $provider === null) {
            unset($config['preferred_model'], $config['provider']);
            $this->llm_config = $config === [] ? null : $config;

            return $this;
        }

        // preferred_model may be a concrete registered model OR a provider-portable role
        // (fast/reasoning/cheap) that the Tenant-hop expands in tenant context (TLC-04).
        if ($preferredModel !== null
            && ! in_array($preferredModel, self::LLM_ROLES, true)
            && ! array_key_exists($preferredModel, config('app.chat.models', []))) {
            throw new \InvalidArgumentException(
                "Unknown chat model [{$preferredModel}] — not a registered model in app.chat.models nor a role (".implode('/', self::LLM_ROLES).').'
            );
        }

        $this->llm_config = array_merge($config, array_filter([
            'preferred_model' => $preferredModel,
            'provider' => $provider,
        ], fn ($value) => $value !== null));

        return $this;
    }

    /**
     * Declare this tenant's calendar Channel registry (slice 05, big-calendar-surface PRD §2.1) —
     * an ordered list of `{id, label, order, color?}` stored under `data['calendar']['channels']`.
     * One shared tenant-wide vocabulary so the aggregate calendar grid's lanes stay coherent across
     * compositions. Passing null/[] drops the registry, and CalendarChannels (Splicewire\Tower\Composition\Calendar\CalendarChannels)
     * then falls back to the single `'default'` seed — the zero-migration back-compat path.
     *
     * @param  list<array{id: string, label: string, order?: int, color?: string}>|null  $channels
     */
    public function setCalendarChannels(?array $channels): self
    {
        $bag = $this->calendar ?? [];

        if ($channels === null || $channels === []) {
            unset($bag['channels']);
            $this->calendar = $bag === [] ? null : $bag;

            return $this;
        }

        $bag['channels'] = array_values($channels);
        $this->calendar = $bag;

        return $this;
    }

    /** The provider-portable role vocabulary a tenant may pin as its preferred_model (TLC-04). */
    public const LLM_ROLES = ['fast', 'reasoning', 'cheap'];

    /**
     * Declare this tenant's role → model aliases (TLC-04), keyed by **provider** so a role
     * re-points automatically when the tenant swaps providers: `roles[<provider>][<role>]`.
     * A role lives only here; it is expanded to a concrete model at the single Tenant-hop
     * transform ({@see TenantRoleAlias}) in tenant context, so it always
     * resolves against the tenant's *current* provider — never frozen into a Message/Chat slot.
     * Passing null/[] drops the map. Structural validation is loud here; the "resolves to a real
     * catalog model" check is loud at expansion (only there is the merged catalog available).
     *
     * Two accepted shapes (ticket 07): the legacy FLAT `roles[<provider>][<role>]` is the TEXT
     * modality (validated against the text role vocab, byte-identical to TLC-04); or a NESTED
     * `roles[<modality>][<provider>][<role>]` when every top-level key is a {@see Modality} value —
     * each modality validated against its own role vocab (text `fast|reasoning|cheap`, image
     * `standard|hi_res`, embedding `standard|high`). Passing null/[] drops the map.
     *
     * @param  array<string, array<string, string>>|array<string, array<string, array<string, string>>>|null  $roles
     */
    public function setLlmRoles(?array $roles): self
    {
        $config = $this->llm_config ?? [];

        if ($roles === null || $roles === []) {
            unset($config['roles']);
            $this->llm_config = $config === [] ? null : $config;

            return $this;
        }

        if ($this->rolesAreNestedByModality($roles)) {
            foreach ($roles as $modalityValue => $providerMap) {
                $vocab = Modality::from($modalityValue)->roles();

                if (! is_array($providerMap) || $providerMap === []) {
                    throw new \InvalidArgumentException("Tenant role map for modality [{$modalityValue}] must be a non-empty {provider: {role: model}} map.");
                }
                foreach ($providerMap as $provider => $map) {
                    $this->assertProviderRoleMap($provider, $map, $vocab, $modalityValue);
                }
            }
        } else {
            foreach ($roles as $provider => $map) {
                $this->assertProviderRoleMap($provider, $map, self::LLM_ROLES, Modality::Text->value);
            }
        }

        $config['roles'] = $roles;
        $this->llm_config = $config;

        return $this;
    }

    /** True when every top-level key is a {@see Modality} value — the nested per-modality shape. */
    protected function rolesAreNestedByModality(array $roles): bool
    {
        $modalities = array_column(Modality::cases(), 'value');

        foreach (array_keys($roles) as $key) {
            if (! in_array($key, $modalities, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string[]  $vocab  the accepted role names for the modality
     */
    protected function assertProviderRoleMap(mixed $provider, mixed $map, array $vocab, string $modality): void
    {
        if (! is_string($provider) || $provider === '' || ! is_array($map) || $map === []) {
            throw new \InvalidArgumentException("Tenant role map for provider [{$provider}] must be a non-empty {role: model} map.");
        }
        foreach ($map as $role => $model) {
            if (! in_array($role, $vocab, true)) {
                throw new \InvalidArgumentException("Unknown role [{$role}] for modality [{$modality}] — roles are: ".implode(', ', $vocab).'.');
            }
            if (! is_string($model) || $model === '') {
                throw new \InvalidArgumentException("Role [{$role}] for provider [{$provider}] must map to a model name.");
            }
        }
    }

    /**
     * Declare this tenant's Prism provider per completion modality (ticket 07):
     * `providers[text|image|embedding]` — e.g. text:anthropic, image:gemini, embedding:openai — so
     * an image model (Gemini) can coexist with a text model on a provider that has none. The legacy
     * single `provider` (set via {@see setLlmConfig()}) remains the TEXT provider for back-compat;
     * an entry here overrides it for its modality. Passing null/[] drops the map.
     *
     * @param  array<string, string>|null  $providers
     */
    public function setLlmProviders(?array $providers): self
    {
        $config = $this->llm_config ?? [];

        if ($providers === null || $providers === []) {
            unset($config['providers']);
            $this->llm_config = $config === [] ? null : $config;

            return $this;
        }

        $modalities = array_column(Modality::cases(), 'value');

        foreach ($providers as $modality => $provider) {
            if (! is_string($modality) || ! in_array($modality, $modalities, true)) {
                throw new \InvalidArgumentException("Unknown modality [{$modality}] — modalities are: ".implode(', ', $modalities).'.');
            }
            if (! is_string($provider) || $provider === '') {
                throw new \InvalidArgumentException("Provider for modality [{$modality}] must be a non-empty string.");
            }
        }

        $config['providers'] = $providers;
        $this->llm_config = $config;

        return $this;
    }

    /**
     * Resolve a role to its concrete model for this tenant's current provider, or null when the
     * tenant declares no provider or no alias for that role. The single expansion primitive the
     * Tenant-hop feature calls in tenant context.
     */
    public function llmRoleModel(string $role): ?string
    {
        return $this->llmRoleModelFor(Modality::Text, $role);
    }

    /**
     * This tenant's Prism provider for a completion modality (ticket 07). Reads
     * `providers[<modality>]`; for text it falls back to the legacy single `provider` so pre-ticket-07
     * config resolves unchanged. Null when the tenant declares no provider for the modality.
     */
    public function llmProviderFor(Modality $modality): ?string
    {
        $providers = $this->llm_config['providers'] ?? null;

        if (is_array($providers) && is_string($providers[$modality->value] ?? null) && $providers[$modality->value] !== '') {
            return $providers[$modality->value];
        }

        // Back-compat: the legacy single provider IS the text provider.
        return $modality === Modality::Text ? $this->llmProvider() : null;
    }

    /**
     * Resolve a role to its concrete model for a modality's current provider, or null when the
     * tenant declares no provider or no alias. Reads the nested `roles[<modality>][<provider>][<role>]`
     * shape, falling back to the legacy flat `roles[<provider>][<role>]` for the text modality.
     */
    public function llmRoleModelFor(Modality $modality, string $role): ?string
    {
        $provider = $this->llmProviderFor($modality);

        if ($provider === null) {
            return null;
        }

        $roles = $this->llm_config['roles'] ?? [];

        if (isset($roles[$modality->value]) && is_array($roles[$modality->value])) {
            $model = $roles[$modality->value][$provider][$role] ?? null;
        } elseif ($modality === Modality::Text) {
            $model = $roles[$provider][$role] ?? null;
        } else {
            $model = null;
        }

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Curate this tenant's model catalog (TLC-03) — a map keyed like `app.chat.models` entries
     * that {@see TenantModelCatalog} deep-merges onto the platform floor in
     * tenant context (extend-only: it can add models, never hide the floor). Passing null/[] drops
     * the catalog. Each entry must resolve to a usable model or it fails loudly here — a broken
     * catalog never reaches the merge.
     *
     * @param  array<string, array<string, mixed>>|null  $models
     */
    public function setLlmModels(?array $models): self
    {
        $config = $this->llm_config ?? [];

        if ($models === null || $models === []) {
            unset($config['models']);
            $this->llm_config = $config === [] ? null : $config;

            return $this;
        }

        foreach ($models as $key => $entry) {
            $this->assertUsableModelEntry((string) $key, $entry);
        }

        $config['models'] = $models;
        $this->llm_config = $config;

        return $this;
    }

    /**
     * Register this tenant's own billing meters (ADR-0129 model promotion) — a `{meter: {label,
     * unit}}` map merged UNDER the platform meter floor (a flat, floor-wins merge) by MeterRegistry (Splicewire\Beam\Commerce\Billing\MeterRegistry)
     * (extend-only: it can add a `{provider}.tokens` meter for a newly-adopted provider, never
     * redefine a platform meter). Promotion writes here so a promoted model is never silently
     * unmetered. Passing null/[] drops the overlay. Each entry must carry a non-empty `unit` or it
     * fails loudly here — a half-registered meter never reaches the rollup.
     *
     * @param  array<string, array<string, mixed>>|null  $meters
     */
    public function setLlmMeters(?array $meters): self
    {
        $config = $this->llm_config ?? [];

        if ($meters === null || $meters === []) {
            unset($config['meters']);
            $this->llm_config = $config === [] ? null : $config;

            return $this;
        }

        foreach ($meters as $key => $entry) {
            if ((string) $key === '' || ! is_array($entry) || ! is_string($entry['unit'] ?? null) || $entry['unit'] === '') {
                throw new \InvalidArgumentException("Tenant meter [{$key}] must carry a non-empty `unit`.");
            }
        }

        $config['meters'] = $meters;
        $this->llm_config = $config;

        return $this;
    }

    /**
     * A tenant catalog entry is usable only if it names a `provider` service that actually
     * dispatches chat completions — the same contract the platform-floor entries name. This is the
     * loud-fail guard: a typo'd class or a bare stub is rejected before it can be offered as a model.
     */
    protected function assertUsableModelEntry(string $key, mixed $entry): void
    {
        if ($key === '' || ! is_array($entry) || $entry === []) {
            throw new \InvalidArgumentException("Tenant model catalog entry [{$key}] is not a usable model definition.");
        }

        $provider = $entry['provider'] ?? null;

        // ProvidesChatCompletions is a tower-owned contract (T21) — late-bind by string
        // so beam-tenancy needn't import upward. The host binds a real implementation.
        if (! is_string($provider) || ! class_exists($provider)
            || ! is_a($provider, 'App\\Contracts\\ProvidesChatCompletions', true)) {
            throw new \InvalidArgumentException(
                "Tenant model catalog entry [{$key}] must name a `provider` implementing ProvidesChatCompletions."
            );
        }
    }

    /**
     * Steer distinct LLM capabilities (title, quick_prompt, extraction, …) to different models
     * (TLC-05). Stored under `llm_config['capabilities']` as a `{capability: model|role}` map:
     * each key must be a valid {@see LlmCapability} value; each value a non-empty string — a
     * concrete model OR a provider-portable role in {@see LLM_ROLES}. An unset capability degrades
     * to the `chat` entry, then to the platform default (resolved at {@see ThreadService::defaultModel()}).
     * Passing null/[] drops the map. Structural validation is loud here; the "resolves to a real
     * catalog model" check is loud at defaultModel() call time, where the merged catalog exists.
     *
     * @param  array<string, string>|null  $map
     */
    public function setLlmCapabilities(?array $map): self
    {
        $config = $this->llm_config ?? [];

        if ($map === null || $map === []) {
            unset($config['capabilities']);
            $this->llm_config = $config === [] ? null : $config;

            return $this;
        }

        foreach ($map as $capability => $model) {
            if (! is_string($capability) || LlmCapability::tryFrom($capability) === null) {
                throw new \InvalidArgumentException("Unknown LLM capability [{$capability}] — capabilities are: ".implode(', ', array_column(LlmCapability::cases(), 'value')).'.');
            }
            if (! is_string($model) || $model === '') {
                throw new \InvalidArgumentException("Capability [{$capability}] must map to a non-empty model or role name.");
            }
        }

        $config['capabilities'] = $map;
        $this->llm_config = $config;

        return $this;
    }

    /**
     * Point reads at a versioned embedding space (system-models ticket 06). Stored under
     * `llm_config['embedding_space']` as a `{provider, model, dimensions}` pointer. Its PRESENCE
     * opts the tenant into active-space read filtering; absence (null/[]) is legacy single-space
     * mode, byte-identical to pre-ticket-06. The switch orchestrator flips this only once a backfill
     * into the target space completes, so reads never straddle two spaces.
     *
     * @param  array{provider?: string, model?: string, dimensions?: int}|null  $space
     */
    public function setEmbeddingSpace(?array $space): self
    {
        $config = $this->llm_config ?? [];

        if ($space === null || $space === []) {
            unset($config['embedding_space']);
            $this->llm_config = $config === [] ? null : $config;

            return $this;
        }

        $model = $space['model'] ?? null;
        if (! is_string($model) || $model === '') {
            throw new \InvalidArgumentException('Embedding space pointer must name a non-empty model.');
        }

        $config['embedding_space'] = [
            'provider' => is_string($space['provider'] ?? null) && $space['provider'] !== '' ? $space['provider'] : 'openai',
            'model' => $model,
            'dimensions' => (int) ($space['dimensions'] ?? 1536),
        ];
        $this->llm_config = $config;

        return $this;
    }

    /** This tenant's preferred chat model, or null when it defers to the platform default. */
    public function preferredModel(): ?string
    {
        return $this->llm_config['preferred_model'] ?? null;
    }

    /** This tenant's preferred Prism provider, or null when it defers to the model-map / platform default. */
    public function llmProvider(): ?string
    {
        return $this->llm_config['provider'] ?? null;
    }

    /**
     * Point this subscriber's finality at a doctrine publisher (e.g. a Location rolling
     * up to Corporate). Stored in the central `data` column — the single finality
     * center it rolls sign-offs up to, deliberately distinct from the many
     * `corpus_grants` it reads by reference (dealer-network B4).
     */
    public function setDoctrinePublisher(?string $publisherTenantId): self
    {
        $this->doctrine_publisher_tenant_id = $publisherTenantId;

        return $this;
    }

    /** The doctrine publisher this subscriber rolls finality up to, or null. */
    public function doctrinePublisherId(): ?string
    {
        return $this->doctrine_publisher_tenant_id;
    }

    /** Only subscribers whose finality rolls up to the given publisher. */
    public function scopeRollsUpTo(Builder $query, string $publisherTenantId): void
    {
        $query->where('data->doctrine_publisher_tenant_id', $publisherTenantId);
    }

    /**
     * The absolute, fully-qualified host a tenant is reached at — its Tenant Host
     * (see CONTEXT.md). Resolved from the primary Tenant Domain: a subdomain label
     * is expanded against the environment's central domain; a vanity binding (one
     * that already contains a dot) is used as-is. This is the single authority for
     * linking to a tenant — consumers receive it ready-made and never reconstruct
     * it from request context.
     */
    public function primaryHost(): string
    {
        $binding = $this->domains->firstWhere('is_primary', true)
            ?? $this->domains->first();

        $label = $binding?->domain ?? $this->slug;

        if (str_contains($label, '.')) {
            return $label;
        }

        return $label.'.'.$this->centralDomain();
    }

    /**
     * The central domain matching the current environment — the one a subdomain
     * label is expanded against. Picks the configured central domain that the app
     * URL's host ends with; falls back to the first configured central domain.
     */
    protected function centralDomain(): string
    {
        $domains = config('tenancy.central_domains', []);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?? '';

        foreach ($domains as $domain) {
            if (str_ends_with($appHost, $domain)) {
                return $domain;
            }
        }

        return $domains[0] ?? $appHost;
    }

    /**
     * The email Stripe attaches to this tenant's customer — the billing owner, so invoices
     * (which Stripe requires an email to send) reach a real person. Falls back to null when
     * unset; BrokerInvoicer guards against issuing an invoice without one.
     */
    public function stripeEmail(): ?string
    {
        return $this->owner_email;
    }

    /** The tenant name Stripe shows on the customer and its invoices. */
    public function stripeName(): ?string
    {
        return $this->name;
    }

    /**
     * This tenant's optional billing account (laravel-beam-commerce's polymorphic `BillingAccount`,
     * `beam_billable` table). Referenced by FQCN STRING rather than a `use` import: beam-tenancy is a
     * pure tenancy substrate that must stay commerce-agnostic and never hard-depend on
     * laravel-beam-commerce (symmetric to beam-commerce never hard-depending on beam-tenancy — beam's
     * multi-tenancy is optional, and some deployments bill a User directly with no Tenant at all).
     * Eloquent relation methods are lazy, so the class string is only resolved/autoloaded when this
     * relation is actually queried — a host without beam-commerce installed simply never calls
     * `$tenant->billingAccount` and nothing breaks.
     */
    public function billingAccount(): MorphOne
    {
        return $this->morphOne(\Splicewire\Beam\Commerce\BillingAccount::class, 'billable');
    }

    public function users()
    {
        // User is app-owned (T22): resolve the configured central user model.
        return $this->belongsToMany(config('auth.providers.users.model'), 'tenant_users')
            ->withPivot('role', 'invited_at', 'accepted_at', 'removed_at')
            ->withTimestamps();
    }

    // --- beam-accounts TeamContract over the app's own tables (FC-12) ------
    //
    // The platform tenant is a team over the central `tenant_users` pivot — the same
    // engine primitive as beam's `Team`, backed by the app's own UUID-keyed,
    // `removed_at`-soft-deleted table instead of beam's `memberships`. HasMembers
    // supplies members()/hasMember()/memberRole()/assignMember()/removeMember() in
    // terms of the relation + pivot columns named below; teamKey() and invitations()
    // are the tenant-specific bits. Provisioning + Syncable stay the app's private seam.

    /** Point HasMembers at the `users` belongsToMany (not the default `members`). */
    protected function membersRelation(): string
    {
        return 'users';
    }

    /** The `tenant_users` pivot soft-marks a removed seat with `removed_at`. */
    protected function memberRemovedColumn(): ?string
    {
        return 'removed_at';
    }

    public function teamKey(): int|string
    {
        return $this->getKey();
    }

    /** Pending (unaccepted) invitations for this tenant. */
    public function invitations()
    {
        return $this->hasMany(TenantInvitation::class)->whereNull('accepted_at');
    }

    /**
     * Attach (or re-attach) a central user as this tenant's owner: update the
     * owner_email, attach the `owner` pivot, and upsert the in-schema TenantUser
     * with the Admin role. Idempotent. Shared by the SyncOwner provisioning job
     * and the admin "assign owner" endpoint.
     */
    public function assignOwner(Authenticatable $owner): void
    {
        $this->owner_email = $owner->email;
        $this->save();

        $this->users()->syncWithoutDetaching([
            $owner->id => [
                'role' => Role::Owner->value,
                'accepted_at' => now(),
            ],
        ]);

        $this->run(function () use ($owner) {
            $tenantUser = TenantUser::updateOrCreate(
                ['id' => $owner->id],
                [
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'password' => $owner->password,
                ]
            );

            $tenantUser->assignRole('Admin');
        });
    }
}
