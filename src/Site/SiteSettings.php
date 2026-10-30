<?php

namespace Splicewire\Beam\Tenancy\Site;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * A Hosted site's **Site Settings** (analytics ADR-0237 d6, EP-33): its brand (a name, else the tenant's, and a logo) and
 * its GA4 measurement id. They are the tenant's own: held in its schema, edited in its Site section by `site.edit`
 * holders, effective when saved, and never carried by a Scaffold Pack (a copied id would put the source tenant's
 * analytics property on every new site; laravel-beam-ux pins that exclusion).
 *
 * A Hosted site measures with a **GA4 measurement id** only: a container id is refused (ADR-0237 d5), because a container
 * runs whatever its owner adds; a tenant that needs one has a Connected site.
 *
 * @property string|null $brand_name
 * @property string|null $logo
 * @property string|null $ga4_measurement_id
 */
class SiteSettings extends Model
{
    protected $table = 'site_settings';

    protected $fillable = ['brand_name', 'logo', 'ga4_measurement_id'];

    /** GA4's measurement id: `G-` then uppercase letters or digits. A `GTM-` container id is not one. */
    public static function isMeasurementId(string $id): bool
    {
        return preg_match('/^G-[A-Z0-9]{4,}$/', $id) === 1;
    }

    protected static function booted(): void
    {
        static::saving(function (self $settings): void {
            // A tenant's search_path is '<tenant>,public': unmigrated, an unqualified write would land in the shared public
            // table every unmigrated tenant reads (d6). hasTable() asks the current schema only, so refuse until it has its own.
            if (! self::migratedHere($settings)) {
                throw new RuntimeException('Site Settings are not migrated in this schema yet; run its migrations before saving them.');
            }

            $id = $settings->ga4_measurement_id;
            if ($id !== null && $id !== '' && ! self::isMeasurementId($id)) {
                throw new InvalidArgumentException(
                    "[{$id}] is not a GA4 measurement id (G-…). A Hosted site measures with its own GA4 id; a container is refused."
                );
            }
            if ($id === '') {
                $settings->ga4_measurement_id = null;
            }
        });
    }

    /** This schema's Site Settings, or null when none are saved (or the table is absent, e.g. an unmigrated host). */
    public static function current(): ?self
    {
        if (! self::migratedHere(new self)) {
            return null;
        }

        return self::query()->orderBy('id')->first();
    }

    /** Whether THIS schema holds the table: on PostgreSQL, hasTable() reads current_schema(), never the rest of the search_path. */
    private static function migratedHere(self $model): bool
    {
        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }

    /** Save this schema's one row: the given attributes merge into the existing row, or create it. */
    public static function put(array $attributes): self
    {
        $settings = self::current() ?? new self;
        $settings->fill($attributes)->save();

        return $settings;
    }
}
