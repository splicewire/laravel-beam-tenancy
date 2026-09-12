<?php

namespace Splicewire\Beam\Tenancy\Doctor;

use Illuminate\Database\DatabaseManager;

/**
 * The ADVISORY half of {@see PooledStorageAudit}: the rushing frame audit alone (is the session setting
 * live on the probe connection?). A separate class because the doctor manifest is keyed by audit class
 * and its gate flag is per registration — a Warn from an advisory sub-audit inside the gate registration
 * would fail a `--floor=warn` run (pooled-storage ticket 06, spec review).
 */
class PooledStorageFrameAudit extends PooledStorageAudit
{
    public function __construct(DatabaseManager $db)
    {
        parent::__construct($db, gates: false);
    }
}
