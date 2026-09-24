<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_groups', function (Blueprint $table): void {
            $table->timestamp('psychologist_deleted_at')->nullable();
            $table->index(['owner_id', 'psychologist_deleted_at']);
        });
        // One statement preserves the historical deletion time before clearing it.
        DB::table('gp_groups')->where('status', 'rejected')->whereNotNull('deleted_at')
            ->update(['psychologist_deleted_at' => DB::raw('deleted_at'), 'deleted_at' => null]);
    }

    public function down(): void
    {
        // Preserve hidden records as soft-deleted under the old visibility model.
        // An administrator's later deletion timestamp takes precedence.
        DB::table('gp_groups')->whereNotNull('psychologist_deleted_at')->whereNull('deleted_at')
            ->update(['deleted_at' => DB::raw('psychologist_deleted_at')]);
        Schema::table('gp_groups', function (Blueprint $table): void {
            $table->dropIndex(['owner_id', 'psychologist_deleted_at']);
            $table->dropColumn('psychologist_deleted_at');
        });
    }
};
