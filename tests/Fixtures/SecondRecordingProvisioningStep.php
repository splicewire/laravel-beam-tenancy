<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

/** The Supersede case's second sentinel — distinct class so "only B ran" is assertable. */
class SecondRecordingProvisioningStep extends RecordingProvisioningStep
{
    /** @var list<string> */
    public static array $ran = [];
}
