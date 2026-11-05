<?php

namespace Splicewire\Beam\Tenancy\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Data\BeamData;

/**
 * The body of the `tenants/synthetic-reporting` operation: set the runtime override, or clear it.
 */
class SyntheticReportingInputData extends BeamData
{
    public function __construct(
        #[Description('True to leave Synthetic Tenants out of reporting, false to keep them in, null to clear the override so config answers again.')]
        public ?bool $excludeFromReporting,
    ) {}

    /**
     * `present` rather than `required`: null is a real answer here (clear the override), and an absent key is a
     * malformed request, not a request to clear.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'excludeFromReporting' => ['present', 'nullable', 'boolean'],
        ];
    }
}
