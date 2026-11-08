<?php

namespace Splicewire\Beam\Tenancy\Doctor;

use Splicewire\Beam\Doctor\RetiredMigrationAudit;

/**
 * beam-tenancy's own retirement declarations, on the instrument beam already uses for its own (see
 * {@see RetiredMigrationAudit}: a retirement must be DECLARED, because a retired stub's defining property is
 * that it is absent).
 *
 * migration-flatten 06 retired `add_domain_purpose_to_domains_table` (02 rulings: delete-noop). `create_domains_table`
 * already declares `purpose`, `primary_purpose` and the named unique, and the ALTER's duplicate check runs over rows
 * that cannot exist on a fresh install; it only ever repaired hosts migrated before the create carried that shape,
 * which are now re-baselined (migration-flatten 04) instead. Advisory, like its parent: a host still holding the
 * published copy is told, and deleting the file stays the host's call.
 */
class BeamTenancyRetiredMigrationAudit extends RetiredMigrationAudit
{
    /**
     * The published path with its timestamp stripped => what supersedes it. Bare keys mean the migrations root,
     * where this package publishes.
     *
     * @var array<string, string>
     */
    public const TENANCY_RETIRED = [
        'add_domain_purpose_to_domains_table' => 'create_domains_table',
    ];

    public function __construct(?string $migrationsPath = null)
    {
        parent::__construct(self::TENANCY_RETIRED, $migrationsPath);
    }
}
