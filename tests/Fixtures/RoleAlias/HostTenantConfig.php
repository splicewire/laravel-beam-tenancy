<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias;

use Stancl\Tenancy\Features\TenantConfig;

/** Fixture (config-sections ticket 15): a host's own subclass of stancl's TenantConfig, for the boot order refusal. */
class HostTenantConfig extends TenantConfig {}
