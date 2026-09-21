<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_integration_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 128)->collation('utf8mb4_bin')->unique();
            $table->string('endpoint', 64);
            $table->char('request_fingerprint', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_integration_requests');
    }
};
