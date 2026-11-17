<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder;

use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections ticket 07): a section for the tenant tier under stancl's real bootstrappers. `review` stands in
 * for beam-ux's `releases.review_required`; both keys are overridable up to the tenant tier.
 */
#[ConfigRoot(root: 'cs07', title: 'Ladder', description: 'A tenant-tier fixture.')]
class LadderSection extends ConfigSection
{
    public function __construct(
        #[Title('Review required'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public bool $review,
        #[Title('Theme'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public string $theme,
        #[Title('Group'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public LadderGroup $group,
    ) {}
}
