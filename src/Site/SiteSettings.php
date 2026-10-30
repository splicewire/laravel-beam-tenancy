<?php

namespace Splicewire\Beam\Tenancy\Site;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

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
    /** The one row's key: the table's primary key is fixed, so a schema holds one row at most (the database enforces it). */
    public const SINGLETON = 1;

    protected $table = 'site_settings';

    public $incrementing = false;

    protected $fillable = ['brand_name', 'logo', 'ga4_measurement_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['id' => self::SINGLETON];

    /** GA4's measurement id: `G-` then uppercase letters or digits. A `GTM-` container id is not one. */
    public static function isMeasurementId(string $id): bool
    {
        return preg_match('/^G-[A-Z0-9]{4,}$/', $id) === 1;
    }

    protected static function booted(): void
    {
        static::saving(fn (self $settings) => self::admit($settings));
    }

    /** The rules every write passes, through save() or put(). */
    private static function admit(self $settings): void
    {
        // A tenant's search_path is '<tenant>,public': unmigrated, an unqualified write would land in the shared public
        // table every unmigrated tenant reads (d6). hasTable() asks the current schema only, so refuse until it has its own.
        if (! self::migratedHere($settings)) {
            throw new SiteSettingsNotMigrated('Site Settings are not migrated in this schema yet; run its migrations before saving them.');
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
    }

    /** This schema's Site Settings, or null when none are saved (or the table is absent, e.g. an unmigrated host). */
    public static function current(): ?self
    {
        if (! self::migratedHere(new self)) {
            return null;
        }

        return self::query()->find(self::SINGLETON);
    }

    /** Whether THIS schema holds the table: on PostgreSQL, hasTable() reads current_schema(), never the rest of the search_path. */
    private static function migratedHere(self $model): bool
    {
        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }

    /**
     * Save this schema's one row: the given attributes merge into the existing row, or create it. One atomic upsert on the
     * fixed key, so two first saves at once still leave one row, the later one's values merged into it.
     */
    public static function put(array $attributes): self
    {
        $settings = (new self)->fill($attributes);
        self::admit($settings);
        // Only the columns given (as admit() normalized them), so the upsert merges rather than clearing the others.
        $values = array_intersect_key($settings->getAttributes(), array_flip(array_intersect(array_keys($attributes), $settings->getFillable())));

        if ($values === []) {
            self::query()->insertOrIgnore(['id' => self::SINGLETON]);
        } else {
            self::query()->upsert([['id' => self::SINGLETON] + $values], ['id'], array_keys($values));
        }

        return self::query()->findOrFail(self::SINGLETON);
    }
}
