<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures\RoleAlias;

use Illuminate\Support\Arr;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Fixture (config-sections ticket 15): a stand-in for 07's ConfigLadderTenancyBootstrapper, which applies a tenant's
 * published config tier in the BOOTSTRAP phase, before stancl fires TenancyBootstrapped and so before stancl's
 * TenantConfig captures its "original". It writes `llm_config.ladder_default_model` into app.chat.default_model and
 * restores the value it found on revert. stancl resolves a fresh bootstrapper instance per transition, so what it found is
 * kept statically.
 */
class AppliesTenantDefaultModelBootstrapper implements TenancyBootstrapper
{
    /** @var array{0: mixed}|null */
    public static ?array $found = null;

    public function bootstrap(Tenant $tenant)
    {
        $value = Arr::get($tenant, 'llm_config.ladder_default_model');

        if (is_string($value)) {
            static::$found = [config('app.chat.default_model')];
            config()->set('app.chat.default_model', $value);
        }
    }

    public function revert()
    {
        if (static::$found !== null) {
            config()->set('app.chat.default_model', static::$found[0]);
            static::$found = null;
        }
    }
}
