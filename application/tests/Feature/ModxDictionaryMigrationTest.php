<?php

namespace Tests\Feature;

use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Group;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModxDictionaryMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_preserves_existing_names_ids_codes_and_group_references(): void
    {
        $migration = require database_path('migrations/2026_10_01_000001_add_modx_dictionary_metadata.php');
        $migration->down();
        // Use SQL for the pre-upgrade schema: Eloquent caches guardable columns
        // per process, whereas a real deploy starts fresh application processes.
        $formatId = DB::table('gp_dictionaries')->where('code', 'group_format')->value('id');
        DB::table('gp_dictionaries')->where('id', $formatId)->update(['name' => 'Existing custom name']);
        $firstId = DB::table('gp_dictionary_items')->insertGetId(['dictionary_id' => $formatId, 'code' => 'legacy_one', 'name' => 'First legacy']);
        $secondId = DB::table('gp_dictionary_items')->insertGetId(['dictionary_id' => $formatId, 'code' => 'legacy_two', 'name' => 'Second legacy']);
        $ownerId = DB::table('gp_users')->insertGetId(['email' => 'migration@example.test', 'status' => 'approved']);
        $groupId = DB::table('gp_groups')->insertGetId(['owner_id' => $ownerId, 'format_id' => $firstId, 'public_uuid' => (string) Str::uuid()]);
        $migration->up();
        $format = Dictionary::findOrFail($formatId);
        $first = DictionaryItem::findOrFail($firstId);
        $second = DictionaryItem::findOrFail($secondId);
        $group = Group::findOrFail($groupId);
        $this->assertSame('Existing custom name', $format->fresh()->name);
        $this->assertSame('format', $format->fresh()->modx_tv_name);
        $this->assertSame($first->id, $group->fresh()->format_id);
        foreach ([$first, $second] as $item) {
            $this->assertSame($item->code, $item->fresh()->code);
            $this->assertNull($item->fresh()->modx_value);
            $this->assertTrue($item->fresh()->active);
        }
        $this->assertSame(5, Dictionary::whereNotNull('modx_tv_name')->count());
        $first->update(['modx_value' => 'exact']);
        try {
            $second->update(['modx_value' => 'exact']);
            $this->fail('Expected unique remote identity.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
        try {
            DB::table('gp_dictionaries')->where('code', 'gender')->update(['modx_tv_name' => 'format']);
            $this->fail('Expected unique TV ownership.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
    }
}
