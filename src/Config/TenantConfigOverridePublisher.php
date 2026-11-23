<?php

namespace Splicewire\Beam\Tenancy\Config;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Config\Overrides\ConfigOverrideEntry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantDataOverrideSource;

/**
 * Publish a tenant's `app.chat` choices as TENANT-tier Override-Ladder versions, for the tower writers that used to
 * write stancl `llm_config` (config-sections ticket 16). One place drafts and publishes through
 * {@see ConfigOverrideStore}, so every writer lands on the ladder the same way, and a tier refusal (a lock, the ceiling,
 * or an extend collision) surfaces as a visible {@see ValidationException}, never a 200 no-op.
 *
 * **Cutover first.** Before a tenant's first override is published, {@see self::ensureImportedInHop()} finishes that
 * tenant's cutover ({@see TenantConfigOverrideImporter}) so the published override OUTRANKS — and, once the tenant is
 * marked, REPLACES — the legacy `tenants.data` source. Without it a later removal (a model the tenant drops) would be
 * re-laid by the legacy source on a not-yet-imported tenant: a no-op success. The import is idempotent and runs ONCE per
 * tenant (a marked tenant short-circuits), so it is the lazy cutover, paid on the first write and never again.
 *
 * **Two hop contracts, like the importer.** The System › Models writer and the promotion sink already run inside the
 * target tenant's hop, so they call the in-hop methods directly. The operator endpoint acts on a tenant it loaded from
 * central and holds no hop, so it calls {@see self::setChatDefaultsForTenant()}, which opens and closes the hop around
 * the publish. The tenant model stays on the central connection (stancl's CentralConnection), so marking it imported is
 * safe from inside the hop.
 *
 * Scope: only `app.chat`'s tenant keys (`default_model`, `default_provider`, `models`) move here. `meters`,
 * `capabilities`, `roles` and `providers` stay in `llm_config` (undeclared, deploy-only) and keep their `setLlm*` writers.
 */
class TenantConfigOverridePublisher
{
    public const ROOT = 'app.chat';

    public const DEFAULT_MODEL = '/default_model';

    public const DEFAULT_PROVIDER = '/default_provider';

    public const MODELS = '/models';

    private const ACTOR = 'tenant-config-writer';

    public function __construct(
        private TenantConfigOverrideImporter $importer,
    ) {}

