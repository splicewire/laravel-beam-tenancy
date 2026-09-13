<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A child whose table name sorts BEFORE its parent's (`pooled_notes`), so a copy in table-name order
 * meets the foreign key first and must retry — the path pools:move depends on in a live target pool
 * whose foreign keys it cannot drop (pooled-storage ticket 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pooled_0_note_tags', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('note_id');
            $table->string('tag');
            $table->foreign('note_id')->references('id')->on('pooled_notes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pooled_0_note_tags');
    }
};
