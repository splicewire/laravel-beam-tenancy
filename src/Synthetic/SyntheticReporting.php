<?php

namespace Splicewire\Beam\Tenancy\Synthetic;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Tenancy\Data\SyntheticReportingData;

/**
 * Whether financial-truth reads leave Synthetic Tenants out — the one answer `Tenant::reportable()` reads.
 *
 * The answer is a runtime override, if an operator set one, and otherwise
 * `config('beam.tenancy.synthetic.exclude_from_reporting')`. The override sits in the cache rather than in
 * config, so flipping it needs no redeploy and every worker sees it on its next read.
 *
 * The override is a CENTRAL fact, and it is read through a named store on purpose. While tenancy is initialized,
 * stancl's `CacheTenancyBootstrapper` swaps in a cache manager that tags every PROXIED call (`Cache::get()`) with
 * the tenant, so a tenant-context `Cache::get(self::CACHE_KEY)` would miss the central key and quietly fall back
 * to config. `store()` is a real method on that manager, not a proxied one, so the repository it returns is
 * untagged and reads the same key in either context. (stancl's Redis bootstrapper would prefix keys per tenant;
 * a host that prefixes its cache's Redis connection must point `cache_store` at an unprefixed store.)
 *
 * Every change is written to the central activity trail (`log_name` `synthetic-reporting`): who flipped it,
 * from what, to what.
 */
class SyntheticReporting
{
    public const CACHE_KEY = 'beam.tenancy.synthetic.exclude_from_reporting';

    public const LOG_NAME = 'synthetic-reporting';

    /** True when `reportable()` must drop Synthetic Tenants. */
    public function excludes(): bool
    {
        return $this->override() ?? $this->configured();
    }

    /** The operator's runtime override, or null when none is set. */
    public function override(): ?bool
    {
        $value = $this->store()->get(self::CACHE_KEY);

        return is_bool($value) ? $value : null;
    }

    /** The deploy-time answer, from config. */
    public function configured(): bool
    {
        return (bool) config('beam.tenancy.synthetic.exclude_from_reporting', false);
    }

    /**
     * Set the override (`true`/`false`), or clear it (`null`) so config answers again. Logged centrally.
     */
    public function set(?bool $exclude, ?Model $causer = null): SyntheticReportingData
    {
        $before = $this->state();

        $exclude === null
            ? $this->store()->forget(self::CACHE_KEY)
            : $this->store()->forever(self::CACHE_KEY, $exclude);

        $after = $this->state();

        CentralActivityLog::create([
            'log_name' => self::LOG_NAME,
            'description' => match ($exclude) {
                true => 'Synthetic tenants excluded from reporting',
                false => 'Synthetic tenants included in reporting',
                null => 'Synthetic reporting override cleared',
            },
            'event' => $exclude === null ? 'cleared' : 'set',
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer !== null ? (string) $causer->getKey() : null,
            'properties' => [
                'before' => $before->toArray(),
                'after' => $after->toArray(),
            ],
        ]);

        return $after;
    }

    public function state(): SyntheticReportingData
    {
        $override = $this->override();

        return new SyntheticReportingData(
            excludes: $override ?? $this->configured(),
            override: $override,
            configured: $this->configured(),
            source: $override === null ? 'config' : 'override',
        );
    }

    /** Resolved on every call: tenancy re-binds `cache` on initialize and end, so a held manager goes stale. */
    private function store(): Repository
    {
        return app('cache')->store(config('beam.tenancy.synthetic.cache_store'));
    }
}
