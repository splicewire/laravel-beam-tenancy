<?php

namespace Splicewire\Beam\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Splicewire\Beam\Accounts\Concerns\HasMembers;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Enums\LlmTask;
use Splicewire\Beam\Enums\Modality;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Models\HasStatuses;
use Splicewire\Beam\Tenancy\Concerns\DesignatedSystemTenant;
use Splicewire\Beam\Tenancy\Destinations\ProvisioningDestination;
use Splicewire\Beam\Tenancy\Models\NullBillingAccount;
use Splicewire\Beam\Tenancy\Models\TenantMachineIdentity;
use Splicewire\Beam\Tenancy\Models\TenantUser;
use Splicewire\Beam\Workflows\Display\Concerns\HasStatusChannel;
use Splicewire\Beam\Workflows\Display\State;
use Splicewire\Beam\Workflows\Facades\Status;
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
 * @property bool|null $isolated_database Whether this tenant's Postgres storage is an Isolated Database (a dedicated Laravel Cloud cluster) rather than the default shared-cluster schema (stored in data column; tenant-database-upsell ticket 02 — deliberately not named "tier", a technical storage spec distinct from Plan/Entitlement billing vocabulary)
 * @property string|null $isolated_database_cluster_id The provisioning-destination identifier backing this tenant's Isolated Database, once provisioned — a Laravel Cloud cluster id, or a customer-supplied host:port/database string (stored in data column; tenant-database-upsell ticket 04, generalized ticket 13)
 * @property string|null $isolated_database_destination Which {@see ProvisioningDestination} provisioned this tenant's Isolated Database: `'laravel_cloud'` (frozen, retired for new provisioning — ticket 16), `'gcp_cloud_sql'` (the new managed default — ticket 16), or `'customer_supplied'` (stored in data column; tenant-database-upsell ticket 13 — recorded explicitly rather than inferred, since destination-specific behavior like teardown can't safely stay guessed). Null/unset defaults to `'laravel_cloud'` for tenants that predate this marker (never a live choice for a new tenant).
 * @property string|null $isolated_database_requested_at Timestamp a tenant Owner/Admin requested the upgrade to Isolated Database — presence marks a pending, not-yet-actioned request (stored in data column; tenant-database-upsell ticket 03)
 * @property string|null $write_blocked_at Timestamp writes were blocked for a live isolated-database migration's data-copy phase; null once unblocked (stored in data column; tenant-database-upsell ticket 03/04)
 * @property string|null $retired_schema_name The old shared-cluster schema name retained past an isolated-database cutover for the rollback window; null once retired/dropped (stored in data column; tenant-database-upsell ticket 03/04)
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
     * The tenant's ADR-0098 Display status timeline as an EAGER-LOADABLE relation — the same rows
     * {@see statusTimelineQuery()} selects, in the same order, reachable from `with('statusEvents')`.
     *
     * This exists so a LIST read can batch. `isBusy()`, `provisioningIsStalled()` and the `statuses`
     * projection all resolve off the newest/whole timeline, and each used to reach it through a fresh
     * query — one per row, per prop, on every tenants list read (particle-contribution-seam ticket 03
     * measured ~2 queries per row against a docblock claiming there was no N+1). Declared as an
     * `includes:` entry on the `tenants` resource, the whole timeline arrives in ONE query for the page
     * and every one of those reads is then free.
     *
     * The `log_name` constraint is what makes this the STATUS timeline rather than the tenant's whole
     * audit trail — the central log is generic and `log_name` names the domain.
     *
     * @return MorphMany<CentralActivityLog, $this>
     */
    public function statusEvents(): MorphMany
    {
        return $this->morphMany(CentralActivityLog::class, 'subject')
            ->where('log_name', config('beam.workflows.status_log_name', 'status'))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * The tenant's ADR-0098 Display status timeline — central-scoped so it is readable outside any
     * tenant boundary — oldest-first. `id` breaks same-instant ties from a fast synchronous
     * provisioning pipeline (the retired spatie-status timeline used microsecond timestamps for the
     * same reason; this one orders on the activity log's own key instead).
     *
     * Answers from {@see statusEvents()} when the relation is loaded — that is the whole point of the
     * relation — and re-queries otherwise. It deliberately does NOT memoize by loading the relation on
     * the way past: this model EMITS onto its own timeline (`markProvisioning()` and friends), so a
     * caller that emits and then re-reads on the same instance must see its own event. Eager-loading is
     * the read path's opt-in; it is never something a read quietly turns on for everyone after it.
     *
     * @return Collection<int, CentralActivityLog>
     */
    public function statusTimeline(): Collection
    {
        return $this->relationLoaded('statusEvents')
            ? $this->statusEvents
            : $this->statusEvents()->get();
    }

    /**
     * The newest event on the timeline, or null when there is none.
     *
     * Answers from the loaded relation when there is one — that is the whole point of the relation,
     * and it is what makes `isBusy()`/`provisioningIsStalled()` free on a list read. Falls back to a
     * one-row descending query otherwise, rather than loading a whole timeline to read its last row.
     */
    public function latestStatusEvent(): ?CentralActivityLog
    {
        if ($this->relationLoaded('statusEvents')) {
            return $this->statusEvents->last();
        }

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
     *
     * ⚠️ Deliberately still on {@see statusTimelineQuery()} rather than `statusEvents()`. This is a
     * DELETE, and `statusEvents()` carries the timeline's `orderBy` — `DELETE … ORDER BY` is MySQL-only
     * syntax, so routing the purge through the relation would break on Postgres and SQLite. The read
     * side gains the relation; the write side keeps the unordered query.
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
        // Isolated Database credentials (tenant-database-upsell ticket 10): encrypted per-key
        // inside stancl's shared `data` JSON blob via VirtualColumn's cast-aware encode/decode —
        // no schema change, no effect on other data->* keys or their SQL-path queries.
        'tenancy_db_host' => 'encrypted',
        'tenancy_db_username' => 'encrypted',
        'tenancy_db_password' => 'encrypted',
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
     * Mark this tenant's storage as an Isolated Database (a dedicated Laravel Cloud
     * Postgres cluster) rather than the default shared-cluster schema. Set once the
     * destination is provisioned and the atomic cutover flip lands (tenant-database-
     * upsell ticket 03) — never before, since by construction there must be no window
     * where neither destination is authoritative.
     */
    public function markIsolatedDatabase(bool $isolated = true): self
    {
        $this->isolated_database = $isolated;

        return $this;
    }

    public function isIsolatedDatabase(): bool
    {
        return (bool) $this->isolated_database;
    }

    /**
     * Record which {@see ProvisioningDestination}
     * provisioned this tenant's Isolated Database (ticket 13, point 2) — set together with
     * {@see markIsolatedDatabase()} at cutover, never guessed from other signals afterward.
     */
    public function markIsolatedDatabaseDestination(string $destination): self
    {
        $this->isolated_database_destination = $destination;

        return $this;
    }

    /** Which destination backs this tenant's Isolated Database (`laravel_cloud`/`gcp_cloud_sql`/`customer_supplied`); defaults to `laravel_cloud` for tenants that predate the marker. */
    public function isolatedDatabaseDestination(): string
    {
        return $this->isolated_database_destination ?? 'laravel_cloud';
    }

    /** True while a tenant-facing upgrade request is pending operator action (ticket 03). */
    public function hasPendingIsolatedDatabaseRequest(): bool
    {
        return $this->isolated_database_requested_at !== null;
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

        if (! is_string($provider) || ! class_exists($provider)) {
            throw new \InvalidArgumentException(
                "Tenant model catalog entry [{$key}] must name a `provider` class that exists."
            );
        }

        // The completions contract is owned upward (tower's ProvidesChatCompletions) — late-bind by
        // string so beam-tenancy needn't import it, and read the FQN from config so a relocation is a
        // config edit rather than a dead guard. It WAS hardcoded to `App\Contracts\...`, which stopped
        // existing when the contract moved into tower: class_exists() went false, so every catalog
        // entry failed this check and no tenant could name a model at all.
        //
        // A contract class that isn't installed means there is nothing to check against — the
        // provider-exists check above still stands, but the interface half is skipped rather than
        // failing everything, which is the failure mode this replaces.
        $contract = config('beam.tenancy.model_provider_contract');

        if (is_string($contract) && $contract !== '' && interface_exists($contract) && ! is_a($provider, $contract, true)) {
            throw new \InvalidArgumentException(
                "Tenant model catalog entry [{$key}] must name a `provider` implementing ".class_basename($contract).'.'
            );
        }
    }

    /**
     * Steer distinct LLM capabilities (title, quick_prompt, extraction, …) to different models
     * (TLC-05). Stored under `llm_config['capabilities']` as a `{capability: model|role}` map:
     * each key must be a valid {@see LlmTask} value; each value a non-empty string — a
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
            if (! is_string($capability) || LlmTask::tryFrom($capability) === null) {
                throw new \InvalidArgumentException("Unknown LLM capability [{$capability}] — capabilities are: ".implode(', ', array_column(LlmTask::cases(), 'value')).'.');
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
     * This tenant's optional billing account — the polymorphic `beam_billable` row a billing
     * engine (in practice laravel-beam-commerce) owns.
     *
     * The related model is resolved through the `beam.tenancy.billing_account_model` seam rather
     * than named here, and that indirection is structural, not stylistic: laravel-beam-commerce
     * REQUIRES laravel-beam-tenancy, so any commerce symbol in this package — import, class
     * constant, or FQCN string — is a dependency cycle expressed in source. The seam is the only
     * way the relation can reach a model this package must never declare.
     *
     * Unbound, it degrades to no billing account WITHOUT querying — {@see NullBillingAccount}
     * carries that guarantee itself, so it holds for eager loading as much as for a lazy read.
     * That matters because a host with no billing engine has no `beam_billable` table: "returns
     * nothing" has to mean "never asks". A configured class that isn't installed degrades
     * identically rather than fataling, since the two states are indistinguishable to a caller and
     * both mean the same thing — this deployment does not bill.
     *
     * @see NullBillingAccount
     */
    public function billingAccount(): MorphOne
    {
        $model = config('beam.tenancy.billing_account_model');

        if (! is_string($model) || ! class_exists($model)) {
            return $this->morphOne(NullBillingAccount::class, 'billable');
        }

        return $this->morphOne($model, 'billable');
    }

    public function users()
    {
        // User is app-owned (T22): resolve the configured central user model.
        return $this->belongsToMany(config('auth.providers.users.model'), 'tenant_users')
            ->withPivot('role', 'invited_at', 'accepted_at', 'removed_at')
            ->withTimestamps();
    }

    /**
     * The MACHINE identities granted entry to this tenant — sync daemons, brokers, engine consumers.
     *
     * The deliberate counterpart to {@see users()}, and the two must not be conflated. `users()` is
     * the HUMAN membership pivot, whose `role` is `owner|admin|member`
     * ({@see \Splicewire\Beam\Accounts\Enums\Role}); this is the machine axis that had been squatting
     * in that column as `role = 'service'`. A machine is not a seat: it is never invited, never
     * accepts, and must never be billed as one — which is why
     * `tenant_machine_identities` has no `accepted_at` for a per-seat meter to find.
     *
     * A `hasMany` rather than a `belongsToMany`, and that is the point: the grant is a first-class
     * row with its own surrogate key and its own model, not an unaddressable pivot. See
     * {@see TenantMachineIdentity} for why `tenant_users` having no model is the underlying defect.
     */
    public function machineIdentities(): HasMany
    {
        return $this->hasMany(TenantMachineIdentity::class, 'tenant_id');
    }

    /**
     * This tenant's live machine identity of a given kind, or null.
     *
     * The lookup that replaces `wherePivot('role', 'broker')->first()` and its siblings — the
     * pattern that only ever worked because the machine axis was borrowed into the role column.
     * Reading a kind here is reading a first-class grant, not decoding a role string.
     *
     * Revoked grants are excluded: a revoked identity is not an identity, and every caller of this
     * is asking "may this machine act", never "did it ever exist".
     *
     * ⚠️ `$kind` is NOT validated against
     * {@see \Splicewire\Beam\Tenancy\MachineIdentity\MachineIdentityKindRegistry} here, on purpose.
     * An unregistered kind simply has no rows, which is the same answer as a registered kind with no
     * grant — whereas throwing would make a lookup's success depend on provider boot order, and
     * would break exactly the cross-host case the registry is open for.
     */
    public function machineIdentityFor(string $kind): ?TenantMachineIdentity
    {
        return $this->machineIdentities()
            ->where('kind', $kind)
            ->whereNull('revoked_at')
            ->first();
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
        // beam-accounts' own `Invitation`, on the explicit `team_id` foreign key. There is no
        // `TenantInvitation` any more: that model existed only because `beam_invitations.team_id` was a
        // bigint FK into `beam_teams`, so a string-keyed `Tenant` could implement `TeamContract` and
        // still not be nameable by an invitation. The column is a `TeamContract` key now, so the
        // packaged model serves this case and the fork is gone.
        //
        // ⚠️ The FK went with it — a foreign key names one parent table and this column's parent is
        // whatever the host resolved. Cascade-on-tenant-delete is therefore this model's job, not the
        // database's; see the `deleting` hook.
        return $this->hasMany(Invitation::class, 'team_id')->whereNull('accepted_at');
    }

    /**
     * Replace the `tenant_invitations.tenant_id` cascade the retired table used to carry.
     *
     * ⚠️ This is the one thing lost by making `beam_invitations.team_id` a `TeamContract` key instead
     * of a foreign key, and it is replaced deliberately rather than dropped: a deleted tenant that
     * leaves pending invitations behind leaves live bearer tokens naming a workspace that no longer
     * exists. Every OTHER read is already safe — `invitations()` and the resource `scope` both filter
     * by the current team — so this is about the rows, not about the reads.
     *
     * Unfiltered by `accepted_at` on purpose, unlike the relation above: the accepted ones are the
     * historical rows, and the FK deleted those too.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $tenant): void {
            Invitation::query()->where('team_id', (string) $tenant->getKey())->delete();
        });
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
