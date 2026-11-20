<?php

namespace Splicewire\Beam\Tenancy\Synthetic;

use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * The synthetic-reporting toggle as a declared Config Section (config-sections ticket 09; PRD story 41): an
 * OPERATOR-tier LIVE key, so the operator flips it through `beam-config` (the Config area, the op, or the command) and
 * the next read sees it, with no redeploy. It replaces the cache-over-config override.
 */
#[ConfigRoot(root: 'beam.tenancy.synthetic', title: 'Synthetic tenants in reporting', description: 'Whether financial-truth reads leave Synthetic Tenants out.')]
class SyntheticReportingSection extends ConfigSection
{
    public function __construct(
        #[Title('Exclude synthetic tenants from reporting'), ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live, env: 'SYNTHETIC_EXCLUDE_FROM_REPORTING')]
        public bool $exclude_from_reporting,

        #[Title('Legacy override cache store')]
        public ?string $cache_store,
    ) {}
}
