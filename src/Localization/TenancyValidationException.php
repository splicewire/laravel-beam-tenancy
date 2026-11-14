<?php

namespace Splicewire\Beam\Tenancy\Localization;

use Splicewire\Beam\Localization\CodedValidationException;

class TenancyValidationException extends CodedValidationException
{
    public function __construct($validator, $response = null, $errorBag = 'default')
    {
        parent::__construct($validator, $response, $errorBag);

        $failed = $validator->failed();
        if (isset($failed['slug']['Max']) || isset($failed['slug']['Regex'])) {
            $this->codes['slug'] = [TenancyError::InvalidSlug->code()];
        }
    }
}
