<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Splicewire\Beam\Data\MessageReferenceData;
use Splicewire\Beam\Localization\LocaleScope;
use Splicewire\Beam\Localization\Presentation\MessagePresenters;
use Splicewire\Beam\Localization\Presentation\PresentedLine;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Tenancy\Synthetic\SyntheticReporting;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Workflows\Data\StatusEventData;
use Splicewire\Beam\Workflows\Data\StatusStepData;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Display\StatusManager;
use Splicewire\Beam\Workflows\Facades\Status;

/*
 * localization 37 (RULING-04 M7, M11, M14; CLASSIFY-26 #6; SIGNATURES-37c): a tenant's saved status rows render for
 * their reader. New writes carry a stored reference; the two constant-ref provisioning rows render by identity; every
 * other existing row stays literal. Stored descriptions and properties are byte-identical apart from the reference, and
 * the raw diagnostic is never projected.
 */

beforeEach(function (): void {
    $this->app->register(ActivitylogServiceProvider::class);
    config([
        'database.connections.central' => config('database.connections.testing'),
        'activitylog.activity_model' => CentralActivityLog::class,
        'activitylog.buffer.enabled' => false,
        'beam.workflows.status_log_name' => 'status',
    ]);
    Schema::connection('central')->create('activity_log', function (Blueprint $table) {
        $table->increments('id');
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->json('properties')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->string('event')->nullable();
        $table->timestamps();
    });
    Status::swap(new StatusManager(new StatusEmitter(config(), app('events'))));

    // A Spanish host override for tenancy's keys (lang/vendor/<namespace>/<locale>/<group>.php).
    $this->lang = sys_get_temp_dir().'/tenancy-status-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->lang.'/vendor/beam-tenancy/es', 0777, true);
    file_put_contents($this->lang.'/vendor/beam-tenancy/es/status.php', '<?php return '.var_export([
        'provisioning_create_database' => 'Creando la base de datos',
        'provisioning_migrate_database' => 'Ejecutando migraciones',
        'provisioning_complete' => 'Aprovisionamiento completo.',
        'pack_applying' => "Aplicando el paquete ':slug'",
        'pack_applied' => "Paquete ':slug' aplicado",
        'pool_move_failed_before' => "Mover al pool ':target' falló ANTES del cambio; el pool ':source' sigue siendo la autoridad.",
    ], true).';');
    file_put_contents($this->lang.'/vendor/beam-tenancy/es/activity.php', '<?php return '.var_export([
        'synthetic_excluded' => 'Inquilinos sintéticos excluidos de los informes',
        'synthetic_included' => 'Inquilinos sintéticos incluidos en los informes',
        'synthetic_cleared' => 'Se borró la anulación de informes sintéticos',
    ], true).';');
    $this->app->useLangPath($this->lang);
    $this->app->forgetInstance('translation.loader');
    $this->app->forgetInstance('translator');
    config(['beam.localization.supported' => ['en', 'es'], 'app.locale' => 'en']);
    app()->setLocale('en');
});

/** @return list<StatusEventData> */
function tenantStatusLines(Tenant $tenant): array
{
    return $tenant->statusTimeline()->map(fn ($activity) => StatusEventData::fromActivity($activity))->values()->all();
}

/** @return list<array{string, string}> */
function storedTenantStatusBytes(Tenant $tenant): array
{
    return $tenant->statusTimeline()->map(fn ($a) => [$a->description, json_encode($a->properties)])->values()->all();
}

