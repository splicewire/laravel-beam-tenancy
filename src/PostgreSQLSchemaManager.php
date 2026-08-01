<?php

namespace Splicewire\Beam\Tenancy;

use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager as BaseManager;

class PostgreSQLSchemaManager extends BaseManager
{
    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        // Include 'public' in search_path so extensions (vector, fuzzystrmatch)
        // installed in the public schema remain accessible from tenant schemas.
        $baseConfig['search_path'] = "$databaseName,public";

        return $baseConfig;
    }
}
