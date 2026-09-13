<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A parent/child pair whose names sort parent-first, so a pooled delete in table-name order meets the
 * foreign key and must retry — the path a single-table fixture never exercised (review finding).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pooled_a_folders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('name');
        });

        Schema::create('pooled_b_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('folder_id');
            $table->foreign('folder_id')->references('id')->on('pooled_a_folders');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pooled_b_items');
        Schema::dropIfExists('pooled_a_folders');
    }
};
