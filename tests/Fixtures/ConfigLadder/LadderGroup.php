<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture ({@see LadderSection}, config-sections 07 review-r1): a tenant-overridable record, for a lock on one child. */
class LadderGroup extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public bool $review,
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public string $label,
    ) {}
}
