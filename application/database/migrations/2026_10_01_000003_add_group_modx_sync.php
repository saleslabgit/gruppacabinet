<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('public_site_resource_id')->nullable()->unique();
            $table->unsignedBigInteger('modx_sync_revision')->default(0);
            $table->string('modx_sync_status', 32)->nullable();
            foreach (['requested', 'started', 'failed'] as $event) {
                $table->timestamp('modx_sync_'.$event.'_at')->nullable();
            }
            $table->timestamp('modx_synced_at')->nullable();
            $table->string('modx_sync_error_code', 64)->nullable();
            $table->string('modx_remote_cover_path')->nullable();
            $table->string('modx_remote_cover_source_path')->nullable();
            $table->char('modx_sync_payload_hash', 64)->nullable();
            $table->string('modx_sync_request_key')->nullable();
            $table->boolean('modx_cover_cleanup_warning')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('gp_groups', function (Blueprint $table): void {
            $table->dropUnique(['public_site_resource_id']);
            $table->dropColumn(['public_site_resource_id', 'modx_sync_revision', 'modx_sync_status',
                'modx_sync_requested_at', 'modx_sync_started_at', 'modx_synced_at', 'modx_sync_failed_at',
                'modx_sync_error_code', 'modx_remote_cover_path', 'modx_remote_cover_source_path',
                'modx_sync_payload_hash', 'modx_sync_request_key', 'modx_cover_cleanup_warning']);
        });
    }
};
