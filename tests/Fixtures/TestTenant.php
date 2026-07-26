<?php

namespace Splicewire\Beam\MultiTenancy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\MultiTenancy\Concerns\DesignatedSystemTenant;
use Stancl\VirtualColumn\VirtualColumn;

class TestTenant extends Model
{
    use DesignatedSystemTenant;
    use VirtualColumn;

    protected $table = 'tenants';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
        ];
    }
}
