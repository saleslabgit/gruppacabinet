<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplicationHideMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_and_rollback_preserve_application_rows(): void
    {
        $migration = require database_path('migrations/2026_10_03_000001_add_psychologist_deleted_at_to_group_applications.php');
        $migration->down();
        $user = User::create(['email' => 'migration-hide@example.test']);
        $group = Group::create(['owner_id' => $user->id]);
        $row = GroupApplication::factory()->for($group)->create();
        $before = $row->fresh()->getAttributes();
        $migration->up();
        $this->assertNull($row->fresh()->psychologist_deleted_at);
        $row->update(['psychologist_deleted_at' => now()]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('gp_group_applications', 'psychologist_deleted_at'));
        $this->assertSame($before, $row->fresh()->getAttributes());
        $migration->up();
    }
}
