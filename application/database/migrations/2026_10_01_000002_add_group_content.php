<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gp_groups', function (Blueprint $table): void {
            $table->longText('full_description_html')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('cover_original_name')->nullable();
            $table->string('cover_mime_type', 100)->nullable();
            $table->unsignedBigInteger('cover_size')->nullable();
            $table->json('meeting_days')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->string('frequency')->nullable();
            $table->string('city')->nullable();
            $table->foreignId('group_type_id')->nullable()->constrained('gp_dictionary_items')->restrictOnDelete();
        });
        foreach (['gp_group_approaches', 'gp_group_tags'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->foreignId('group_id')->constrained('gp_groups')->cascadeOnDelete();
                $table->foreignId('dictionary_item_id')->constrained('gp_dictionary_items')->restrictOnDelete();
                $table->unique(['group_id', 'dictionary_item_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_group_tags');
        Schema::dropIfExists('gp_group_approaches');
        Schema::table('gp_groups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('group_type_id');
            $table->dropColumn(['full_description_html', 'cover_path', 'cover_original_name', 'cover_mime_type', 'cover_size',
                'meeting_days', 'start_time', 'frequency', 'city']);
        });
    }
};
