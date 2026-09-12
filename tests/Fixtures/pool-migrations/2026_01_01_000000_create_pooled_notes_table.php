<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pooled_notes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('slug');
            $table->string('title');
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pooled_notes');
    }
};
