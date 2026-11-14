<?php

// localization 37 (RULING-04 M7, M14): tenancy's status lines, rendered for their reader from a stored reference or a
// known ref. The English is byte-identical to the description each marker stores (the two failure wrappers end before
// the exception text, which is kept as the unprojected diagnostic).
return [
    'provisioning_create_database' => 'Creating database',
    'provisioning_migrate_database' => 'Running migrations',
    'provisioning_complete' => 'Provisioning complete.',
    'pack_applying' => "Applying scaffold pack ':slug'",
    'pack_applied' => "Applied scaffold pack ':slug'",
    'pool_move_prepare_target' => "Preparing pool ':pool' (:schema)",
    'pool_move_copy' => "Copying rows from pool ':source' to pool ':target'",
    'pool_move_flip' => "Cutting over to pool ':target'",
    'pool_move_cleanup' => "Deleting the tenant's rows from pool ':source'",
    'pool_move_failed_before' => "Moving to pool ':target' failed BEFORE cutover — pool ':source' is still authoritative.",
    'pool_move_failed_after' => "Moved to pool ':target' — it is authoritative — but deleting the old rows from pool ':source' (:schema) failed; they need manual deletion.",
];
