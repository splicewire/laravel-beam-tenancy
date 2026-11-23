<?php

namespace Splicewire\Beam\Tenancy\Config;

use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideEntry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantDataOverrideSource;

/**
 * Copy ONE tenant's legacy `tenants.data` values (the keys the host names in
 * `beam.tenancy.config_overrides.legacy_data`) into that tenant's tier of the Override Ladder, as PUBLISHED overrides
 * (config-sections ticket 09's cutover). Extracted from {@see \Splicewire\Beam\Tenancy\Commands\ImportTenantConfigOverridesCommand}
 * (ticket 16) so the command AND {@see TenantConfigOverridePublisher} share ONE import, with no second copy to drift.
 *
 * It does exactly what the cutover applies: the same legacy reading ({@see TenantDataOverrideSource::legacy()}) and the
 * same restriction ({@see ConfigTenantOverlay::restrict()}), and it NEVER publishes a tenant's unfinished draft — a root
 * with a saved, unpublished draft is left untouched and named in {@see TenantImportOutcome::$skipped}, so the tenant is
 * not marked imported and its legacy values keep applying until a re-run finishes it.
 *
 * It returns a {@see TenantImportOutcome} (counts and pointers, never a value) and leaves PRESENTATION and the decision
 * of WHEN to run to the caller. Two hop contracts:
 * - {@see self::importInsideHop()} assumes the caller already holds the tenant's hop (the API writers do), and never
 *   touches the marker;
 * - {@see self::import()} opens and closes the hop itself and marks the tenant when it completed (the command, central).
 */
class TenantConfigOverrideImporter
{
    public function __construct(
        private TenantDataOverrideSource $source,
        private ConfigSectionRegistry $sections,
    ) {}

    /**
     * Import inside a hop the CALLER already holds. Publishes the tenant's missing legacy values as their own version;
     * sets no marker (the caller decides, because the marker is a CENTRAL write and `completed()` is the predicate).
     *
     * @param  array<string, array{root: string, pointer: string, entries?: bool}>  $map  the host's legacy_data map
     */
    public function importInsideHop(Tenant $tenant, array $map, bool $dry): TenantImportOutcome
    {
        $legacy = $this->source->legacy($tenant, $map);

        if ($legacy === []) {
            return new TenantImportOutcome;
        }

        $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
        $imported = 0;
        $present = 0;
        $refused = [];
        $skipped = [];

        // config-sections ticket 16: a root a lazy writer already cut over on its own (it is in the tenant's
        // IMPORTED_ROOTS_MARKER) is DONE. Its legacy is retired at apply time ({@see TenantDataOverrideSource::contribute()}),
        // but legacy() still returns it; re-importing it here would re-PUBLISH a value the tenant dropped after the
        // cutover (e.g. a model unset through setChatModels), resurrecting it as a real row. Skip it — counting it
        // already-imported (present), never refused and never skipped, so completed() stays true and the tenant is still
        // marked, exactly as a whole-tenant import that ran before any lazy writer would have left it.
        $importedRoots = TenantDataOverrideSource::importedRoots($tenant);

        foreach ($legacy as $root => $entries) {
            if (in_array($root, $importedRoots, true)) {
                $present += count($entries);

                continue;
            }

            $registered = $this->sections->get($root);

            if ($registered === null) {
                array_push($refused, ...array_map(fn (string $p): string => "{$root}{$p} (no section)", array_keys($entries)));

                continue;
            }

            $scope = $store->scope($root);
            $published = $scope === null ? [] : array_keys($store->publishedLayer($scope));

            if ($scope !== null && $scope->head_version !== null && $scope->head_version !== $scope->published_version) {
                // The tenant has a saved draft it has not published: publishing ours would publish theirs too.
                $skipped[] = "{$root} (draft pending, skipped)";

                continue;
            }

            $fresh = [];

            foreach ($entries as $pointer => $entry) {
                if (self::covered((string) $pointer, $published)) {
                    $present++;
                } else {
                    $fresh[$pointer] = $entry;
                }
            }

            if ($fresh === []) {
                continue;
            }

            $central = app(ConfigTenantOverlay::class)->central($root);
            $kept = ConfigTenantOverlay::restrict($registered, $fresh, $store->locks($root), is_array($central) ? $central : null);

            foreach (array_diff_key($fresh, $kept) as $pointer => $_) {
                $refused[] = "{$root}{$pointer} (locked, above the tenant ceiling, or a platform entry)";
            }

            if ($kept === [] || $dry) {
                $imported += count($kept);

                continue;
            }

            $ours = $store->saveDraft($root, array_values(array_map(
                fn (ConfigOverrideEntry $entry): array => ['pointer' => $entry->pointer, 'op' => 'set', 'value' => $entry->value],
                $kept,
            )), 'import-config-overrides');
            // Exactly the version just saved (no draft was pending, so it holds only the published layer plus ours).
            $store->publishVersion($root, $ours->head_version);
            $imported += count($kept);
        }

        return new TenantImportOutcome($imported, $present, $refused, $skipped);
    }

    /**
     * Import a tenant end to end: open the hop, import, close it, and — when nothing was skipped and this is not a dry
     * run — mark the tenant ({@see TenantDataOverrideSource::IMPORTED_MARKER}) so the cutover source stops laying its
     * legacy values. The marker is a CENTRAL write (the tenant model lives on the central connection, outside the hop).
     *
     * @param  array<string, array{root: string, pointer: string, entries?: bool}>  $map
     */
    public function import(Tenant $tenant, array $map, bool $dry): TenantImportOutcome
    {
        try {
            tenancy()->initialize($tenant);
            $outcome = $this->importInsideHop($tenant, $map, $dry);
        } finally {
            tenancy()->end();
        }

        // The command marks a tenant it finished (nothing skipped) that actually had legacy values to act on; a tenant
        // with NO legacy values is left unmarked, exactly as before the extraction (the publisher, which needs the
        // tenant authoritative, marks on completed() alone — see TenantConfigOverridePublisher::ensureImported()).
        if (! $dry && $outcome->completed() && $outcome->touched()) {
            $this->mark($tenant);
        }

        return $outcome;
    }

    /** Mark the tenant imported (central write). The caller checks {@see TenantImportOutcome::completed()} first. */
    public function mark(Tenant $tenant): void
    {
        $tenant->setAttribute(TenantDataOverrideSource::IMPORTED_MARKER, now()->toIso8601String());
        $tenant->save();
    }

    /**
     * Mark ONE root cut over (config-sections ticket 16): add it to {@see TenantDataOverrideSource::IMPORTED_ROOTS_MARKER}
     * so the cutover source stops laying that root's legacy, while every other root keeps laying until it too is imported
     * or the whole tenant is marked. A CENTRAL write (the tenant model lives on the central connection), idempotent, and
     * never a whole-tenant mark — only the bulk import marks the tenant entirely, after every root is done. The caller
     * imported the root (published its legacy) before calling this.
     */
    public function markRoot(Tenant $tenant, string $root): void
    {
        $roots = TenantDataOverrideSource::importedRoots($tenant);

        if (in_array($root, $roots, true)) {
            return;
        }

        $roots[] = $root;
        $tenant->setAttribute(TenantDataOverrideSource::IMPORTED_ROOTS_MARKER, array_values($roots));
        $tenant->save();
    }

    /** @param  list<string|int>  $published */
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
}
