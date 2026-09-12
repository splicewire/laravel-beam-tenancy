<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Splicewire\Beam\Tenancy\Tests\Postgres\PostgresTestCase;
use Splicewire\Beam\Tenancy\Tests\TestCase;

// The sqlite harness for everything except the Postgres-gated folder, which binds its own case below
// (Pest refuses two cases on one folder, so the root is enumerated rather than `'.'`).
uses(TestCase::class, RefreshDatabase::class)->in('*.php', 'Data', 'Destinations', 'Doctor', 'Support');

// pooled-storage ticket 05: real Postgres when PG_TEST_DATABASE is set, a visible skip when it is not.
uses(PostgresTestCase::class)->in('Postgres');

/**
 * Provision one pooled tenant the way stancl's CreateDatabase job would, minus the queue. Shared by
 * every file under tests/Postgres — it lived in one of them, so a filtered run of another file
 * failed with "undefined function" (pooled-storage ticket 10).
 */
function provisionPooled(string $id): Splicewire\Beam\Tenancy\Tenant
{
    $tenant = Splicewire\Beam\Tenancy\Tenant::create(['id' => $id, 'name' => ucfirst($id), 'slug' => $id]);
    $tenant->markPooled('default')->save();
    $tenant->database()->makeCredentials();
    $tenant->database()->manager()->createDatabase($tenant);

    return Splicewire\Beam\Tenancy\Tenant::find($id);
}
