<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder;

use Schemastud\DataSchemas\Attributes\MapValues;
use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections ticket 09, review S3): the flagship's `app.chat` shape on the tenant tier, the root
 * TenantRoleAlias reads (`default_model`) and the extend-only catalog it validates against (`models`).
 *
 * Ticket 16: the three tower writers also publish the operator "pinned provider" (`default_provider`) here, so the
 * fixture carries it too — a nullable, optional tenant-ceiling scalar (a tenant that never pins one inherits the floor),
 * exactly the shape {@see \Splicewire\Beam\Tenancy\Config\TenantConfigOverridePublisher} drafts.
 */
#[ConfigRoot(root: 'app.chat', title: 'Chat', description: 'A tenant-tier chat fixture.')]
class ChatLadderSection extends ConfigSection
{
    /** @param  array<string, array<string, mixed>>  $models */
    public function __construct(
        #[Title('Default model'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public string $default_model,
        #[Title('Models'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live, merge: ConfigMerge::Extend), MapValues]
        public array $models,
        #[Title('Default provider'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public ?string $default_provider = null,
    ) {}
}
