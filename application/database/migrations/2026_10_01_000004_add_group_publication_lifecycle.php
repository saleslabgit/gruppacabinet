<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_groups', function (Blueprint $table): void {
            $table->string('meeting_price_currency')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedBigInteger('modx_publication_revision')->default(0);
            $table->string('modx_publication_desired', 16)->nullable();
            $table->string('modx_publication_status', 32)->nullable();
            foreach (['requested', 'started', 'synced', 'failed'] as $event) {
                $table->timestamp('modx_publication_'.$event.'_at')->nullable();
            }
            $table->string('modx_publication_error_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gp_groups', function (Blueprint $table): void {
            $table->dropColumn(['meeting_price_currency', 'paused_at', 'modx_publication_revision',
                'modx_publication_desired', 'modx_publication_status', 'modx_publication_requested_at',
                'modx_publication_started_at', 'modx_publication_synced_at', 'modx_publication_failed_at',
                'modx_publication_error_code']);
        });
    }
};
