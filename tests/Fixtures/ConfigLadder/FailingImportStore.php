<?php

namespace Splicewire\Beam\Tenancy\Tests\Fixtures\ConfigLadder;

use Illuminate\Database\QueryException;
use RuntimeException;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;

/**
 * Fixture (config-sections ticket 09, review S5): a store whose draft write fails with a database error whose message, as
 * Illuminate builds it, carries the SQL and its bindings, i.e. the value being written.
 */
class FailingImportStore extends ConfigOverrideStore
{
    public function saveDraft(string $root, array $ops, ?string $actor = null): ConfigOverrideScope
    {
        throw new QueryException('pgsql', 'insert into "beam_config_scopes" ("draft") values (?)', [json_encode($ops)], new RuntimeException('forced'));
    }
}
