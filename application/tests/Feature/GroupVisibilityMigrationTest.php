<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GroupVisibilityMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_backfill_and_rollback_preserve_retained_records(): void
    {
        $migration = require database_path('migrations/2026_09_24_000002_add_psychologist_deleted_at_to_groups.php');
        $migration->down();
        $owner = User::create(['email' => 'migration@example.test']);
        $ids = [];
        foreach (['rejected', 'draft', 'active', 'approved', 'expired', 'revision', 'moderation', 'awaiting_payment'] as $status) {
            $group = Group::create(['owner_id' => $owner->id, 'status' => $status, 'deleted_at' => '2026-09-01 10:11:12']);
            $ids[$status] = $group->id;
        }
        $visible = Group::create(['owner_id' => $owner->id, 'status' => 'rejected']);
        $group = Group::withTrashed()->findOrFail($ids['rejected']);
        $group->statusHistory()->create(['from_status' => 'moderation', 'to_status' => 'rejected', 'actor_type' => 'system']);
        $group->applications()->create(['first_name' => 'Synthetic', 'last_name' => 'Migration', 'phone' => 'test', 'phone_normalized' => '']);
        $group->payments()->create(['owner_id' => $owner->id, 'type' => 'placement', 'order_number' => 'migration', 'amount' => 1, 'status' => 'refunded']);
        $before = DB::table('gp_groups')->orderBy('id')->get()->toJson();
        $related = [];
        foreach (['gp_group_status_history', 'gp_group_applications', 'gp_payments'] as $table) {
            $related[$table] = DB::table($table)->get()->toJson();
        }
        $migration->up();
        $restored = Group::findOrFail($ids['rejected']);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('2026-09-01 10:11:12', $restored->psychologist_deleted_at->toDateTimeString());
        $this->assertSame('rejected', $restored->status->value);
        $this->assertSame([$visible->id], Group::visibleToPsychologist($owner->id)->pluck('id')->all());
        foreach ($ids as $status => $id) {
            if ($status !== 'rejected') {
                $this->assertSoftDeleted('gp_groups', ['id' => $id, 'deleted_at' => '2026-09-01 10:11:12']);
                $this->assertNull(Group::withTrashed()->findOrFail($id)->psychologist_deleted_at);
            }
        }
        $migration->down();
        $this->assertFalse(Schema::hasColumn('gp_groups', 'psychologist_deleted_at'));
        $this->assertSame($before, DB::table('gp_groups')->orderBy('id')->get()->toJson());
        $migration->up();
        DB::table('gp_groups')->where('id', $restored->id)->update(['deleted_at' => '2026-09-24 12:00:00']);
        DB::table('gp_groups')->where('id', $visible->id)->update(['psychologist_deleted_at' => '2026-09-23 12:00:00']);
        $migration->down();
        $this->assertSoftDeleted('gp_groups', ['id' => $restored->id, 'deleted_at' => '2026-09-24 12:00:00']);
        $this->assertSoftDeleted('gp_groups', ['id' => $visible->id, 'deleted_at' => '2026-09-23 12:00:00']);
        foreach ($related as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->get()->toJson());
        }
        $migration->up();
    }
}
