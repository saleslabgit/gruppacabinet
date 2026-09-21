<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Jobs\SendExpiryWarning;
use App\Mail\ExpiryWarningMail;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Services\GroupLifecycleService;
use App\Services\GroupWorkflow;
use App\Services\SettingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Support\SuccessfulMailFake;
use Tests\TestCase;

class ExpiryWarningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0));
        URL::forceRootUrl('http://localhost');
        config(['mail.default' => 'smtp']);
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'psychologist@gruppa.test')->sole();
        $this->owner->update(['free' => true]);
        SuccessfulMailFake::install();
    }

    private function group(array $data = []): Group
    {
        return Group::create($data + ['owner_id' => $this->owner->id, 'title' => 'Synthetic warning group', 'status' => 'active', 'expires_at' => now()->addDays(2), 'placement_days' => 30]);
    }

    private function job(Group $group): SendExpiryWarning
    {
        return new SendExpiryWarning($group->id, $group->expires_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_scheduler_threshold_exclusions_schedule_and_query_count(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'groups:queue-expiry-warnings'));
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        foreach ([['expires_at' => now()->addDays(3)->addSecond()], ['expires_at' => now()], ['expires_at' => now()->subSecond()], ['expires_at' => null], ['disabled' => true], ['expiry_warning_sent_at' => now()]] as $data) {
            $this->group($data);
        }
        foreach (['draft', 'approved', 'expired', 'rejected', 'moderation', 'revision', 'awaiting_payment'] as $status) {
            $this->group(['status' => $status]);
        }
        $deleted = $this->group();
        $deleted->delete();
        $this->group(['expires_at' => now()->addDays(3)]);
        $this->group(['expires_at' => now()->addSecond()]);
        DB::enableQueryLog();
        $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 2')->assertSuccessful();
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'gp_users'));
        $this->assertCount(1, $queries); // Owner eligibility is one EXISTS query, no per-owner lookup.
        DB::disableQueryLog();
        $this->assertDatabaseCount('jobs', 2);
        $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 0')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_owner_eligibility_and_disabled_group_reenable(): void
    {
        $group = $this->group(['disabled' => true]);
        $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 0')->assertSuccessful();
        $group->update(['disabled' => false]);
        foreach ([['disabled' => true], ['disabled' => false, 'admin' => true], ['admin' => false, 'status' => 'pending'], ['status' => 'rejected']] as $data) {
            $this->owner->update($data);
            $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 0')->assertSuccessful();
            $this->job($group)->handle(app(SettingService::class));
        }
        $this->owner->update(['status' => 'approved']);
        $this->owner->delete();
        $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 0')->assertSuccessful();
        $this->job($group)->handle(app(SettingService::class));
        Mail::assertNothingSent();
        $this->assertNull($group->fresh()->expiry_warning_sent_at);
        $this->owner->restore();
        $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 1')->assertSuccessful();
    }

    public function test_job_rechecks_stale_state_and_current_threshold(): void
    {
        foreach ([['status' => 'expired'], ['expires_at' => now()->addDays(10)], ['expires_at' => now()], ['expiry_warning_sent_at' => now()], ['disabled' => true], ['deleted_at' => now()]] as $change) {
            $group = $this->group();
            $job = $this->job($group);
            $group->update($change);
            $job->handle(app(SettingService::class));
        }
        $group = $this->group();
        Setting::where('key', SettingService::EXPIRY_WARNING_DAYS)->update(['value' => '1']);
        app(SettingService::class)->invalidate(SettingService::EXPIRY_WARNING_DAYS);
        $this->job($group)->handle(app(SettingService::class));
        Mail::assertNothingSent();
    }

    public function test_worker_holds_unique_lock_during_send_marks_after_and_releases(): void
    {
        $group = $this->group();
        $this->artisan('groups:queue-expiry-warnings')->assertSuccessful();
        Mail::shouldReceive('to')->once()->with($this->owner->email)->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnUsing(function () use ($group) {
            $this->assertNull($group->fresh()->expiry_warning_sent_at);
            Artisan::call('groups:queue-expiry-warnings');
            $this->assertSame("Queued expiry warnings: 0\n", Artisan::output());
            $this->assertDatabaseCount('jobs', 1);

            return SuccessfulMailFake::receipt();
        });
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--once' => true]));
        $this->assertNotNull($group->fresh()->expiry_warning_sent_at);
        $this->assertDatabaseCount('jobs', 0);
        $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 0')->assertSuccessful();
        $this->assertDatabaseCount('gp_payments', 0);
    }

    public function test_smtp_failure_retry_and_lifecycle_independence(): void
    {
        $group = $this->group();
        $original = $group->only(['status', 'expires_at', 'placement_days']);
        $job = $this->job($group);
        Mail::shouldReceive('to')->twice()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('synthetic SMTP failure'));
        try {
            $job->handle(app(SettingService::class));
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Expiry warning delivery failed; retry the queued job.', $exception->getMessage());
        }
        $this->assertNull($group->fresh()->expiry_warning_sent_at);
        $this->assertEquals($original, $group->fresh()->only(array_keys($original)));
        $due = $this->group(['expires_at' => now()]);
        $this->artisan('groups:expire')->expectsOutput('Expired groups: 1')->assertSuccessful();
        $this->assertSame(GroupStatus::Expired, $due->fresh()->status);
        Mail::shouldReceive('send')->once()->andReturn(SuccessfulMailFake::receipt());
        $job->handle(app(SettingService::class));
        $this->assertNotNull($group->fresh()->expiry_warning_sent_at);
        $this->assertEquals($original, $group->fresh()->only(array_keys($original)));
        $this->assertDatabaseCount('gp_payments', 0);
    }

    public function test_transport_cancellation_does_not_mark_and_database_worker_retries(): void
    {
        $group = $this->group();
        $this->artisan('groups:queue-expiry-warnings')->assertSuccessful();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturn(null);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        $this->assertNull($group->fresh()->expiry_warning_sent_at);
        $this->assertSame(1, DB::table('jobs')->sole()->attempts);
        $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 0')->assertSuccessful();
        SuccessfulMailFake::install();
        DB::table('jobs')->update(['available_at' => now()->timestamp - 1]);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        $this->assertNotNull($group->fresh()->expiry_warning_sent_at);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertSent(ExpiryWarningMail::class, 1);
    }

    public function test_late_mail_cannot_mark_extended_period_and_new_period_can_warn(): void
    {
        $group = $this->group();
        $oldJob = $this->job($group);
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnUsing(function () use ($group) {
            app(GroupLifecycleService::class)->extend($group, $this->owner);

            return SuccessfulMailFake::receipt();
        });
        $oldJob->handle(app(SettingService::class));
        $group->refresh();
        $this->assertNull($group->expiry_warning_sent_at);
        $this->assertNotSame($oldJob->uniqueId(), $this->job($group)->uniqueId());
        Mail::swap(new MailManager($this->app));
        SuccessfulMailFake::install();
        $oldJob->handle(app(SettingService::class));
        Mail::assertNothingSent();
        $this->travelTo($group->expires_at->copy()->subDays(2));
        $this->job($group)->handle(app(SettingService::class));
        Mail::assertSent(ExpiryWarningMail::class, 1);
    }

    public function test_republication_and_activation_inside_threshold_queue_once(): void
    {
        $group = $this->group(['status' => 'approved', 'expiry_warning_sent_at' => now()]);
        Setting::where('key', SettingService::PLACEMENT_DURATION_DAYS)->update(['value' => '2']);
        app(SettingService::class)->invalidate(SettingService::PLACEMENT_DURATION_DAYS);
        $group->update(['expires_at' => now()->subDay()]);
        app(GroupWorkflow::class)->activate($group, User::where('email', 'admin@gruppa.test')->sole());
        $group->refresh();
        $this->assertNull($group->expiry_warning_sent_at);
        $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 1')->assertSuccessful();
        $this->artisan('groups:queue-expiry-warnings')->expectsOutput('Queued expiry warnings: 0')->assertSuccessful();
        $this->job($group)->handle(app(SettingService::class));
        Mail::assertSent(ExpiryWarningMail::class, 1);
    }
}
