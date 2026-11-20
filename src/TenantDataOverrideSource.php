<?php

namespace Splicewire\Beam\Tenancy;

use Illuminate\Support\Facades\Log;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Schemastud\DataSchemas\Overlay\JsonPathMutator;
use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Config\Overrides\ConfigOverrideEntry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Stancl\Tenancy\Contracts\Tenant;
use Throwable;

/**
 * The CUTOVER source of the tenant tier (config-sections ticket 09; PRD §Tenant tier): until
 * `splicewire:beam:tenancy:import-config-overrides` copies a tenant's legacy `tenants.data` values into tenant-tier rows,
 * this lays them as if they were that tenant's published overrides, so a host can drop stancl's `TenantConfig` map and
 * beam-tenancy's `TenantModelCatalog` without a tenant's effective values changing.
 *
 * The HOST names the legacy keys in `beam.tenancy.config_overrides.legacy_data`: a `tenants.data` path (dot notation into
 * the virtual column) => `['root' => <a registered section root>, 'pointer' => <a JSON pointer in it>]`, plus
 * `'entries' => true` for a map whose ENTRIES each become an override (`<pointer>/<key>`), the way a catalog is added to.
 * A map declared `merge: Extend` NEEDS `entries: true`: one `set` of the whole map at its pointer always collides with the
 * platform's entries and is dropped.
 *
 * - **It ends per tenant at the import.** The import marks each tenant it finished ({@see self::IMPORTED_MARKER} in
 *   `tenants.data`); a marked tenant gets NOTHING from this source, ever again. Its tenant-tier rows are the whole truth,
 *   so an override it later unsets in the Config area stays unset (review B1).
 * - **A published row wins.** Before the import, a legacy value is laid only where the tenant has no published tenant-tier
 *   entry at its pointer or above it, so a key the tenant edits in the Config area takes over from its legacy value.
 * - **The same rules as a published row.** Legacy entries go through {@see ConfigTenantOverlay::restrict()}: a key whose
 *   ceiling no longer admits the tenant, an operator lock, and an extend collision all stop a legacy value as they stop
 *   a published one. A null or absent legacy value is no override.
 * - **One layer, reverted as one.** It is a CONTRIBUTOR to laravel-beam's tenant tier ({@see ConfigTenantOverlay::contribute()},
 *   registered by the provider): apply() lays the published and legacy entries in one {@see ConfigTenantOverlay::lay()},
 *   which records the central value of every root either touched; so the hop's revert, the refresh after a tenant publish
 *   mid-request (review S1), and an operator sync mid-hop (which lifts and lays the held layers) keep both together.
 * - **Valid, or not laid.** Published values were schema-checked when written; a legacy value never was. Each one is
 *   checked against its section's schema over the central value (laravel-beam's own acceptance gate), and one that does
 *   not validate, or cannot even be written there, is skipped and logged by pointer, never by value.
 * - **Never throws through the hop.** laravel-beam guards every contributor: a throw is logged by class and the published
 *   layer alone is laid, and so is a lay of contributed entries that fails.
 * - **Only from a readable store.** laravel-beam calls contributors only once the tenant store was read: a tenant whose
 *   store cannot be read runs on the central values, never on stale legacy ones (review B1).
 */
class TenantDataOverrideSource
{
    /** The `tenants.data` key the import sets on a tenant it finished; this source then lays nothing for that tenant. */
    public const IMPORTED_MARKER = 'config_overrides_imported_at';

    public static function imported(Tenant $tenant): bool
    {
        return data_get($tenant, self::IMPORTED_MARKER) !== null;
    }

    /**
     * The CONTRIBUTOR laravel-beam's tenant tier calls inside apply() ({@see ConfigTenantOverlay::contribute()}): the
     * current tenant's restricted published layers, plus every legacy entry they do not cover, restricted and
     * schema-checked. It runs with the central values in the repository, and only once the tenant store was read.
     * Unchanged layers for a host naming no legacy keys, outside a tenant, and for a tenant the import marked.
     *
     * @param  array<string, array<string, ConfigOverrideEntry>>  $layers
     * @return array<string, array<string, ConfigOverrideEntry>>
     */
    public function contribute(array $layers): array
    {
        $map = (array) config('beam.tenancy.config_overrides.legacy_data', []);
        $tenant = tenancy()->tenant;

        if ($map === [] || $tenant === null || self::imported($tenant)) {
            return $layers;
        }

        return $this->merge($layers, $this->legacy($tenant, $map));
    }