it('renders a provisioning run for two readers, the stored rows unchanged (M7, M11)', function (): void {
    $tenant = Tenant::create(['id' => 'loc-prov', 'name' => 'Loc', 'slug' => 'loc_prov']);
    $tenant->markProvisioning('provisioning_create_database', 'Creating database');
    $tenant->markProvisioning('provisioning_migrate_database', 'Running migrations');
    $tenant->markActive();
    $stored = storedTenantStatusBytes($tenant);

    $en = tenantStatusLines($tenant);
    $es = LocaleScope::run('es', fn () => tenantStatusLines($tenant));
    $esSteps = LocaleScope::run('es', fn () => StatusStepData::fold(tenantStatusLines($tenant)));

    expect(array_map(fn ($e) => $e->message, $en))->toBe(['Creating database', 'Running migrations', 'Provisioning complete.'])
        ->and(array_map(fn ($e) => $e->message, $es))->toBe(['Creando la base de datos', 'Ejecutando migraciones', 'Aprovisionamiento completo.'])
        ->and(array_map(fn ($e) => [$e->id, $e->state, $e->ref, $e->runId], $es))->toBe(array_map(fn ($e) => [$e->id, $e->state, $e->ref, $e->runId], $en))
        ->and(array_map(fn ($s) => $s->label, array_values(array_filter($esSteps, fn ($s) => $s->ref !== null))))->toContain('Creando la base de datos', 'Ejecutando migraciones')
        ->and(storedTenantStatusBytes($tenant))->toBe($stored);

    // The two provisioning steps render by their ref (no stored reference); markActive stores one.
    $rows = $tenant->statusTimeline()->values();
    expect($rows[0]->properties->has('messageReference'))->toBeFalse()
        ->and($rows[1]->properties->has('messageReference'))->toBeFalse()
        ->and($rows[2]->properties->get('messageReference'))->toBe(MessageReferenceData::of('beam-tenancy::status.provisioning_complete', 'Provisioning complete.')->toProperties())
        ->and($rows[2]->description)->toBe('Provisioning complete.');
});

it('renders a scaffold pack apply for two readers, with its slug as a parameter (M7)', function (): void {
    $tenant = Tenant::create(['id' => 'loc-pack', 'name' => 'Loc', 'slug' => 'loc_pack']);
    $tenant->provisioning_status = 'active';
    $tenant->save();
    $tenant->markPackApplyStarting('starter');
    $tenant->markPackApplyComplete('starter');

    $en = tenantStatusLines($tenant);
    $es = LocaleScope::run('es', fn () => tenantStatusLines($tenant));

    expect(array_map(fn ($e) => $e->message, $en))->toBe(["Applying scaffold pack 'starter'", "Applied scaffold pack 'starter'"])
        ->and(array_map(fn ($e) => $e->message, $es))->toBe(["Aplicando el paquete 'starter'", "Paquete 'starter' aplicado"])
        ->and($tenant->statusTimeline()->pluck('description')->all())->toBe(["Applying scaffold pack 'starter'", "Applied scaffold pack 'starter'"])
        ->and($tenant->statusTimeline()->first()->properties->get('ref'))->toBe('pack:starter');
});

it('keeps an existing row with no reference and no identity literal, for every reader', function (): void {
    $tenant = Tenant::create(['id' => 'loc-legacy', 'name' => 'Loc', 'slug' => 'loc_legacy']);
    // Rows as f32ddb5 wrote them: straight through the facade, no reference.
    Status::running($tenant, "Preparing pool 'b' (pool_b)", ref: 'pool_move_prepare_target', runId: 'r1');
    Status::complete($tenant, 'Provisioning complete.', runId: 'r1');
    Status::failed($tenant, "Moving to pool 'b' failed BEFORE cutover — pool 'a' is still authoritative: boom", runId: 'r1');

    $es = LocaleScope::run('es', fn () => tenantStatusLines($tenant));

    expect(array_map(fn ($e) => $e->message, $es))->toBe([
        "Preparing pool 'b' (pool_b)",
        'Provisioning complete.',
        "Moving to pool 'b' failed BEFORE cutover — pool 'a' is still authoritative: boom",
    ]);
});

it('stores a mixed M14 failure byte-identical, renders its wrapper, and never projects the diagnostic', function (): void {
    $tenant = Tenant::create(['id' => 'loc-mixed', 'name' => 'Loc', 'slug' => 'loc_mixed']);
    $english = "Moving to pool 'b' failed BEFORE cutover — pool 'a' is still authoritative: connection refused";
    $tenant->withStatusReference(
        MessageReferenceData::of('beam-tenancy::status.pool_move_failed_before', "Moving to pool ':target' failed BEFORE cutover — pool ':source' is still authoritative.", ['target' => 'b', 'source' => 'a']),
        'connection refused',
        fn () => $tenant->markFailed($english),
    );
    $row = $tenant->statusTimeline()->sole();

    $en = tenantStatusLines($tenant)[0];
    $es = LocaleScope::run('es', fn () => tenantStatusLines($tenant)[0]);

    expect($row->description)->toBe($english)
        ->and($row->properties->get('diagnostic'))->toBe('connection refused')
        ->and($en->message)->toBe("Moving to pool 'b' failed BEFORE cutover — pool 'a' is still authoritative.")
        ->and($es->message)->toBe("Mover al pool 'b' falló ANTES del cambio; el pool 'a' sigue siendo la autoridad.")
        ->and(json_encode($en->toArray()).json_encode($es->toArray()))->not->toContain('connection refused')
        ->and(json_encode(StatusStepData::fold([$en, $es])))->not->toContain('connection refused');
});