    /**
     * Operator endpoint ({@see \Splicewire\Tower\Api\Operator\TenantController::setLlmConfig}): the caller holds NO hop,
     * so open the target tenant's hop around the publish. {@see self::setChatDefaults()} for the in-hop contract.
     */
    public function setChatDefaultsForTenant(Tenant $tenant, ?string $model, ?string $provider): void
    {
        try {
            tenancy()->initialize($tenant);
            $this->setChatDefaults($tenant, $model, $provider);
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Pin the tenant's chat default model and provider (operator "pinned model"), reproducing {@see Tenant::setLlmConfig()}
     * on the ladder: BOTH null clears the pin entirely (unset both, inherit the platform default), exactly as the legacy
     * carrier's "both empty clears the carrier" did. Otherwise each NON-null field is set and a null field is left as it
     * stands — the legacy `array_filter`-drop-nulls merge, which only ever cleared both at once, never one alone. The
     * stored string may be a concrete model or a role (fast/reasoning/cheap); the Tenant hop expands a role on read, so
     * nothing here expands it. CALLER HOLDS the tenant's hop.
     */
    public function setChatDefaults(Tenant $tenant, ?string $model, ?string $provider): void
    {
        $this->ensureImportedInHop($tenant);

        $published = $this->publishedLayer(self::ROOT);

        if ($model === null && $provider === null) {
            $ops = [
                ...$this->unsetOp($published, self::DEFAULT_MODEL),
                ...$this->unsetOp($published, self::DEFAULT_PROVIDER),
            ];
        } else {
            $ops = [
                ...($model === null ? [] : $this->setOp($published, self::DEFAULT_MODEL, $model)),
                ...($provider === null ? [] : $this->setOp($published, self::DEFAULT_PROVIDER, $provider)),
            ];
        }

        $this->publishInHop(self::ROOT, $ops);
    }

    /**
     * The tenant's OWN pinned chat default model and provider: the PRESENCE of a tenant-tier override, NOT the laid
     * effective value. An unpinned field is `null` (never the inherited platform floor), so a reader can tell a real
     * pin from an inherited default and the "pinned" response round-trips — re-sending `null` leaves the field unpinned
     * instead of writing the floor id as a hard pin (config-sections ticket 16, D1). CALLER HOLDS the tenant's hop.
     *
     * @return array{model: ?string, provider: ?string}
     */
    public function pinnedChatDefaults(): array
    {
        $published = $this->publishedLayer(self::ROOT);

        return [
            'model' => $this->publishedString($published, self::DEFAULT_MODEL),
            'provider' => $this->publishedString($published, self::DEFAULT_PROVIDER),
        ];
    }

    /**
     * Replace the tenant's extend-only model catalog (System › Models). The input is the FULL desired map, so an id the
     * tenant dropped is unset and a changed row is re-set; an id that collides with a platform model is refused by the
     * store (extend keys add, never replace). `null` (the documented "clear it" input of
     * {@see \Splicewire\Tower\Api\V1\Data\SystemModels\LlmModelsInputData::$models}) is the empty map: it unsets every
     * published model. A model id is a JSON-pointer SEGMENT, RFC 6901-escaped ({@see JsonPointer::from()}), so an id with
     * a `/` or `~` (a provider-qualified id like `openai/gpt-4o`) addresses one entry, not three. CALLER HOLDS the
     * tenant's hop.
     *
     * @param  array<string, array<string, mixed>>|null  $models  id => row, or null to clear every model
     */
    public function setChatModels(Tenant $tenant, ?array $models): void
    {
        $this->ensureImportedInHop($tenant);

        $published = $this->publishedLayer(self::ROOT);
        $desired = [];
        foreach ($models ?? [] as $id => $row) {
            $desired[JsonPointer::from(['models', (string) $id])] = $row;
        }

        $ops = [];
        foreach ($desired as $pointer => $row) {
            array_push($ops, ...$this->setOp($published, $pointer, $row));
        }
        foreach ($published as $pointer => $_) {
            if (str_starts_with((string) $pointer, self::MODELS.'/') && ! array_key_exists($pointer, $desired)) {
                array_push($ops, ...$this->unsetOp($published, (string) $pointer));
            }
        }

        $this->publishInHop(self::ROOT, $ops);
    }

    /**
     * Add ONE model to the tenant's extend catalog (the promotion sink offering a newly-discovered model). Add-only: it
     * never reads the current map, so it cannot drop a model another write published. A collision with a platform model
     * is refused by the store. CALLER HOLDS the tenant's hop.
     *
     * @param  array<string, mixed>  $row
     */
    public function addChatModel(Tenant $tenant, string $id, array $row): void
    {
        $this->ensureImportedInHop($tenant);

        $published = $this->publishedLayer(self::ROOT);
        $this->publishInHop(self::ROOT, $this->setOp($published, JsonPointer::from(['models', $id]), $row));
    }

    /**
     * Finish the cutover of ONLY the root this publisher writes ({@see self::ROOT}), so its published overrides are
     * authoritative, then record that one root as cut over ({@see TenantDataOverrideSource::IMPORTED_ROOTS_MARKER}).
     *
     * Scoped to ONE root on purpose (config-sections ticket 16): the first legacy `setLlmConfig` had no cross-root
     * dependency, so a chat-config write must not be wedged by an unpublished draft on an UNRELATED root (e.g.
     * `media-library`) the operator may have no way to discard here. Only {@see self::ROOT}'s own pending draft refuses,
     * exactly as {@see self::publishInHop()} would refuse it. The per-ROOT marker — never the whole-tenant marker — is
     * what makes a later unset on this root stick without retiring every other root's legacy; the bulk import command
     * ({@see TenantConfigOverrideImporter::import()}) stays the only thing that marks the tenant entirely. Idempotent:
     * once the root is marked, this is a no-op. CALLER HOLDS the tenant's hop.
     */
    public function ensureImportedInHop(Tenant $tenant): void
    {
        if (TenantDataOverrideSource::imported($tenant) || TenantDataOverrideSource::importedRoot($tenant, self::ROOT)) {
            return;
        }

        $map = array_filter(
            (array) config('beam.tenancy.config_overrides.legacy_data', []),
            fn (array $target): bool => ($target['root'] ?? null) === self::ROOT,
        );

        $outcome = $this->importer->importInsideHop($tenant, $map, false);

        if (! $outcome->completed()) {
            // This root has a saved, unpublished draft: finishing the cutover would publish it. Refuse, naming the root.
            throw ValidationException::withMessages([
                'config' => 'This tenant has an unpublished '.self::ROOT.' draft ('.implode(', ', $outcome->skipped)
                    .'); publish or discard it before changing tenant config here.',
            ]);
        }

        $this->importer->markRoot($tenant, self::ROOT);
    }

    /**
     * Draft the ops onto the current published layer and publish exactly that version. Empty ops publish nothing (no
     * no-op version). A root with a pending draft refuses, so a writer never silently publishes an in-progress draft.
     *
     * @param  list<array{pointer: string, op: string, value?: mixed}>  $ops
     */
    private function publishInHop(string $root, array $ops): void
    {
        if ($ops === []) {
            return;
        }

        $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
        $scope = $store->scope($root);

        if ($scope !== null && $scope->head_version !== null && $scope->head_version !== $scope->published_version) {
            throw ValidationException::withMessages([
                'config' => "This tenant has an unpublished {$root} draft; publish or discard it before this change.",
            ]);
        }

        $ours = $store->saveDraft($root, $ops, self::ACTOR);
        $store->publishVersion($root, $ours->head_version);
    }

    /** The current published override doc for a root, pointer => entry (empty when the root has no versions yet). */
    private function publishedLayer(string $root): array
    {
        $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
        $scope = $store->scope($root);

        return $scope === null ? [] : $store->publishedLayer($scope);
    }

    /**
     * A Set op for a pointer, UNLESS the published layer already sets it to the same value (then no op — no redundant
     * version). `$published` is pointer => ConfigOverrideEntry.
     *
     * @param  array<string, ConfigOverrideEntry>  $published
     * @return list<array{pointer: string, op: string, value: mixed}>
     */
    private function setOp(array $published, string $pointer, mixed $value): array
    {
        $entry = $published[$pointer] ?? null;
        if ($entry !== null && $entry->op === ConfigOverrideOp::Set && $entry->value === $value) {
            return [];
        }

        return [['pointer' => $pointer, 'op' => 'set', 'value' => $value]];
    }

    /**
     * An Unset op for a pointer, UNLESS the published layer has no entry there (nothing to inherit back — no op).
     *
     * @param  array<string, ConfigOverrideEntry>  $published
     * @return list<array{pointer: string, op: string}>
     */
    private function unsetOp(array $published, string $pointer): array
    {
        return array_key_exists($pointer, $published)
            ? [['pointer' => $pointer, 'op' => 'unset']]
            : [];
    }

    /**
     * The string a published Set entry holds at `$pointer`, or null when the tenant has no Set override there (an unset
     * pointer holds no entry). `$published` is pointer => ConfigOverrideEntry.
     *
     * @param  array<string, ConfigOverrideEntry>  $published
     */
    private function publishedString(array $published, string $pointer): ?string
    {
        $entry = $published[$pointer] ?? null;

        if ($entry === null || $entry->op !== ConfigOverrideOp::Set) {
            return null;
        }

        return is_string($entry->value) ? $entry->value : null;
    }
}
