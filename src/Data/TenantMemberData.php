<?php

namespace Splicewire\Beam\Tenancy\Data;

use Splicewire\Beam\Data\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A tenant team member row TenantMemberController::index emits — the user projected with
 * their pivot role and accepted-at timestamp. `name` is nullable; `joined_at` (the pivot
 * `accepted_at`) is nullable (a seat that was attached without an accept). The index returns a
 * bare array (no `data` envelope).
 *
 * C09 close-it: snake_case props mirror the hand-mapped wire verbatim (the TypeScript
 * transformer emits property names as-is — `joined_at` stays snake_case).
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
