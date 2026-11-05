<?php

namespace Splicewire\Beam\Tenancy\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Tenancy\Synthetic\SyntheticReporting;

/**
 * Whether reporting leaves Synthetic Tenants out right now, and why: the declared `output:` of the
 * `tenants/synthetic-reporting` operation. See {@see SyntheticReporting}.
 */
class SyntheticReportingData extends BeamData
{
    public function __construct(
        #[Description('True when reporting reads leave Synthetic Tenants out.')]
        public bool $excludes,
        #[Description("The operator's runtime override, or null when none is set and config answers.")]
        public ?bool $override,
        #[Description('The deploy-time answer from config (`beam.tenancy.synthetic.exclude_from_reporting`).')]
        public bool $configured,
        #[Description('Which answer is in force: `override` or `config`.')]
        public string $source,
    ) {}
}
