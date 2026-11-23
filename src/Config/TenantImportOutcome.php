<?php

namespace Splicewire\Beam\Tenancy\Config;

/**
 * The result of importing ONE tenant's legacy `tenants.data` values into its tier of the Override Ladder
 * ({@see TenantConfigOverrideImporter}). Logic only — never a value: pointers and counts, so a caller can report
 * without printing what was imported (config-sections ticket 09's "prints no values" rule, carried to ticket 16).
 *
 * `completed` is the cutover predicate: true when NO root was skipped, so the caller may set
 * {@see \Splicewire\Beam\Tenancy\TenantDataOverrideSource::IMPORTED_MARKER}. A skipped root (the tenant has an
 * unpublished draft) leaves the tenant unmarked: its legacy values keep applying until a re-run finishes it.
 */
final class TenantImportOutcome
{
    /**
     * @param  int  $imported  pointers written (or, in a dry run, that would be written)
     * @param  int  $present  pointers already covered by a published entry at or above them
     * @param  list<string>  $refused  "<root><pointer> (<reason>)" — locked, above the ceiling, a platform entry, or no section
     * @param  list<string>  $skipped  "<root> (draft pending, skipped)" — a root left untouched because a draft is pending
     */
    public function __construct(
        public readonly int $imported = 0,
        public readonly int $present = 0,
        public readonly array $refused = [],
        public readonly array $skipped = [],
    ) {}

    /** True when every root was handled (none skipped): the tenant may be marked imported. */
    public function completed(): bool
    {
        return $this->skipped === [];
    }

    /** Whether anything at all was found to import (used to distinguish "nothing to import" from "already present"). */
    public function touched(): bool
    {
        return $this->imported > 0 || $this->present > 0 || $this->refused !== [] || $this->skipped !== [];
    }
}
