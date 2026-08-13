<?php

namespace Splicewire\Beam\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\ModelStatus\Status as SpatieModelStatus;

/**
 * Status model bound to the central connection, so central models (e.g. Tenant)
 * record their status timeline in the central statuses table regardless of whether
 * a tenant context is currently initialized.
 */
class CentralStatus extends SpatieModelStatus
{
    use HasUuids;

    /**
     * @central-floor tenant-isolation — the status timeline of the isolation record itself:
     * a Tenant's provisioning steps are stamped before/while its schema is being stood up, so
     * the timeline must live where it can be written with no tenant context initialized.
     */
    protected $connection = 'central';

    protected $table = 'statuses';

    // Write microsecond precision so a fast (synchronous) provisioning pipeline produces
    // a strictly ordered timeline; without this, steps within the same second tie and the
    // rendered step trail order becomes ambiguous.
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
