<?php

namespace Splicewire\Beam\Tenancy\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Tenancy\Models\TenantInvitation;

/**
 * A tenant invitation as TenantInvitationController emits it — the {@see TenantInvitation}
 * model serialization, constructed live and carried in the standard `{data: …}` envelope
 * (envelope normalization: a list on index; the acted-on invitation on send/resend/revoke).
 * Every serialized column is on the wire, so the DTO declares them all; the client aliases only
 * the safe subset it reads ({id, email, role, acceptedAt, createdAt}). `acceptedAt` is
 * nullable (a still-pending invite).
 *
 * The single-use accept `token` is NOT on this wire: it is a bearer secret and the model marks
 * it `$hidden`, so no serialization (index list or create echo) carries it. The follow-up the
 * C09 close-it flagged is now closed — the leak is fixed at the source (the model), not papered
 * over at the DTO.
 *
 * Properties are camelCase, like every other DTO in the estate — the wire carries `tenantId`,
 * `acceptedAt` and friends. They previously mirrored the model's columns verbatim, which made this
 * the last snake_case wire in the package.
 *
 * Callers construct it EXPLICITLY (named arguments) rather than `from($invitation->toArray())`, which
 * would need a snake_case input mapper. That is deliberate on two counts: it matches how the sibling
 * membership row is built, and an input-mapping attribute would be silently inert wherever
 * spatie/laravel-data's structure caching is enabled until that cache is cleared — a deploy-order
 * trap this DTO has no reason to take on for one call site.
 */
#[TypeScript]
class TenantInvitationData extends Data
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $email,
        public string $role,
        public string $invitedBy,
        public ?string $acceptedAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
