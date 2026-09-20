<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_dictionaries', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('gp_dictionary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_id')->constrained('gp_dictionaries')->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['dictionary_id', 'code']);
        });

        Schema::create('gp_users', function (Blueprint $table) {
            $table->id();
            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email');
            $table->foreignId('education_type_id')->nullable()->constrained('gp_dictionary_items')->restrictOnDelete();
            $table->string('other_education')->nullable();
            $table->string('modality_program')->nullable();
            $table->string('training_center')->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->unsignedInteger('training_hours')->nullable();
            $table->string('license_number')->nullable();
            $table->date('license_expires_at')->nullable();
            $table->text('group_leading_experience')->nullable();
            $table->unsignedInteger('groups_conducted_count')->nullable();
            $table->boolean('documents_confirmed')->nullable();
            $table->boolean('education_confirmed')->nullable();
            $table->boolean('live_session_ready')->nullable();
            $table->timestamp('personal_data_consent_at')->nullable();
            $table->string('personal_data_consent_version')->nullable();
            $table->string('status')->default('pending')->index();
            $table->boolean('accept')->default(false);
            $table->boolean('disabled')->default(false)->index();
            $table->boolean('free')->default(false)->index();
            $table->boolean('admin')->default(false);
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE gp_users ADD active_email VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN email ELSE NULL END) STORED');
        DB::statement('CREATE UNIQUE INDEX gp_users_active_email_unique ON gp_users (active_email)');

        Schema::create('gp_user_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('gp_users')->restrictOnDelete();
            $table->string('type');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('gp_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('owner_id')->constrained('gp_users')->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->boolean('disabled')->default(false);
            $table->boolean('accept')->default(false);
            $table->boolean('free')->default(false);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('schedule')->nullable();
            $table->foreignId('format_id')->nullable()->constrained('gp_dictionary_items')->restrictOnDelete();
            $table->unsignedInteger('meeting_duration_minutes')->nullable();
            $table->unsignedInteger('participant_capacity')->nullable();
            $table->foreignId('gender_id')->nullable()->constrained('gp_dictionary_items')->restrictOnDelete();
            $table->unsignedBigInteger('meeting_price')->nullable();
            $table->text('moderator_comment')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('expiry_warning_sent_at')->nullable();
            $table->unsignedInteger('placement_days')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('owner_id', 'gp_groups_owner_id_lookup');
            $table->index(['status', 'expires_at']);
            $table->index(['owner_id', 'status']);
        });

        Schema::create('gp_group_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('gp_groups')->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->nullable()->constrained('gp_users')->nullOnDelete();
            $table->string('actor_type', 20);
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('gp_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('gp_users')->nullOnDelete();
            $table->string('actor_type', 20);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 100);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });

        Schema::create('gp_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('gp_users')->restrictOnDelete();
            $table->foreignId('group_id')->constrained('gp_groups')->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->string('order_number', 64)->unique();
            $table->string('transaction_id', 128)->nullable()->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('BYN');
            $table->string('status')->default('created')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('refund_comment')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('last_status_check_at')->nullable();
            $table->unsignedInteger('status_check_attempts')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('created_at');
        });

        Schema::create('gp_payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('gp_payments')->nullOnDelete();
            $table->string('order_number', 64)->nullable()->index();
            $table->string('transaction_id', 128)->nullable()->index();
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->boolean('processed')->default(false);
            $table->string('result')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('gp_group_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('gp_groups')->restrictOnDelete();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('phone');
            $table->string('phone_normalized');
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();
            $table->index('group_id', 'gp_group_applications_group_id_lookup');
            $table->index(['group_id', 'processed_at']);
            $table->index('created_at');
        });

        Schema::create('gp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('type', 20);
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('gp_settings');
        Schema::dropIfExists('gp_group_applications');
        Schema::dropIfExists('gp_payment_notifications');
        Schema::dropIfExists('gp_payments');
        Schema::dropIfExists('gp_audit_log');
        Schema::dropIfExists('gp_group_status_history');
        Schema::dropIfExists('gp_groups');
        Schema::dropIfExists('gp_user_documents');
        Schema::dropIfExists('gp_users');
        Schema::dropIfExists('gp_dictionary_items');
        Schema::dropIfExists('gp_dictionaries');
    }
};
