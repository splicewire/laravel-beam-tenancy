<?php

namespace Splicewire\Beam\Tenancy\Synthetic;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Tenancy\Data\SyntheticReportingData;
use Throwable;

/**
 * Whether financial-truth reads leave Synthetic Tenants out: the one answer `Tenant::reportable()` reads.
 *
 * config-sections ticket 09: the answer is the OPERATOR tier of beam's Override Ladder
 * ({@see SyntheticReportingSection}, `beam.tenancy.synthetic` `/exclude_from_reporting`, a live key), over the
 * deploy-time config. An operator flips it through `beam-config` (the Config area), the `tenants/synthetic-reporting`
 * op, or `splicewire:beam:tenancy:synthetic-reporting`; all three publish an operator-tier override, and every flip,
 * whichever surface made it, is written to the central activity trail (`log_name` `synthetic-reporting`) by
 * {@see self::recordPublish()} (observing the scope's publish), with who, from what, to what.
 *
 * The cache-over-config override is RETIRED. A value an older release left in that cache is still honoured, read-only,
 * while no operator-tier entry exists, so a deploy changes nothing until the next flip, which clears it.
 */
class SyntheticReporting
{
    public const ROOT = 'beam.tenancy.synthetic';

    public const POINTER = '/exclude_from_reporting';

    /** The retired cache override's key, read-only for continuity until the first operator-tier flip. */
    public const CACHE_KEY = 'beam.tenancy.synthetic.exclude_from_reporting';

    public const LOG_NAME = 'synthetic-reporting';

    /** The causer of the flip in progress, handed to the publish observer by {@see set()}. */
    protected static ?Model $flipCauser = null;

    /** The answer just before this section's publish, captured on the scope's `updating` event. */
    protected static ?SyntheticReportingData $pendingBefore = null;

    /**
     * Wire the audit to the scope model (config-sections 09, review Q4; the same pattern as 10b's ux discard observer):
     * `updating` captures the answer before a publish of this section, `updated` records the flip. A publish is the
     * store saving the scope MODEL with a new `published_version` (pinned by a test).
     */
    public static function observePublishes(): void
    {
        ConfigOverrideScope::updating(function (ConfigOverrideScope $scope): void {
            if ($scope->section === self::ROOT && $scope->isDirty('published_version')) {
                static::$pendingBefore = app(static::class)->state();
            }
        });
        ConfigOverrideScope::updated(function (ConfigOverrideScope $scope): void {
            $before = static::$pendingBefore;
            static::$pendingBefore = null;

            if ($before !== null) {
                static::recordPublish($scope, $before);
            }
        });
    }

    /** True when `reportable()` must drop Synthetic Tenants. */
    public function excludes(): bool
    {
        return $this->state()->excludes;
    }

    /** The operator tier's published value, or null when the operator has set none. */
    public function override(): ?bool
    {
        try {
            $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator);
            $scope = $store->scope(self::ROOT);
            $entry = $scope === null ? null : ($store->publishedLayer($scope)[self::POINTER] ?? null);
        } catch (Throwable) {
            return null;
        }

        return $entry !== null && is_bool($entry->value) ? $entry->value : null;
    }

    /** The deploy-time answer: the package and host tiers, without the operator's override. */
    public function configured(): bool
    {
        try {
            $base = app(ConfigOverlay::class)->base(self::ROOT);
        } catch (Throwable) {
            $base = null;
        }

        return (bool) (is_array($base) && array_key_exists('exclude_from_reporting', $base)
            ? $base['exclude_from_reporting']
            : config('beam.tenancy.synthetic.exclude_from_reporting', false));
    }

    /**
     * Set the operator-tier override (`true`/`false`), or clear it (`null`) so config answers again. Publishes through
     * the ladder; the publish observer writes the activity entry (with `$causer`), and the retired cache value is cleared.
     */
    public function set(?bool $exclude, ?Model $causer = null): SyntheticReportingData
    {
        $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator);
        $op = $exclude === null ? ['pointer' => self::POINTER, 'op' => 'unset'] : ['pointer' => self::POINTER, 'op' => 'set', 'value' => $exclude];

        static::$flipCauser = $causer;

        try {
            $store->saveDraft(self::ROOT, [$op], $causer !== null ? (string) $causer->getKey() : null);
            $store->publish(self::ROOT);
        } finally {
            static::$flipCauser = null;
        }

        $this->legacyStore()->forget(self::CACHE_KEY);
        $overlay = app(ConfigOverlay::class);
        $overlay->recheckNow();
        $overlay->sync();

        return $this->state();
    }

    public function state(): SyntheticReportingData
    {
        $override = $this->override();
        $legacy = $override === null ? $this->legacyOverride() : null;
        $chosen = $override ?? $legacy;

        return new SyntheticReportingData(
            // With no operator override, the effective config IS the answer (the ladder has nothing on top of it).
            excludes: $chosen ?? (bool) config('beam.tenancy.synthetic.exclude_from_reporting', false),
            override: $chosen,
            configured: $this->configured(),
            source: $override !== null ? 'override' : ($legacy !== null ? 'legacy-cache' : 'config'),
        );
    }

    /**
     * The audit, for EVERY flip (config-sections 09, review Q4): laravel-beam has no publish event, but
     * ConfigOverrideStore::publish() moves the scope's `published_version` on the Eloquent model, so its `updated`
     * event marks a publish. For this section's scope it records the before and after effective answer.
     */
    public static function recordPublish(ConfigOverrideScope $scope, SyntheticReportingData $before): void
    {
        if ($scope->section !== self::ROOT || ! $scope->wasChanged('published_version')) {
            return;
        }

        $reporting = app(static::class);
        $after = $reporting->state();
        $causer = static::$flipCauser ?? auth()->user();

        CentralActivityLog::create([
            'log_name' => self::LOG_NAME,
            'description' => match ($after->override) {
                true => 'Synthetic tenants excluded from reporting',
                false => 'Synthetic tenants included in reporting',
                null => 'Synthetic reporting override cleared',
            },
            'event' => $after->override === null ? 'cleared' : 'set',
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer !== null ? (string) $causer->getKey() : null,
            'properties' => [
                'before' => $before->toArray(),
                'after' => $after->toArray(),
            ],
        ]);
    }

    /** The retired cache override, read-only (a value an older release left), or null. */
    protected function legacyOverride(): ?bool
    {
        try {
            $value = $this->legacyStore()->get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_bool($value) ? $value : null;
    }

    /** Resolved on every call: tenancy re-binds `cache` on initialize and end, so a held manager goes stale. */
    private function legacyStore(): Repository
    {
        return app('cache')->store(config('beam.tenancy.synthetic.cache_store'));
    }
}