    /**
     * The tenant's legacy values as override entries, root => pointer => entry (unrestricted).
     *
     * @param  array<string, array{root: string, pointer: string, entries?: bool}>  $map
     * @return array<string, array<string, ConfigOverrideEntry>>
     */
    public function legacy(Tenant $tenant, array $map): array
    {
        $layers = [];

        foreach ($map as $path => $target) {
            $value = data_get($tenant, $path);

            if ($value === null) {
                continue;
            }

            $root = (string) $target['root'];
            $pointer = (string) $target['pointer'];

            if (($target['entries'] ?? false) === true) {
                if (! is_array($value)) {
                    continue;
                }

                foreach ($value as $key => $entry) {
                    if ($entry !== null) {
                        $at = JsonPointer::from([...JsonPointer::segments($pointer), (string) $key]);
                        $layers[$root][$at] = self::entry($at, $entry);
                    }
                }

                continue;
            }

            $layers[$root][$pointer] = self::entry($pointer, $value);
        }

        return $layers;
    }

    /**
     * The held (published) layers plus every legacy entry no published entry covers, each legacy root restricted as the
     * runtime restricts a published one.
     *
     * @param  array<string, array<string, ConfigOverrideEntry>>  $held
     * @param  array<string, array<string, ConfigOverrideEntry>>  $legacy
     * @return array<string, array<string, ConfigOverrideEntry>>
     */
    protected function merge(array $held, array $legacy): array
    {
        $sections = app(ConfigSectionRegistry::class);
        $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
        $layers = $held;

        foreach ($legacy as $root => $entries) {
            $registered = $sections->get($root);

            if ($registered === null) {
                continue;
            }

            $published = array_keys($held[$root] ?? []);
            $uncovered = array_filter(
                $entries,
                fn (ConfigOverrideEntry $entry): bool => ! self::covered($entry->pointer, $published),
            );

            if ($uncovered === []) {
                continue;
            }

            // Read after lift(), so this is the central effective value, as ConfigTenantOverlay::layers() reads it.
            $central = app('config')->get($root);
            $kept = ConfigTenantOverlay::restrict($registered, $uncovered, $store->locks($root), is_array($central) ? $central : null);
            $kept = $this->valid($registered->section, $root, is_array($central) ? $central : [], $kept);

            if ($kept !== []) {
                $layers[$root] = ($held[$root] ?? []) + $kept;
            }
        }

        return $layers;
    }

    /**
     * The legacy entries that validate (review S2): each written alone over the central value must still satisfy the
     * section schema through laravel-beam's acceptance gate, as a published draft must; then all of them together. An
     * entry that fails, or cannot even be written there, is skipped and logged by pointer, never by value.
     *
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>  $section
     * @param  array<string, ConfigOverrideEntry>  $entries
     * @return array<string, ConfigOverrideEntry>
     */
    protected function valid(string $section, string $root, array $central, array $entries): array
    {
        $schema = app(ConfigSectionSchema::class)->for($section);
        $gate = app(AcceptanceGate::class);
        $accepts = function (array $chosen) use ($central, $schema, $gate): bool {
            $doc = $central;
            $mutator = new JsonPathMutator;
            ksort($chosen);

            foreach ($chosen as $pointer => $entry) {
                $mutator->override($doc, JsonPointer::toJsonPath((string) $pointer), $entry->valueOver($doc));
            }

            $properties = $schema['properties'] ?? null;
            $governed = is_array($properties) && $properties !== [] ? array_intersect_key($doc, $properties) : $doc;

            return $gate->accepts($governed, $schema);
        };

        $kept = [];

        foreach ($entries as $pointer => $entry) {
            try {
                $ok = $accepts([$pointer => $entry]);
            } catch (Throwable) {
                $ok = false;
            }

            if ($ok) {
                $kept[$pointer] = $entry;
            } else {
                Log::warning('[beam-tenancy] a legacy tenant config value does not fit its section schema; skipped.', ['root' => $root, 'pointer' => $pointer]);
            }
        }

        try {
            $together = $kept === [] || $accepts($kept);
        } catch (Throwable) {
            $together = false;
        }

        if (! $together) {
            Log::warning('[beam-tenancy] the legacy tenant config values of a root do not validate together; none of them laid.', ['root' => $root]);

            return [];
        }

        return $kept;
    }

    /** Whether a published entry sits at `$pointer` or above it (an ancestor's value already decides it). */
    protected static function covered(string $pointer, array $published): bool
    {
        foreach ($published as $at) {
            $at = (string) $at;

            if ($at === $pointer || $at === '' || str_starts_with($pointer, $at.'/')) {
                return true;
            }
        }

        return false;
    }

    protected static function entry(string $pointer, mixed $value): ConfigOverrideEntry
    {
        return new ConfigOverrideEntry($pointer, ConfigOverrideOp::Set, $value, false, 'legacy-tenants-data', null, null);
    }
}
