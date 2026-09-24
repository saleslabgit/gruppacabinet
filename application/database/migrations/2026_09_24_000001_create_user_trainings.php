<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gp_user_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('gp_users')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('modality_program')->nullable();
            $table->string('training_center')->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->unsignedInteger('training_hours')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'position']);
        });
        Schema::table('gp_user_documents', function (Blueprint $table) {
            $table->foreignId('user_training_id')->nullable()->constrained('gp_user_trainings')->nullOnDelete();
        });

        // Include soft-deleted users and retain exact legacy values without model events.
        DB::transaction(function (): void {
            DB::table('gp_users')->where(function ($query): void {
                foreach (['modality_program', 'training_center', 'graduation_year', 'training_hours'] as $field) {
                    $query->orWhereNotNull($field);
                }
            })->orderBy('id')->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $trainingId = DB::table('gp_user_trainings')->insertGetId([
                        'user_id' => $user->id, 'position' => 0,
                        'modality_program' => $user->modality_program, 'training_center' => $user->training_center,
                        'graduation_year' => $user->graduation_year, 'training_hours' => $user->training_hours,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('gp_user_documents')->where('user_id', $user->id)->where('type', 'certificate')
                        ->update(['user_training_id' => $trainingId]);
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('gp_user_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_training_id');
        });
        Schema::dropIfExists('gp_user_trainings');
    }
};
