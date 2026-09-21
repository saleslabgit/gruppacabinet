<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupApplication;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApplicationRetentionService;
use App\Services\SettingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApplicationRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_strict_cutoff_current_setting_historical_parents_idempotency_and_no_pii(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 21)->startOfDay());
        Mail::fake();
        Queue::fake();
        $setting = Setting::create(['key' => SettingService::PARTICIPANT_APPLICATION_RETENTION_MONTHS, 'type' => 'integer', 'value' => '12']);
        $settings = app(SettingService::class);
        $settings->invalidate($setting->key);
        $owner = User::create(['email' => 'retention-synthetic@example.test', 'status' => 'approved']);
        $group = Group::create(['owner_id' => $owner->id]);
        $deletedGroup = Group::create(['owner_id' => $owner->id]);
        $cutoff = now('UTC')->subMonthsNoOverflow(12);
        $old = GroupApplication::factory()->for($group)->create(['created_at' => $cutoff->copy()->subSecond()]);
        $processed = GroupApplication::factory()->for($group)->processed()->create(['created_at' => $cutoff->copy()->subSecond()]);
        $historical = GroupApplication::factory()->for($deletedGroup)->create(['created_at' => $cutoff->copy()->subSecond()]);
        $exact = GroupApplication::factory()->for($group)->create(['created_at' => $cutoff]);
        $new = GroupApplication::factory()->for($group)->create(['created_at' => $cutoff->copy()->addSecond()]);
        $deletedGroup->delete();
        $groupsBefore = Group::withTrashed()->get()->toArray();
        $usersBefore = User::withTrashed()->get()->toArray();
        $this->assertSame(0, Artisan::call('applications:cleanup'));
        $this->assertSame('Deleted applications: 3', trim(Artisan::output()));
        foreach ([$old, $processed, $historical] as $record) {
            $this->assertDatabaseMissing('gp_group_applications', ['id' => $record->id]);
            $this->assertStringNotContainsString($record->phone, Artisan::output());
            $this->assertStringNotContainsString($record->last_name, Artisan::output());
        }
        $this->assertSame([$exact->id, $new->id], GroupApplication::orderBy('id')->pluck('id')->all());
        Artisan::call('applications:cleanup');
        $this->assertSame('Deleted applications: 0', trim(Artisan::output()));
        $setting->update(['value' => '6']);
        $settings->invalidate($setting->key);
        Artisan::call('applications:cleanup');
        $this->assertSame('Deleted applications: 2', trim(Artisan::output()));
        $this->assertSame($groupsBefore, Group::withTrashed()->get()->toArray());
        $this->assertSame($usersBefore, User::withTrashed()->get()->toArray());
        foreach (['gp_payments', 'gp_group_status_history', 'gp_audit_log', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_cleanup_does_not_skip_chunks_and_reads_retention_once(): void
    {
        $owner = User::create(['email' => 'retention-chunks@example.test', 'status' => 'approved']);
        $group = Group::create(['owner_id' => $owner->id]);
        $row = ['group_id' => $group->id, 'last_name' => 'Синтетический', 'first_name' => 'Тест', 'phone' => '+12025550100', 'phone_normalized' => '+12025550100', 'created_at' => now()->subYears(2), 'updated_at' => now()];
        foreach (array_chunk(array_fill(0, 1003, $row), 250) as $chunk) {
            DB::table('gp_group_applications')->insert($chunk);
        }
        $settings = \Mockery::mock(SettingService::class);
        $settings->shouldReceive('participantApplicationRetentionMonths')->once()->andReturn(12);
        $this->assertSame(1003, (new ApplicationRetentionService($settings))->cleanup());
        $this->assertDatabaseCount('gp_group_applications', 0);
    }

    public function test_month_end_cutoff_uses_calendar_month_without_overflow(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 3, 31)->startOfDay());
        $owner = User::create(['email' => 'retention-month@example.test', 'status' => 'approved']);
        $group = Group::create(['owner_id' => $owner->id]);
        GroupApplication::factory()->for($group)->create(['created_at' => '2026-02-27 23:59:59']);
        $exact = GroupApplication::factory()->for($group)->create(['created_at' => '2026-02-28 00:00:00']);
        $settings = \Mockery::mock(SettingService::class);
        $settings->shouldReceive('participantApplicationRetentionMonths')->once()->andReturn(1);
        $this->assertSame(1, (new ApplicationRetentionService($settings))->cleanup());
        $this->assertSame($exact->id, GroupApplication::sole()->id);
    }

    public function test_daily_overlap_protection_preserves_existing_expiry_schedule(): void
    {
        $events = collect(app(Schedule::class)->events());
        $cleanup = $events->first(fn ($event) => str_contains($event->command ?? '', 'applications:cleanup'));
        $expiry = $events->first(fn ($event) => str_contains($event->command ?? '', 'groups:expire'));
        $this->assertNotNull($cleanup);
        $this->assertSame('0 0 * * *', $cleanup->expression);
        $this->assertTrue($cleanup->withoutOverlapping);
        $this->assertSame('* * * * *', $expiry->expression);
        $this->assertTrue($expiry->withoutOverlapping);
    }
}