it('holds a reference for exactly one emission, and clears it after a throw', function (): void {
    $tenant = Tenant::create(['id' => 'loc-seam', 'name' => 'Loc', 'slug' => 'loc_seam']);
    $reference = MessageReferenceData::of('beam-tenancy::status.pool_move_flip', "Cutting over to pool ':target'", ['target' => 'b']);

    $tenant->withStatusReference($reference, null, function () use ($tenant): void {
        $tenant->markProvisioning('pool_move_flip', "Cutting over to pool 'b'");
        $tenant->markProvisioning('pool_move_cleanup', "Deleting the tenant's rows from pool 'a'");
    });
    expect(fn () => $tenant->withStatusReference($reference, null, fn () => throw new RuntimeException('step failed')))
        ->toThrow(RuntimeException::class, 'step failed');
    $tenant->markFailed('Later failure');

    $rows = $tenant->statusTimeline()->values();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->properties->get('messageReference'))->toBe($reference->toProperties())
        ->and($rows[1]->properties->has('messageReference'))->toBeFalse('only the next emission carries it')
        ->and($rows[2]->properties->has('messageReference'))->toBeFalse('a throw clears the held reference')
        ->and($rows[2]->description)->toBe('Later failure');
});

it('lets a host override that skips parent:: write a plain legacy row', function (): void {
    Tenant::create(['id' => 'loc-host', 'name' => 'Loc', 'slug' => 'loc_host']);
    $host = new class extends Tenant
    {
        protected $table = 'tenants';

        public function markFailed(string $reason): void
        {
            Status::failed($this, 'host: '.$reason, runId: $this->status_run_id);
        }
    };
    $tenant = $host->newQuery()->find('loc-host');

    $tenant->withStatusReference(MessageReferenceData::of('beam-tenancy::status.provisioning_complete', 'Provisioning complete.'), 'diag', fn () => $tenant->markFailed('custom'));

    $row = CentralActivityLog::query()->where('subject_id', 'loc-host')->sole();
    expect($row->description)->toBe('host: custom')
        ->and($row->properties->has('messageReference'))->toBeFalse()
        ->and($row->properties->has('diagnostic'))->toBeFalse();
});

it('renders the three synthetic-reporting rows by identity for two readers (#6)', function (?bool $exclude, string $event, string $english, string $spanish): void {
    $row = ['log_name' => SyntheticReporting::LOG_NAME, 'event' => $event, 'properties' => ['before' => ['excludes' => false], 'after' => ['excludes' => $exclude ?? false]]];
    $presenters = app(MessagePresenters::class);

    $en = $presenters->present('activity', $row, null, $english);
    $es = LocaleScope::run('es', fn () => $presenters->present('activity', $row, null, $english));

    expect($en->source)->toBe(PresentedLine::IDENTITY)->and($en->text)->toBe($english)
        ->and($es->source)->toBe(PresentedLine::IDENTITY)->and($es->text)->toBe($spanish);
})->with([
    'excluded' => [true, 'set', 'Synthetic tenants excluded from reporting', 'Inquilinos sintéticos excluidos de los informes'],
    'included' => [false, 'set', 'Synthetic tenants included in reporting', 'Inquilinos sintéticos incluidos en los informes'],
    'cleared' => [null, 'cleared', 'Synthetic reporting override cleared', 'Se borró la anulación de informes sintéticos'],
]);

it('leaves any other activity row to the next presenter or its literal', function (): void {
    $line = LocaleScope::run('es', fn () => app(MessagePresenters::class)->present('activity', ['log_name' => 'default', 'event' => 'set', 'properties' => []], null, 'Something else'));

    expect($line->source)->toBe(PresentedLine::LEGACY)->and($line->text)->toBe('Something else');
});
