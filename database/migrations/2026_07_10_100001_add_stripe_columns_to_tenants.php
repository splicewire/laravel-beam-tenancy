<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier billing columns for the Billable Tenant — the party Splicewire charges.
 *
 * Retargeted from Cashier's default `users` table: Splicewire bills the Tenant/account
 * (a consumer, a business, or a broker), never an individual central user. Re-dated to run
 * AFTER the tenants table exists (created 2019_09_15). These must be REAL columns, so they
 * are also registered in Tenant::getCustomColumns() to escape stancl's `data` virtual-column
 * folding — otherwise Cashier's where('stripe_id', …) queries would miss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }
};
