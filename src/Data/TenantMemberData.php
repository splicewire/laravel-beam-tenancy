<?php

namespace Splicewire\Beam\Tenancy\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * A tenant team member row as TenantMemberController emits it — the user projected with
 * their pivot role and accepted-at timestamp. `name` is nullable; `joined_at` (the pivot
 * `accepted_at`) is nullable (a seat that was attached without an accept). Constructed live by
 * the controller (envelope normalization: the former bare-array index now rides the standard
 * `{data: …}` envelope, and update-role/remove carry the acted-on member in their data slot).
 *
 * C09 close-it: snake_case props mirror the wire verbatim (the TypeScript transformer emits
 * property names as-is — `joined_at` stays snake_case).
 */
#[TypeScript]
class TenantMemberData extends Data
{
    public function __construct(
        public string $id,
        public string $email,
        // The pivot `role` column is NOT NULL DEFAULT 'member', so it's always on the wire.
        public string $role,
        public ?string $name = null,
        public ?string $joined_at = null,
    ) {}
}
