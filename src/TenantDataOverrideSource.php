<?php

namespace Splicewire\Beam\Tenancy;

use Illuminate\Support\Facades\Log;
use Splicewire\Beam\Config\ConfigSectionRegistry;
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
 *
 * - **A published row wins.** A legacy value is laid only where the tenant has no published tenant-tier entry at its
 *   pointer or above it, so once a tenant is imported (or edits the key in the Config area), the legacy value is ignored.
 * - **The same rules as a published row.** Legacy entries go through {@see ConfigTenantOverlay::restrict()}: a key whose
 *   ceiling no longer admits the tenant, an operator lock, and an extend collision all stop a legacy value as they stop
 *   a published one. A null or absent legacy value is no override.
 * - **One layer, reverted as one.** It lifts what {@see ConfigTenantOverlay::apply()} laid and lays the union back in one
 *   {@see ConfigTenantOverlay::lay()}, which records the central value of every root either touched; so the hop's revert
 *   (and an operator sync mid-hop, which lifts and lays the held layers) restores and keeps both together.
 * - **Never throws.** A legacy layer that cannot be built leaves the hop on what `apply()` laid, and logs why.
 */
class TenantDataOverrideSource
{
    /** Lay `$tenant`'s legacy values beside its published tenant layer, in the CURRENT repository. */
    public function lay(Tenant $tenant): void
    {
        $map = (array) config('beam.tenancy.config_overrides.legacy_data', []);

        if ($map === []) {
            return;
        }

        $config = app('config');
        $overlay = app(ConfigTenantOverlay::class);
        $held = $overlay->lift($config) ?? [];

        try {
            $layers = $this->merge($held, $this->legacy($tenant, $map));
        } catch (Throwable $e) {
            Log::warning('[beam-tenancy] legacy tenant config values not applied: '.$e->getMessage(), ['tenant' => $tenant->getTenantKey()]);
            $layers = $held;
        }

        $overlay->lay($config, array_filter($layers));
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
            $layers[$root] = ($held[$root] ?? []) + $kept;
        }

        return $layers;
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
