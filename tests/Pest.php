<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Splicewire\Beam\Tenancy\Tests\Postgres\PostgresTestCase;
use Splicewire\Beam\Tenancy\Tests\TestCase;

// The sqlite harness for everything except the Postgres-gated folder, which binds its own case below
// (Pest refuses two cases on one folder, so the root is enumerated rather than `'.'`).
uses(TestCase::class, RefreshDatabase::class)->in('*.php', 'Data', 'Destinations', 'Doctor', 'Support');

// pooled-storage ticket 05: real Postgres when PG_TEST_DATABASE is set, a visible skip when it is not.
uses(PostgresTestCase::class)->in('Postgres');
