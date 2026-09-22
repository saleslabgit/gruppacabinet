<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_payments', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('binding_verified_at')->nullable();
            $table->string('provider_order_id', 128)->nullable();
            $table->timestamp('recovery_started_at')->nullable();
            $table->unsignedInteger('extension_days')->nullable();
            $table->timestamp('extension_expires_at')->nullable();
            $table->string('extension_status', 20)->nullable();
            $table->string('product_effect', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gp_payments', fn (Blueprint $table) => $table->dropColumn(['started_at', 'binding_verified_at', 'provider_order_id', 'recovery_started_at', 'extension_days', 'extension_expires_at', 'extension_status', 'product_effect']));
    }
};
