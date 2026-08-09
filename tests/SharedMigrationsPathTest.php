<?php

namespace Splicewire\Beam\Tenancy\Tests;

use Illuminate\Support\Facades\Schema;

/**
 * Verifies the host-side half of the "everything is shared by default" convention
 * (BeamMultiTenancyServiceProvider::registerSharedMigrationsPath): a migration sitting in
 * database/migrations/shared/ actually runs via a plain `migrate`, and the same directory is pushed
 * onto Stancl's tenant `--path` array — with no package ever calling loadMigrationsFrom() on its own
 * un-published source.
 */
class SharedMigrationsPathTest extends TestCase
{
    private string $sharedDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sharedDir = database_path('migrations/shared');

        if (! is_dir($this->sharedDir)) {
            mkdir($this->sharedDir, 0755, true);
        }

        file_put_contents(
            $this->sharedDir.'/2000_01_01_000000_create_widgets_table.php',
            <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('widgets', function (Blueprint $table) {
                        $table->id();
                    });
                }
            };
            PHP,
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->sharedDir.'/2000_01_01_000000_create_widgets_table.php');
        @rmdir($this->sharedDir);

        parent::tearDown();
    }

    public function test_a_migration_in_the_shared_directory_runs_via_a_plain_migrate(): void
    {
        $this->assertFalse(Schema::hasTable('widgets'));

        $this->artisan('migrate', ['--force' => true])->run();

        $this->assertTrue(Schema::hasTable('widgets'));
    }

    public function test_the_shared_directory_is_pushed_onto_the_tenant_migration_path(): void
    {
        $this->assertContains($this->sharedDir, config('tenancy.migration_parameters.--path', []));
    }

    public function test_calling_the_registration_twice_does_not_duplicate_the_tenant_path_entry(): void
    {
        $provider = new \Splicewire\Beam\Tenancy\BeamMultiTenancyServiceProvider($this->app);

        $method = new \ReflectionMethod($provider, 'registerSharedMigrationsPath');
        $method->invoke($provider);
        $method->invoke($provider);

        $occurrences = array_count_values(config('tenancy.migration_parameters.--path', []))[$this->sharedDir] ?? 0;

        $this->assertSame(1, $occurrences);
    }
}
