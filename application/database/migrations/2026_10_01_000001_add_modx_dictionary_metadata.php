<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_dictionaries', function (Blueprint $table): void {
            $table->string('modx_tv_name', 64)->nullable()->unique();
            $table->timestamp('last_synced_at')->nullable();
        });
        Schema::table('gp_dictionary_items', function (Blueprint $table): void {
            // NO PAD, case/accent-sensitive: remote identity includes trailing spaces.
            $table->string('modx_value', 255)->collation('utf8mb4_0900_bin')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unique(['dictionary_id', 'modx_value']);
        });

        DB::transaction(function (): void {
            foreach ([
                'group_format' => ['format', 'Group format'],
                'gender' => ['gender', 'Gender'],
                'group_type' => ['groupType', 'Group type'],
                'group_approach' => ['approaches', 'Approaches'],
                'group_tag' => ['tags', 'Tags'],
            ] as $code => [$tv, $name]) {
                if (! DB::table('gp_dictionaries')->where('code', $code)->exists()) {
                    DB::table('gp_dictionaries')->insert([
                        'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('gp_dictionaries')->where('code', $code)->update(['modx_tv_name' => $tv]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('gp_dictionary_items', function (Blueprint $table): void {
            $table->dropUnique(['dictionary_id', 'modx_value']);
            $table->dropColumn(['modx_value', 'last_synced_at']);
        });
        Schema::table('gp_dictionaries', function (Blueprint $table): void {
            $table->dropUnique(['modx_tv_name']);
            $table->dropColumn(['modx_tv_name', 'last_synced_at']);
        });
    }
};
