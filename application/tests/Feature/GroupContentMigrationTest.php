<?php

namespace Tests\Feature;

use App\Models\Group;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GroupContentFixture as Fixture;
use Tests\TestCase;

class GroupContentMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_additive_upgrade_preserves_legacy_data_and_enforces_relational_constraints(): void
    {
        $migration = require database_path('migrations/2026_10_01_000002_add_group_content.php');
        $migration->down();
        $owner = DB::table('gp_users')->insertGetId(['email' => 'legacy-content@example.test', 'status' => 'approved']);
        $uuid = (string) Str::uuid();
        $id = DB::table('gp_groups')->insertGetId(['owner_id' => $owner, 'public_uuid' => $uuid, 'description' => 'Original', 'schedule' => 'Arbitrary legacy', 'status' => 'active']);
        $before = (array) DB::table('gp_groups')->find($id);
        $migration->up();
        $group = Group::findOrFail($id);
        foreach ($before as $column => $value) {
            $this->assertEquals($value, $group->getRawOriginal($column));
        }
        foreach (['full_description_html', 'cover_path', 'cover_original_name', 'cover_mime_type', 'cover_size', 'meeting_days', 'start_time', 'frequency', 'city', 'group_type_id'] as $column) {
            $this->assertNull($group->$column);
        }
        $type = Fixture::item('group_type');
        $approach = Fixture::item('group_approach');
        $tag = Fixture::item('group_tag');
        $group->update(['group_type_id' => $type->id, 'meeting_days' => ['mon'], 'cover_size' => 123]);
        $group->approaches()->attach($approach);
        $group->tags()->attach($tag);
        $this->assertSame(['mon'], $group->fresh()->meeting_days);
        $this->assertSame(123, $group->fresh()->cover_size);
        $this->assertSame($type->id, $group->fresh()->groupType->id);
        foreach ([fn () => $type->delete(), fn () => $approach->delete(), fn () => $tag->delete(),
            fn () => $group->approaches()->attach($approach), fn () => $group->tags()->attach($tag),
            fn () => $group->update(['group_type_id' => 999999]), fn () => $group->tags()->attach(999999)] as $invalid) {
            try {
                $invalid();
                $this->fail('Expected FK or unique constraint');
            } catch (QueryException $exception) {
                $this->assertSame('23000', $exception->errorInfo[0]);
            }
        }
        $group->forceDelete();
        $this->assertDatabaseCount('gp_group_approaches', 0);
        $this->assertDatabaseCount('gp_group_tags', 0);
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('gp_dictionary_items', ['id' => $type->id]);
    }
}
