<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures;

use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * A `tenancy.database.managers` entry that records instead of creating.
 *
 * The harness is in-memory sqlite, so there is no storage a real manager could create and no way
 * to observe provisioning by looking at Postgres. What the provisioning decision actually asserts
 * is behavioural — "the seeder ASKS the host's registered manager to create this tenant's
 * database, exactly once, and skips when it already exists" — and that is a question a recorder
 * answers exactly and a real manager answers not at all.
 */
class RecordingTenantDatabaseManager implements TenantDatabaseManager
{
    /** @var list<string> Database names `createDatabase()` was called for, in order. */
    public static array $created = [];

    /** @var list<string> Names already reported as existing, seeding `databaseExists()`. */
    public static array $existing = [];

    public static function reset(): void
    {
        static::$created = [];
        static::$existing = [];
    }

    public function setConnection(string $connection): void {}

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $name = (string) $tenant->database()->getName();

        static::$created[] = $name;
        static::$existing[] = $name;

        return true;
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return true;
    }

    public function databaseExists(string $name): bool
    {
        return in_array($name, static::$existing, true);
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['database'] = $databaseName;

        return $baseConfig;
    }
}
