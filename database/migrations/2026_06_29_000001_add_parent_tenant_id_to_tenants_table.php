<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('parent_tenant_id')->nullable()->after('slug');
            $table->foreign('parent_tenant_id')
                ->references('id')->on('tenants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['parent_tenant_id']);
            $table->dropColumn('parent_tenant_id');
        });
    }
};
