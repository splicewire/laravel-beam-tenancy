<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Config\Overrides\ConfigOverrideEntry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantDataOverrideSource;
use Throwable;

/**
 * The CUTOVER's import (config-sections ticket 09): copy each tenant's legacy `tenants.data` values (the keys the host
 * names in `beam.tenancy.config_overrides.legacy_data`) into that tenant's tier of the Override Ladder, as PUBLISHED
 * overrides, so TenantDataOverrideSource stops laying them and the host can stop mapping them.
 *
 * - **What it writes is what the cutover applies:** the same legacy reading ({@see TenantDataOverrideSource::legacy()})
 *   and the same restriction ({@see ConfigTenantOverlay::restrict()}): a key a lock or ceiling stops, or an extend
 *   entry the platform already holds, is reported as not importable and never written.
 * - **Idempotent:** a pointer the tenant already has a published entry for (at or above it) is left alone, so a re-run
 *   reports `already present`.
 * - **Never publishes a tenant's unfinished draft** (review S4): a root with a saved, unpublished draft is skipped and
 *   reported (`draft pending, skipped`). Otherwise the legacy values are saved as their OWN new version and exactly that
 *   version is published.
 * - **It ends the cutover per tenant** (review B1): a tenant it finished (no root skipped) is marked
 *   ({@see TenantDataOverrideSource::IMPORTED_MARKER}), and the cutover source lays nothing for it from then on, so an
 *   override the tenant later unsets stays unset. A tenant with a skipped root is NOT marked: its legacy values keep
 *   applying until a re-run finishes it.
 * - **Per tenant:** `imported (n)`, `already present`, or `nothing to import`, plus any not-importable or skipped pointers.
 * - **Never prints a value**, secret or not: pointers and counts only, and on a failure the exception's CLASS, never its
 *   message (review S5: a query exception's message carries the SQL and its bindings). A key the section declares secret
 *   is stored encrypted by the store and served redacted, like any secret override.
 *
 * An OWNER/runbook deploy step (lead 06:23Z, Q5): run against a live database only by the owner. The runbook (review
 * S-b, S-e, N7):
 * - Run it WHEN THE HOST'S CUTOVER PLAN SAYS SO, not by default in the deploy that enables `legacy_data`. A host whose
 *   legacy writers still write `tenants.data` must move them to tenant-tier rows FIRST: an imported tenant ignores
 *   `tenants.data` from then on, so a write there would no longer apply. Until it runs, the Config area shows platform
 *   values where the runtime lays legacy ones, and every hop pays the cutover source's validation.
 * - The marker is per TENANT: a key added to `legacy_data` later needs the import run again, on the same condition.
 * - A host that caches stancl's tenant resolver must clear that cache after the import (or wait out its TTL): until then
 *   a cached tenant model lacks the marker, and an override it unsets in that window brings its legacy value back.
 */
class ImportTenantConfigOverridesCommand extends Command
{
    protected $signature = 'splicewire:beam:tenancy:import-config-overrides
        {--tenant=* : Only these tenant ids (default: every tenant)}
        {--dry-run : Report what would be imported, writing nothing}';

    protected $description = "Import tenants' legacy tenants.data config values into their tenant tier (prints no values)";

    public function handle(TenantDataOverrideSource $source, ConfigSectionRegistry $sections): int
    {
        $map = (array) config('beam.tenancy.config_overrides.legacy_data', []);

        if ($map === []) {
            $this->line('No legacy keys are named (beam.tenancy.config_overrides.legacy_data is empty): nothing to import.');

            return self::SUCCESS;
        }

        $ids = (array) $this->option('tenant');
        $tenants = ($ids === [] ? Tenant::query() : Tenant::query()->whereKey($ids))->orderBy('id')->cursor();
        $failed = false;

        foreach ($tenants as $tenant) {
            try {
                $this->line($this->importOne($tenant, $source, $sections, $map, (bool) $this->option('dry-run')));
            } catch (ValidationException $e) {
                // A store refusal (ConfigOverrideRefusal::because()): its message is the store's own sentence about a root
                // or pointer, written to be shown to whoever wrote it, never a value.
                $failed = true;
                $this->error("{$tenant->getTenantKey()}: failed (refused: {$e->getMessage()})");
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$tenant->getTenantKey()}: failed (".$e::class.'; nothing printed from it, see the application log)');
                Log::error('[beam-tenancy] import-config-overrides failed for a tenant.', ['tenant' => $tenant->getTenantKey(), 'exception' => $e::class]);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<string, array{root: string, pointer: string, entries?: bool}>  $map */
    protected function importOne(Tenant $tenant, TenantDataOverrideSource $source, ConfigSectionRegistry $sections, array $map, bool $dry): string
    {
        $legacy = $source->legacy($tenant, $map);

        if ($legacy === []) {
            return "{$tenant->getTenantKey()}: nothing to import";
        }

        $imported = 0;
        $present = 0;
        $refused = [];
        $skipped = [];

        try {
            tenancy()->initialize($tenant);
            $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);

            foreach ($legacy as $root => $entries) {
                $registered = $sections->get($root);

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

                // The central effective value: read inside the hop, but the store's central reading is what the
                // runtime restricts against, and an import writes no tenant layer before this point.
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
        } finally {
            tenancy()->end();
        }

        if (! $dry && $skipped === []) {
            // Central: the tenant model lives on the central connection, outside the hop.
            $tenant->setAttribute(TenantDataOverrideSource::IMPORTED_MARKER, now()->toIso8601String());
            $tenant->save();
        }

        $status = match (true) {
            $imported > 0 => ($dry ? 'would import' : 'imported')." ({$imported})",
            $present > 0 => 'already present',
            default => 'nothing to import',
        };

        return "{$tenant->getTenantKey()}: {$status}"
            .($refused === [] ? '' : '; not importable: '.implode(', ', $refused))
            .($skipped === [] ? '' : '; '.implode(', ', $skipped).'; not marked imported');
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
