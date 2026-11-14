<?php

namespace Splicewire\Beam\Tenancy\Localization;

use Splicewire\Beam\Data\BeamData;

class TenancyErrorData extends BeamData
{
    public function __construct(public string $message, public string $code) {}
}
