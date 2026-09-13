<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A migration that lands AFTER the pool was prepared, with a foreign key into a table whose key is
 * already `(tenant_id, id)` (pooled-storage ticket 11 review finding).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pooled_c_labels', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->foreignUuid('folder_id')->nullable()->constrained('pooled_a_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pooled_c_labels');
    }
};
