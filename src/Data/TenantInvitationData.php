<?php

namespace Splicewire\Beam\Tenancy\Data;

use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Tenancy\Models\TenantInvitation;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A pending tenant invitation as TenantInvitationController::index emits it — the
 * {@see TenantInvitation} model serialization (the index returns `->get()` directly, a bare
 * array with no `data` envelope). Every serialized column is on the wire, so the DTO declares
 * them all; the client aliases only the safe subset it reads ({id, email, role, accepted_at,
 * created_at}). `accepted_at` is nullable (a still-pending invite).
 *
 * The single-use accept `token` is NOT on this wire: it is a bearer secret and the model marks
 * it `$hidden`, so no serialization (index list or create echo) carries it. The follow-up the
 * C09 close-it flagged is now closed — the leak is fixed at the source (the model), not papered
 * over at the DTO.
 *
 * C09 close-it: snake_case props mirror the model's serialized attributes verbatim.
 */
#[TypeScript]
class TenantInvitationData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $email,
        public string $role,
        public string $invited_by,
        public ?string $accepted_at = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}
}
