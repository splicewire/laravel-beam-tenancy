<?php

namespace Splicewire\Beam\Tenancy\Data;

use Illuminate\Validation\Rule;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Data\BeamData;

/**
 * The change-a-member's-role body (api-surface-coherence ticket 64).
 *
 * The vocabulary is {@see Role}, the same enum the `roles` read endpoint publishes, so the rule stays a
 * `Rule::in(Role::values())` rather than freezing a copy of the list here — a deployment that adds a role
 * gains it on both surfaces at once.
 */
class TenantMemberRoleInputData extends BeamData
{
    public function __construct(
        #[Description('The role to move the member to. Must be one of the values the member-roles endpoint lists as assignable, ownership transfer included.')]
        public string $role,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'role' => ['required', Rule::in(Role::values())],
        ];
    }
}
