<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pool-level data a host may exclude from row-level security (`postgres-rls.exclude`). Tests that
 * exclude it prove a move or a pooled delete never touches it through a tenant frame (ticket 13 review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pooled_shared_settings', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->string('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pooled_shared_settings');
    }
};
