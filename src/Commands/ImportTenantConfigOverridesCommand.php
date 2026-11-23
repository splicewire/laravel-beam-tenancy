<?php

namespace Splicewire\Beam\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Splicewire\Beam\Tenancy\Config\TenantConfigOverrideImporter;
use Splicewire\Beam\Tenancy\Config\TenantImportOutcome;
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

    public function handle(TenantConfigOverrideImporter $importer): int
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
                $this->line($this->importOne($tenant, $importer, $map, (bool) $this->option('dry-run')));
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

    /**
     * Import one tenant through the shared {@see TenantConfigOverrideImporter} and render its {@see TenantImportOutcome}
     * as the per-tenant line (ticket 16: the import logic now lives in the service the API writers also use; this
     * command keeps only the presentation and the dry-run/`--tenant` surface). A tenant whose legacy map is empty reads
     * `nothing to import` and is left unmarked, as before; `imported (n)` / `already present` / `would import (n)` and
     * the `not importable` / `draft pending, skipped` suffixes are unchanged.
     *
     * @param  array<string, array{root: string, pointer: string, entries?: bool}>  $map
     */
    protected function importOne(Tenant $tenant, TenantConfigOverrideImporter $importer, array $map, bool $dry): string
    {
        $outcome = $importer->import($tenant, $map, $dry);

        $status = match (true) {
            $outcome->imported > 0 => ($dry ? 'would import' : 'imported')." ({$outcome->imported})",
            $outcome->present > 0 => 'already present',
            default => 'nothing to import',
        };

        return "{$tenant->getTenantKey()}: {$status}"
            .($outcome->refused === [] ? '' : '; not importable: '.implode(', ', $outcome->refused))
            .($outcome->skipped === [] ? '' : '; '.implode(', ', $outcome->skipped).'; not marked imported');
    }
}
