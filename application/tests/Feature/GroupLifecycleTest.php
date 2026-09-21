<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Services\GroupLifecycleService;
use App\Services\GroupStatusTransitionService;
use App\Services\SettingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'psychologist@gruppa.test')->sole();
        $this->owner->update(['free' => true]);
        $this->admin = User::where('email', 'admin@gruppa.test')->sole();
        Mail::fake();
        Queue::fake();
    }

    private function group(array $attributes = []): Group
    {
        return Group::create($attributes + ['owner_id' => $this->owner->id, 'title' => 'Lifecycle fixture',
            'status' => 'active', 'published_at' => now()->subDays(20), 'expires_at' => now()->addDays(10),
            'placement_days' => 30, 'expiry_warning_sent_at' => now()->subDay()]);
    }

    private function setting(string $key, int $value): void
    {
        Setting::where('key', $key)->update(['value' => (string) $value]);
        app(SettingService::class)->invalidate($key);
    }

    private function noSideEffects(): void
    {
        $this->assertDatabaseCount('gp_payments', 0);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_command_schedule_boundaries_and_repeat_processing(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'groups:expire'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $before = $this->group(['expires_at' => now()->addSecond()]);
        $due = $this->group(['expires_at' => now()]);
        $after = $this->group(['expires_at' => now()->subSecond(), 'disabled' => true]);
        $original = $due->only(['published_at', 'expires_at', 'placement_days', 'expiry_warning_sent_at']);
        $this->artisan('groups:expire')->expectsOutput('Expired groups: 2')->assertSuccessful();
        $this->assertSame(GroupStatus::Active, $before->fresh()->status);
        foreach ([$due, $after] as $group) {
            $this->assertSame(GroupStatus::Expired, $group->fresh()->status);
            $history = $group->statusHistory()->sole();
            $this->assertSame(GroupStatus::Active, $history->from_status);
            $this->assertSame(GroupStatus::Expired, $history->to_status);
            $this->assertSame('system', $history->actor_type);
            $this->assertNull($history->actor_id);
            $this->assertNull($history->comment);
        }
        $this->assertEquals($original, $due->fresh()->only(array_keys($original)));
        $this->artisan('groups:expire')->expectsOutput('Expired groups: 0')->assertSuccessful();
        $this->assertFalse(app(GroupLifecycleService::class)->expireOne($due->id));
        $this->assertDatabaseCount('gp_group_status_history', 2);
        $this->noSideEffects();
    }

    public function test_ignored_candidates_and_recheck_after_candidate_was_extended(): void
    {
        $null = $this->group(['expires_at' => null]);
        $deleted = $this->group(['expires_at' => now()->subDay()]);
        $deleted->delete();
        foreach (['draft', 'moderation', 'revision', 'approved', 'rejected', 'expired', 'awaiting_payment'] as $status) {
            $this->group(['status' => $status, 'expires_at' => now()->subDay()]);
        }
        $candidate = $this->group(['expires_at' => now()]);
        // The candidate ID was selected before another operation changed its expiry.
        $candidate->update(['expires_at' => now()->addDay()]);
        $service = app(GroupLifecycleService::class);
        $this->assertFalse($service->expireOne($candidate->id));
        $this->assertFalse($service->expireOne($deleted->id));
        $this->assertFalse($service->expireOne($null->id));
        $this->artisan('groups:expire')->expectsOutput('Expired groups: 0')->assertSuccessful();
        $this->assertDatabaseCount('gp_group_status_history', 0);
        $this->noSideEffects();
    }

    public function test_expiration_rolls_back_on_history_failure(): void
    {
        $group = $this->group(['expires_at' => now()]);
        $transition = \Mockery::mock(GroupStatusTransitionService::class);
        $transition->shouldReceive('transition')->once()->andReturnUsing(function (Group $group): void {
            $group->update(['status' => 'expired']);
            throw new \RuntimeException('History failure');
        });
        try {
            (new GroupLifecycleService($transition, app(SettingService::class)))->expireOne($group->id);
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('History failure', $exception->getMessage());
        }
        $this->assertSame(GroupStatus::Active, $group->fresh()->status);
        $this->assertDatabaseCount('gp_group_status_history', 0);
    }

    public function test_remaining_warning_threshold_and_current_settings_have_no_write_effect(): void
    {
        $this->setting('expiry_warning_days', 5);
        $group = $this->group(['expires_at' => now()->addDays(5)->addSecond()]);
        $marker = $group->expiry_warning_sent_at;
        $this->actingAs($this->owner);
        $this->get('/groups/'.$group->id)->assertOk()->assertViewHas('group', fn ($data) => $data['remaining_days'] === 6 && ! $data['warning']);
        $group->update(['expires_at' => now()->addDays(5)]);
        $this->get('/')->assertOk()->assertSee('До окончания размещения: 5 дн.')->assertViewHas('groups', fn ($rows) => $rows[0]['warning']);
        $this->setting('expiry_warning_days', 4);
        $this->get('/groups/'.$group->id)->assertOk()->assertViewHas('group', fn ($data) => ! $data['warning']);
        foreach ([now(), now()->subDay()] as $expiry) {
            $group->update(['expires_at' => $expiry]);
            $this->get('/groups/'.$group->id)->assertOk()->assertSee('Срок размещения истёк.')
                ->assertViewHas('group', fn ($data) => $data['remaining_days'] === 0 && $data['expiry_due']);
        }
        $this->assertEquals($marker, $group->fresh()->expiry_warning_sent_at);
        $this->noSideEffects();
    }

    public function test_active_extension_uses_current_owner_tariff_and_stored_duration(): void
    {
        $this->owner->update(['free' => false]);
        $group = $this->group(['placement_days' => 17]);
        $this->owner->update(['free' => true]);
        $this->setting('placement_duration_days', 45);
        $old = $group->expires_at->copy();
        $published = $group->published_at;
        $this->actingAs($this->owner)->get('/groups/'.$group->id.'/extension')->assertOk()
            ->assertSee('Бесплатное продление')->assertSee('17 дн.')->assertDontSee('WEBPAY');
        $this->post('/groups/'.$group->id.'/extension')->assertSessionHasErrors('confirmed');
        for ($i = 1; $i <= 2; $i++) {
            $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect(route('psychologist.groups.show', $group));
            $fresh = $group->fresh();
            $this->assertEquals($old->copy()->addDays(17 * $i), $fresh->expires_at);
            $this->assertSame(GroupStatus::Active, $fresh->status);
            $this->assertEquals($published, $fresh->published_at);
            $this->assertSame(17, $fresh->placement_days);
            $this->assertFalse($fresh->free);
            $this->assertNull($fresh->expiry_warning_sent_at);
        }
        $this->assertDatabaseCount('gp_group_status_history', 0);
        $this->noSideEffects();
    }

    public static function boundaryOffsets(): array
    {
        return [[-1, true], [0, true], [1, false]];
    }

    #[DataProvider('boundaryOffsets')]
    public function test_expired_window_boundary_and_preserved_dates(int $seconds, bool $allowed): void
    {
        $group = $this->group(['status' => 'expired', 'expires_at' => now()->subDays(30)->subSeconds($seconds)]);
        $dates = $group->only(['published_at', 'expires_at', 'placement_days']);
        $this->actingAs($this->owner);
        $response = $this->get('/groups/'.$group->id.'/extension')->assertOk();
        if ($allowed) {
            $response->assertSee('Продлить бесплатно');
            $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertSessionHasNoErrors();
            $this->assertSame(GroupStatus::Approved, $group->fresh()->status);
            $history = $group->statusHistory()->sole();
            $this->assertSame(GroupStatus::Expired, $history->from_status);
            $this->assertSame(GroupStatus::Approved, $history->to_status);
            $this->assertSame('system', $history->actor_type);
            $this->assertNull($history->actor_id);
        } else {
            $response->assertSee('Срок продления закончился.')->assertDontSee('Продлить бесплатно')->assertDontSee('extend-group-form');
            $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertSessionHasErrors('extension');
            $this->assertSame(GroupStatus::Expired, $group->fresh()->status);
            $this->assertDatabaseCount('gp_group_status_history', 0);
            $this->get('/')->assertSee('Создать новую группу')->assertDontSee('/groups/'.$group->id.'/extension');
        }
        $this->assertEquals($dates, $group->fresh()->only(array_keys($dates)));
        $this->noSideEffects();
    }

    public function test_settings_change_applies_to_expired_window_and_republication_uses_new_duration(): void
    {
        $group = $this->group(['status' => 'expired', 'expires_at' => now()->subDays(31), 'placement_days' => 17]);
        $uuid = $group->public_uuid;
        $this->actingAs($this->owner)->get('/groups/'.$group->id.'/extension')->assertSee('Срок продления закончился.');
        $this->setting('expired_extension_window_days', 32);
        $this->get('/groups/'.$group->id.'/extension')->assertSee('Продлить бесплатно');
        $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->get('/groups/'.$group->id)->assertSee('ожидает ручной публикации');
        $this->actingAs($this->admin)->get('/admin/groups?quick=approved')->assertViewHas('groups', fn ($groups) => $groups->pluck('id')->all() === [$group->id]);
        $this->get('/admin/groups/'.$group->id)->assertSee('Продление:')->assertSee($uuid);
        $this->setting('placement_duration_days', 43);
        $this->post('/admin/groups/'.$group->id.'/activate', ['confirmed' => 1])->assertSessionHasNoErrors();
        $fresh = $group->fresh();
        $this->assertEquals(now(), $fresh->published_at);
        $this->assertEquals(now()->addDays(43), $fresh->expires_at);
        $this->assertSame(43, $fresh->placement_days);
        $this->assertSame($uuid, $fresh->public_uuid);
        $this->assertNull($fresh->expiry_warning_sent_at);
        $this->assertSame(GroupStatus::Active, $fresh->status);
        $history = $group->statusHistory()->latest('id')->first();
        $this->assertSame(GroupStatus::Approved, $history->from_status);
        $this->assertSame(GroupStatus::Active, $history->to_status);
        $this->assertSame($this->admin->id, $history->actor_id);
        $this->noSideEffects();
    }

    public static function extensionStatuses(): array
    {
        return [['active'], ['expired']];
    }

    #[DataProvider('extensionStatuses')]
    public function test_paid_current_owner_cannot_extend_historically_free_group(string $status): void
    {
        $group = $this->group(['status' => $status, 'expires_at' => $status === 'expired' ? now()->subDay() : now()->addDay()]);
        $original = $group->fresh()->getAttributes();
        // Retain a stale authenticated model to prove the service re-reads the tariff.
        $this->actingAs($this->owner);
        User::whereKey($this->owner->id)->update(['free' => false]);
        try {
            app(GroupLifecycleService::class)->extend($group, $this->owner);
            $this->fail('A stale free tariff must not authorize an extension.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('extension', $exception->errors());
        }
        $this->get('/groups/'.$group->id.'/extension')->assertOk()->assertSee('Платное продление станет доступно')
            ->assertDontSee('WEBPAY')->assertDontSee('Продлить бесплатно')->assertDontSee('extend-group-form');
        $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertSessionHasErrors('extension');
        $this->assertSame($original, $group->fresh()->getAttributes());
        $this->assertDatabaseCount('gp_group_status_history', 0);
        $this->noSideEffects();
    }

    public function test_overdue_active_is_rejected_until_scheduler_expires_it(): void
    {
        $group = $this->group(['expires_at' => now()]);
        $this->actingAs($this->owner)->get('/groups/'.$group->id.'/extension')->assertSee('Дождитесь обновления статуса')->assertDontSee('extend-group-form');
        $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertSessionHasErrors('extension');
        $this->assertSame(GroupStatus::Active, $group->fresh()->status);
        $this->assertEquals(now(), $group->fresh()->expires_at);
        $this->artisan('groups:expire')->assertSuccessful();
        $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(GroupStatus::Approved, $group->fresh()->status);
        $this->assertSame(2, $group->statusHistory()->count());
        $this->noSideEffects();
    }

    public function test_missing_expiry_or_duration_and_timestamp_overflow_are_validation_errors(): void
    {
        foreach ([['expires_at' => null], ['placement_days' => null], ['placement_days' => 0], ['expires_at' => '2038-01-18 00:00:00']] as $attributes) {
            $group = $this->group($attributes);
            $this->actingAs($this->owner)->get('/groups/'.$group->id.'/extension')->assertOk()->assertDontSee('extend-group-form');
            $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertSessionHasErrors('extension');
        }
        $this->assertDatabaseCount('gp_group_status_history', 0);
        $this->noSideEffects();
    }

    public function test_idor_roles_disabled_deleted_and_ineligible_statuses(): void
    {
        $group = $this->group();
        $other = User::create(['email' => 'other@example.test', 'status' => 'approved']);
        foreach ([$other, $this->admin] as $actor) {
            $this->actingAs($actor);
            $this->get('/groups/'.$group->id.'/extension')->assertStatus($actor->admin ? 403 : 404);
            $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertStatus($actor->admin ? 403 : 404);
        }
        $this->actingAs($this->owner);
        foreach (['draft', 'moderation', 'revision', 'rejected', 'approved', 'awaiting_payment'] as $status) {
            $group->update(['status' => $status]);
            $this->get('/groups/'.$group->id.'/extension')->assertForbidden();
            $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertForbidden();
        }
        $group->update(['status' => 'active', 'disabled' => true]);
        $this->get('/groups/'.$group->id.'/extension')->assertForbidden();
        $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertForbidden();
        $group->delete();
        $this->get('/groups/'.$group->id.'/extension')->assertNotFound();
        $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertNotFound();
    }

    public static function revoked(): array
    {
        return [['disabled'], ['pending'], ['rejected'], ['deleted']];
    }

    #[DataProvider('revoked')]
    public function test_revoked_account_loses_extension_access(string $state): void
    {
        $group = $this->group();
        $this->actingAs($this->owner);
        if ($state === 'deleted') {
            $this->owner->delete();
        } else {
            $this->owner->update($state === 'disabled' ? ['disabled' => true] : ['status' => $state]);
        }
        $this->post('/groups/'.$group->id.'/extension', ['confirmed' => 1])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(GroupStatus::Active, $group->fresh()->status);
    }

    public function test_expired_filter_pagination_sort_and_constant_list_queries(): void
    {
        $counts = [];
        $this->group(['title' => 'Active excluded']);
        foreach ([1, 24] as $size) {
            for ($i = ($size === 1 ? 0 : 1); $i < $size; $i++) {
                $this->group(['status' => 'expired', 'expires_at' => now()->subDays($i), 'title' => 'Expired '.$i]);
            }
            foreach (['owner' => '/', 'admin' => '/admin/groups?quick=expired&sort=expires_at'] as $role => $path) {
                $this->actingAs($this->$role);
                DB::enableQueryLog();
                DB::flushQueryLog();
                $response = $this->get($path)->assertOk();
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                foreach ($queries as $query) {
                    $this->assertStringNotContainsString('gp_payments', $query['query']);
                    if ($role === 'admin') {
                        $this->assertStringNotContainsString('gp_group_applications', $query['query']);
                    }
                }
                $counts[$role][$size] = count($queries);
                if ($role === 'admin') {
                    $response->assertSee('Снять с публикации')->assertSee('Снимите группу с публикации')->assertDontSee('Active excluded')
                        ->assertViewHas('groups', fn ($groups) => $groups->every(fn ($group) => $group['status'] === 'expired'));
                }
            }
        }
        foreach ($counts as $sizes) {
            $this->assertSame($sizes[1], $sizes[24]);
        }
        $this->get('/admin/groups?quick=expired&sort=expires_at')->assertSee('quick=expired&amp;sort=expires_at&amp;page=2', false)
            ->assertViewHas('groups', fn ($groups) => $groups->first()['title'] === 'Expired 0');
        $this->get('/admin/groups?quick=expired&sort=expires_at&page=2')->assertViewHas('groups', fn ($groups) => $groups->count() === 4);
    }
}
