<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Exceptions\ModxGroupSyncException;
use App\Jobs\SetGroupModxPublication;
use App\Jobs\SyncGroupToModx;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Payments\PaymentAttempts;
use App\Services\GroupLifecycleService;
use App\Services\GroupModxPublicationScheduler;
use App\Services\GroupStatusTransitionService;
use App\Services\GroupWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WebpayFixture;
use Tests\TestCase;

class GroupPublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->startOfSecond());
        URL::forceRootUrl('http://localhost');
        config(['services.modx.base_url' => 'https://modx.example.test/api/v1', 'services.modx.token' => 'synthetic-private-token',
            'services.modx.connect_timeout' => 5, 'services.modx.sync_timeout' => 60]);
        $this->fakeHttp();
        foreach (['expiry_warning_days' => 3, 'expired_extension_window_days' => 30, 'placement_duration_days' => 30] as $key => $value) {
            Setting::create(['key' => $key, 'type' => 'integer', 'value' => (string) $value]);
        }
        $this->owner = User::create(['email' => 'publication-owner@example.test', 'status' => 'approved', 'free' => true]);
        $this->admin = User::create(['email' => 'publication-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $this->group = Group::create(['owner_id' => $this->owner->id, 'status' => 'active', 'title' => 'Synthetic publication',
            'public_site_resource_id' => 123, 'published_at' => now()->subDays(10), 'expires_at' => now()->addDays(20), 'placement_days' => 30]);
    }

    private function fakeHttp(?callable $callback = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback ?? fn ($request) => Http::response(['data' => ['resource_id' => 123, 'published' => $request['published'], 'changed' => false], 'meta' => []]));
    }

    private function runJob(?int $revision = null): SetGroupModxPublication
    {
        $job = new SetGroupModxPublication($this->group->id, $revision ?? $this->group->fresh()->modx_publication_revision, 123);
        app()->call([$job, 'handle']);

        return $job;
    }

    private function pause(): void
    {
        app(GroupWorkflow::class)->pause($this->group, $this->owner);
    }

    private function resume(): void
    {
        app(GroupWorkflow::class)->resume($this->group, $this->owner);
    }

    public function test_pause_resume_preserves_original_deadline_and_warning_across_repeated_cycles(): void
    {
        Queue::fake();
        $this->group->update(['expiry_warning_sent_at' => now()->subHour()]);
        $warning = $this->group->fresh()->getRawOriginal('expiry_warning_sent_at');
        $originalExpiry = $this->group->fresh()->getRawOriginal('expires_at');
        $date = $this->group->expires_at->copy()->timezone('Europe/Minsk')->format('d.m.Y H:i');
        for ($cycle = 0; $cycle < 2; $cycle++) {
            $expiry = $this->group->fresh()->expires_at->timestamp;
            $this->pause();
            $paused = $this->group->fresh();
            $this->assertSame(GroupStatus::Paused, $paused->status);
            $this->assertFalse($paused->accept);
            $this->assertSame($originalExpiry, $paused->getRawOriginal('expires_at'));
            $this->actingAs($this->owner)->get('/groups/'.$paused->id)->assertOk()->assertSee($date)
                ->assertSee('Срок размещения продолжает идти')->assertDontSee('Дата окончания будет сдвинута');
            $this->assertSame('user', $paused->statusHistory()->latest('id')->first()->actor_type);
            $this->assertSame($this->owner->id, $paused->statusHistory()->latest('id')->first()->actor_id);
            $this->assertSame($cycle * 2 + 1, $paused->modx_publication_revision);
            Http::assertSentCount($cycle * 2);
            $this->runJob();
            $this->travel(2)->days();
            $this->travel(17)->seconds();
            $this->assertFalse(app(GroupLifecycleService::class)->expireOne($paused->id));
            $this->resume();
            $this->resume(); // Pending requests do not allocate another revision.
            $this->assertSame($cycle * 2 + 2, $paused->fresh()->modx_publication_revision);
            $this->assertSame(GroupStatus::Paused, $paused->fresh()->status);
            $this->runJob();
            $this->runJob();
            $active = $paused->fresh();
            $this->assertSame(GroupStatus::Active, $active->status);
            $this->assertSame($expiry, $active->expires_at->timestamp);
            $this->assertNull($active->paused_at);
            $this->assertSame($warning, $active->getRawOriginal('expiry_warning_sent_at'));
            $this->assertSame($originalExpiry, $active->getRawOriginal('expires_at'));
            $this->get('/groups/'.$active->id)->assertOk()->assertSee($date);
            $this->assertSame('system', $active->statusHistory()->latest('id')->first()->actor_type);
            $this->assertSame('published', $active->modx_publication_status);
        }
        Queue::assertPushed(SetGroupModxPublication::class, 4);
        Http::assertSentCount(4);
        $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->all();
        $this->assertCount(4, array_unique($keys));
        Http::assertSent(fn ($r) => $r->url() === 'https://modx.example.test/api/v1/cabinet/resources/publication'
            && $r->body() === '{"resource_id":123,"published":false}'
            && $r->header('Idempotency-Key') === ['group-publication:'.$this->group->public_uuid.':123:1']);
    }

    public static function resumeDeadlineRaces(): array
    {
        return ['before HTTP' => [false, false], 'during HTTP' => [true, false], 'scheduler during HTTP' => [true, true]];
    }

    #[DataProvider('resumeDeadlineRaces')]
    public function test_expiry_wins_over_queued_or_inflight_resume(bool $duringHttp, bool $scheduler): void
    {
        Queue::fake();
        $this->group->update(['expires_at' => now()->addSecond()]);
        $expiry = $this->group->fresh()->getRawOriginal('expires_at');
        $this->pause();
        $this->resume();
        Queue::fake();
        if ($duringHttp) {
            $this->fakeHttp(function () use ($scheduler) {
                $this->travel(1)->seconds();
                if ($scheduler) {
                    $this->assertTrue(app(GroupLifecycleService::class)->expireOne($this->group->id));
                }

                return Http::response(['data' => ['resource_id' => 123, 'published' => true, 'changed' => true], 'meta' => []]);
            });
        } else {
            $this->travel(1)->seconds();
        }
        DB::beginTransaction();
        $this->runJob(2);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SetGroupModxPublication::class, fn ($job) => $job->revision === 3 && $job->expectedResourceId === 123);
        $group = $this->group->fresh();
        $this->assertSame(GroupStatus::Expired, $group->status);
        $this->assertNull($group->paused_at);
        $this->assertSame($expiry, $group->getRawOriginal('expires_at'));
        $this->assertSame('unpublished', $group->modx_publication_desired);
        $this->assertSame('pending', $group->modx_publication_status);
        $history = $group->statusHistory()->latest('id')->first();
        $this->assertSame(GroupStatus::Paused, $history->from_status);
        $this->assertSame(GroupStatus::Expired, $history->to_status);
        $this->assertSame('system', $history->actor_type);
        $this->assertNull($history->actor_id);
        $this->runJob(2);
        Http::assertSentCount($duringHttp ? 1 : 0);
        $this->fakeHttp();
        $this->runJob(3);
        Http::assertSent(fn ($request) => $request['published'] === false);
        $this->runJob(2);
        $this->assertSame('unpublished', $group->fresh()->modx_publication_status);
        $this->assertSame(GroupStatus::Expired, $group->fresh()->status);
        $this->assertSame(2, $group->statusHistory()->count());
    }

    public function test_web_actions_owner_only_confirmed_and_truthful_pending_ui(): void
    {
        Queue::fake();
        $other = User::create(['email' => 'other-publication@example.test', 'status' => 'approved']);
        foreach (['pause', 'resume'] as $action) {
            $this->actingAs($other)->post('/groups/'.$this->group->id.'/'.$action, ['confirmed' => 1])->assertNotFound();
            $this->actingAs($this->admin)->post('/groups/'.$this->group->id.'/'.$action, ['confirmed' => 1])->assertForbidden();
        }
        $this->actingAs($this->owner)->get('/groups/'.$this->group->id)->assertOk()->assertSee('Поставить на паузу');
        $this->post('/groups/'.$this->group->id.'/pause')->assertSessionHasErrors('confirmed');
        $this->post('/groups/'.$this->group->id.'/pause', ['confirmed' => 1])->assertRedirect();
        $this->get('/groups/'.$this->group->id)->assertOk()->assertSee('На паузе')->assertSee('Возобновить публикацию')->assertDontSee('Продлить размещение');
        $this->post('/groups/'.$this->group->id.'/resume', ['confirmed' => 1])->assertRedirect();
        $this->get('/groups/'.$this->group->id)->assertOk()->assertSee('Возобновление выполняется')->assertSee('До подтверждения группа остаётся на паузе');
        $this->assertSame(GroupStatus::Paused, $this->group->fresh()->status);
        $this->actingAs($this->admin)->get('/admin/groups/'.$this->group->id)->assertOk()->assertSee('Состояние публикации')->assertSee('В очереди');
        Http::assertNothingSent();
    }

    public static function ineligiblePause(): array
    {
        return array_merge(array_map(fn ($status) => [['status' => $status]], ['approved', 'moderation', 'draft', 'revision', 'rejected', 'expired', 'awaiting_payment', 'paused']),
            [[['disabled' => true]], [['expires_at' => null]], [['expires_at' => '2000-01-01 00:00:00']]]);
    }

    #[DataProvider('ineligiblePause')]
    public function test_pause_rejects_ineligible_group(array $changes): void
    {
        $this->group->update($changes);
        $this->actingAs($this->owner)->post('/groups/'.$this->group->id.'/pause', ['confirmed' => 1])->assertForbidden();
        $this->assertSame(0, $this->group->fresh()->modx_publication_revision);
        Http::assertNothingSent();
    }

    public function test_remote_identity_and_resume_preconditions(): void
    {
        $local = Group::create(['owner_id' => $this->owner->id, 'status' => 'active', 'expires_at' => now()->addDay()]);
        $this->assertFalse($this->owner->can('pause', $local));
        $this->assertFalse($this->owner->can('resume', $this->group));
        $this->pause();
        foreach ([['disabled' => true], ['disabled' => false, 'paused_at' => null], ['paused_at' => now(), 'expires_at' => null], ['expires_at' => now()], ['expires_at' => now()->subSecond()]] as $changes) {
            $this->group->refresh()->update($changes);
            $this->actingAs($this->owner)->post('/groups/'.$this->group->id.'/resume', ['confirmed' => 1])->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_after_commit_rollback_and_transition_failure_queue_nothing(): void
    {
        Queue::fake();
        DB::beginTransaction();
        $this->pause();
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertSame(GroupStatus::Active, $this->group->fresh()->status);
        DB::beginTransaction();
        $this->pause();
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SetGroupModxPublication::class, fn ($j) => $j->revision === 1 && $j->expectedResourceId === 123 && $j->connection === 'database');
        $this->resume();
        $expiry = $this->group->fresh()->expires_at->timestamp;
        $mock = \Mockery::mock(GroupStatusTransitionService::class);
        $mock->shouldReceive('transition')->once()->andThrow(new \RuntimeException('private SQL text'));
        $this->app->instance(GroupStatusTransitionService::class, $mock);
        $this->travel(91)->seconds();
        try {
            $this->runJob();
            $this->fail('Expected safe retry');
        } catch (ModxGroupSyncException $e) {
            $this->assertSame('local_failure', $e->safeCode);
        }
        $this->assertSame($expiry, $this->group->fresh()->expires_at->timestamp);
        $this->assertNotNull($this->group->fresh()->paused_at);
        $this->app->instance(GroupStatusTransitionService::class, new GroupStatusTransitionService);
        $this->runJob();
        $this->runJob();
        $this->assertSame($expiry, $this->group->fresh()->expires_at->timestamp);
    }

    public function test_stale_unpublish_skips_and_delete_wins_over_inflight_resume(): void
    {
        $this->pause();
        $this->resume();
        $this->runJob(1);
        Http::assertNothingSent();
        $expiry = $this->group->fresh()->expires_at->timestamp;
        $this->fakeHttp(function ($request) {
            $this->assertSame(1, DB::transactionLevel()); // Only the test's outer transaction.
            app(GroupWorkflow::class)->delete($this->group, $this->owner);

            return Http::response(['data' => ['resource_id' => 123, 'published' => true, 'changed' => true]]);
        });
        $this->runJob(2);
        $group = $this->group->fresh();
        $this->assertSame(3, $group->modx_publication_revision);
        $this->assertSame('unpublished', $group->modx_publication_desired);
        $this->assertSame('pending', $group->modx_publication_status);
        $this->assertSame(GroupStatus::Paused, $group->status);
        $this->assertSame($expiry, $group->expires_at->timestamp);
        $this->assertNotNull($group->psychologist_deleted_at);
        $this->fakeHttp();
        $this->runJob();
        $this->assertSame('unpublished', $group->fresh()->modx_publication_status);
        $this->actingAs($this->admin)->get('/admin/groups/'.$group->id)->assertOk();
    }

    public function test_admin_deleted_rows_and_expiration_unpublish_without_remote_deletion(): void
    {
        Queue::fake();
        $this->group->update(['expires_at' => now()]);
        $this->assertTrue(app(GroupLifecycleService::class)->expireOne($this->group->id));
        $this->assertFalse(app(GroupLifecycleService::class)->expireOne($this->group->id));
        $this->assertSame('unpublished', $this->group->fresh()->modx_publication_desired);
        $this->fakeHttp(fn () => Http::response([], 422));
        $this->runJob();
        $this->assertSame(GroupStatus::Expired, $this->group->fresh()->status);
        $this->assertSame('failed', $this->group->fresh()->modx_publication_status);
        app(GroupWorkflow::class)->delete($this->group, $this->admin);
        $this->fakeHttp();
        $this->runJob();
        $this->assertSoftDeleted($this->group);
        $this->assertSame('unpublished', $this->group->fresh()->modx_publication_status);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['published'] === false);
        Queue::assertPushed(SetGroupModxPublication::class, 2);
        $local = Group::create(['owner_id' => $this->owner->id, 'status' => 'active', 'expires_at' => now()]);
        app(GroupLifecycleService::class)->expireOne($local->id);
        app(GroupWorkflow::class)->delete($local, $this->owner);
        Queue::assertPushed(SetGroupModxPublication::class, 2);
    }

    public function test_retry_key_body_exhaustion_and_shared_worker_lock(): void
    {
        config(['cache.default' => 'database']);
        $this->pause();
        $this->resume();
        DB::table('jobs')->delete(); // Discard stale unpublish for this focused worker test.
        SetGroupModxPublication::dispatch($this->group->id, 2, 123)->onConnection('database');
        $job = new SetGroupModxPublication($this->group->id, 2, 123);
        $content = new SyncGroupToModx($this->group->id, 1);
        $key = $job->middleware()[0]->getLockKey($job);
        $this->assertSame($key, $content->middleware()[0]->getLockKey($content));
        $lock = Cache::lock($key, 120);
        $this->assertTrue($lock->get());
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        Http::assertNothingSent();
        $lock->release();
        $this->fakeHttp(function () use ($key) {
            $this->assertFalse(Cache::lock($key, 120)->get());

            return Http::response(['private' => 'never store response'], 503);
        });
        for ($i = 0; $i < 4; $i++) {
            DB::table('jobs')->update(['available_at' => now()->timestamp]);
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        }
        $group = $this->group->fresh();
        $this->assertSame('failed', $group->modx_publication_status);
        $this->assertSame(GroupStatus::Paused, $group->status);
        $this->assertNotNull($group->paused_at);
        $bodies = Http::recorded()->map(fn ($p) => $p[0]->body())->all();
        $keys = Http::recorded()->map(fn ($p) => $p[0]->header('Idempotency-Key')[0])->all();
        $this->assertCount(1, array_unique($bodies));
        $this->assertCount(1, array_unique($keys));
        $failed = DB::table('failed_jobs')->sole();
        foreach (['synthetic-private-token', 'never store response', 'content_base64'] as $secret) {
            $this->assertStringNotContainsString($secret, $failed->exception.$failed->payload);
        }
        $this->resume();
        $this->fakeHttp();
        $this->runJob();
        $this->assertSame(GroupStatus::Active, $group->fresh()->status);
    }

    public function test_conflict_is_not_hidden_by_rotating_revision_and_queue_outage_keeps_pause(): void
    {
        $this->pause();
        $this->resume();
        $this->fakeHttp(fn () => Http::response(['message' => 'Idempotency-Key was reused with a different request body'], 409));
        $this->runJob();
        $this->actingAs($this->owner)->post('/groups/'.$this->group->id.'/resume', ['confirmed' => 1])->assertSessionHasErrors('publication');
        $this->assertSame(2, $this->group->fresh()->modx_publication_revision);
        $this->assertSame('conflict', $this->group->fresh()->modx_publication_status);
        $this->group->refresh()->update(['status' => 'active']);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('private queue failure'));
        $this->pause();
        $this->assertSame(GroupStatus::Paused, $this->group->fresh()->status);
        $this->assertSame('queue_unavailable', $this->group->fresh()->modx_publication_error_code);
    }

    public function test_delete_and_expiry_rollback_leave_no_publication_request(): void
    {
        Queue::fake();
        foreach (['delete', 'expire'] as $action) {
            $this->group->refresh()->update(['expires_at' => now()]);
            DB::beginTransaction();
            if ($action === 'delete') {
                app(GroupWorkflow::class)->delete($this->group, $this->admin);
            } else {
                app(GroupLifecycleService::class)->expireOne($this->group->id);
            }
            Queue::assertNothingPushed();
            DB::rollBack();
            Queue::assertNothingPushed();
            $this->assertSame(0, $this->group->fresh()->modx_publication_revision);
            $this->assertFalse($this->group->fresh()->trashed());
            $this->assertSame(GroupStatus::Active, $this->group->fresh()->status);
        }
        $mock = \Mockery::mock(GroupStatusTransitionService::class);
        $mock->shouldReceive('transition')->once()->andThrow(new \RuntimeException('Synthetic history failure'));
        $this->app->instance(GroupStatusTransitionService::class, $mock);
        try {
            app(GroupLifecycleService::class)->expireOne($this->group->id);
            $this->fail('Expected transition failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic history failure', $e->getMessage());
        }
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->group->fresh()->modx_publication_revision);
    }

    public function test_stale_publish_after_admin_delete_and_wrong_identity_do_not_reactivate(): void
    {
        $this->pause();
        $this->resume();
        $wrong = new SetGroupModxPublication($this->group->id, 2, 999);
        app()->call([$wrong, 'handle']);
        Http::assertNothingSent();
        $this->fakeHttp(function () {
            app(GroupWorkflow::class)->delete($this->group, $this->admin);

            return Http::response(['data' => ['resource_id' => 123, 'published' => true, 'changed' => false]]);
        });
        $this->runJob(2);
        $this->assertSoftDeleted($this->group);
        $this->assertSame(GroupStatus::Paused, $this->group->fresh()->status);
        $this->assertSame('pending', $this->group->fresh()->modx_publication_status);
        (new SetGroupModxPublication($this->group->id, 2, 123))->failed(new ModxGroupSyncException('connection'));
        $this->assertNull($this->group->fresh()->modx_publication_error_code);
    }

    private function currentIntent(): SetGroupModxPublication
    {
        $group = $this->group->fresh();

        return Queue::pushed(SetGroupModxPublication::class)->last(fn ($job) => $job->revision === $group->modx_publication_revision);
    }

    public function test_admin_withdraw_restore_preserves_dates_and_blocks_owner_until_current_remote_success(): void
    {
        Queue::fake();
        $dates = $this->group->only(['published_at', 'expires_at', 'placement_days']);
        $url = '/admin/groups/'.$this->group->id;
        $this->actingAs($this->owner)->post($url.'/withdraw', ['confirmed' => 1])->assertForbidden();
        $this->actingAs($this->admin)->post($url.'/withdraw')->assertSessionHasErrors('confirmed');
        DB::beginTransaction();
        $this->post($url.'/withdraw', ['confirmed' => 1])->assertRedirect();
        Queue::assertNothingPushed();
        DB::commit();
        $withdraw = $this->currentIntent();
        $this->assertTrue($this->group->fresh()->disabled);
        $this->assertSame(GroupStatus::Active, $this->group->fresh()->status);
        $this->assertEquals($dates, $this->group->fresh()->only(array_keys($dates)));
        $this->actingAs($this->owner)->post('/groups/'.$this->group->id.'/pause', ['confirmed' => 1])->assertForbidden();
        $this->post('/groups/'.$this->group->id.'/extension', ['confirmed' => 1])->assertForbidden();
        $this->postJson('/api/v1/group-applications', ['group_uuid' => $this->group->public_uuid, 'first_name' => 'Test', 'last_name' => 'Synthetic', 'phone' => '+12025550100'], ['X-Request-Id' => 'withdraw-intake'])->assertUnprocessable();
        app()->call([$withdraw, 'handle']);
        $this->actingAs($this->admin)->post($url.'/restore-placement', ['confirmed' => 1])->assertRedirect();
        $restore = $this->currentIntent();
        $this->assertSame('admin_restore', $restore->mode);
        $this->assertTrue($this->group->fresh()->disabled);
        app()->call([$restore, 'handle']);
        $this->assertFalse($this->group->fresh()->disabled);
        $this->assertSame(GroupStatus::Active, $this->group->fresh()->status);
        $this->assertEquals($dates, $this->group->fresh()->only(array_keys($dates)));
        app()->call([$restore, 'handle']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['resource_id'] === 123 && $request['published'] === true);
    }

    public function test_admin_restore_failure_stays_disabled_and_action_can_retry(): void
    {
        Queue::fake();
        app(GroupWorkflow::class)->withdraw($this->group, $this->admin);
        app(GroupWorkflow::class)->restorePlacement($this->group, $this->admin);
        $job = $this->currentIntent();
        $this->fakeHttp(fn () => Http::response([], 422));
        app()->call([$job, 'handle']);
        $this->assertTrue($this->group->fresh()->disabled);
        $this->fakeHttp();
        app(GroupWorkflow::class)->restorePlacement($this->group, $this->admin);
        app()->call([$this->currentIntent(), 'handle']);
        $this->assertFalse($this->group->fresh()->disabled);
    }

    public function test_admin_restore_deadline_before_and_during_http_expires_without_reenable(): void
    {
        Queue::fake();
        foreach ([false, true] as $during) {
            $this->group->refresh()->update(['status' => 'active', 'disabled' => false, 'expires_at' => now()->addMinute()]);
            app(GroupWorkflow::class)->withdraw($this->group, $this->admin);
            app(GroupWorkflow::class)->restorePlacement($this->group, $this->admin);
            $job = $this->currentIntent();
            if ($during) {
                $this->fakeHttp(function ($request) {
                    $this->travel(61)->seconds();

                    return Http::response(['data' => ['resource_id' => $request['resource_id'], 'published' => $request['published'], 'changed' => true], 'meta' => []]);
                });
            } else {
                $this->travel(61)->seconds();
            }
            app()->call([$job, 'handle']);
            $current = $this->group->fresh();
            $this->assertTrue($current->disabled);
            $this->assertSame(GroupStatus::Expired, $current->status);
            $this->assertSame('unpublished', $current->modx_publication_desired);
            $this->assertGreaterThan($job->revision, $current->modx_publication_revision);
        }
    }

    public function test_free_and_paid_expired_renewal_start_clock_only_after_remote_success(): void
    {
        Queue::fake();
        WebpayFixture::configure();
        Setting::create(['key' => 'extension_price_minor_units', 'type' => 'integer', 'value' => '5000']);
        foreach ([true, false] as $free) {
            $this->owner->update(['free' => $free]);
            $this->group->refresh()->update(['status' => 'expired', 'published_at' => now()->subDays(40), 'expires_at' => now()->subDays(10), 'expiry_warning_sent_at' => now()->subDays(11)]);
            $before = $this->group->fresh()->only(['published_at', 'expires_at']);
            DB::beginTransaction();
            if ($free) {
                app(GroupLifecycleService::class)->extend($this->group, $this->owner);
            } else {
                $payment = app(PaymentAttempts::class)->extend($this->group, $this->owner);
                app(PaymentAttempts::class)->start($payment);
                $notify = WebpayFixture::notify($payment);
                $this->post('/webpay/notify', $notify)->assertOk();
                $this->assertSame('extension-expired', $payment->fresh()->product_effect);
                $this->assertSame('succeeded', $payment->fresh()->status->value);
                $this->post('/webpay/notify', $notify)->assertOk();
            }
            $revision = $this->group->fresh()->modx_publication_revision;
            Queue::assertNotPushed(SetGroupModxPublication::class, fn ($job) => $job->revision === $revision);
            $this->assertSame(GroupStatus::Approved, $this->group->fresh()->status);
            $this->assertEquals($before, $this->group->fresh()->only(array_keys($before)));
            DB::commit();
            $job = $this->currentIntent();
            $this->assertSame('renewal', $job->mode);
            $this->travel(3)->hours();
            app()->call([$job, 'handle']);
            $group = $this->group->fresh();
            $this->assertSame(GroupStatus::Active, $group->status);
            $this->assertEquals(now()->utc(), $group->published_at);
            $this->assertEquals(now()->utc()->addDays(30), $group->expires_at);
            $this->assertNull($group->expiry_warning_sent_at);
            $this->assertSame('published', $group->modx_publication_status);
            $this->assertSame('system', $group->statusHistory()->latest('id')->first()->actor_type);
            app()->call([$job, 'handle']);
        }
        Http::assertSentCount(2);
    }

    public function test_renewal_failure_missing_resource_and_initial_approval_remain_truthful(): void
    {
        Queue::fake();
        $this->group->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        $before = $this->group->fresh()->only(['published_at', 'expires_at']);
        app(GroupLifecycleService::class)->extend($this->group, $this->owner);
        $job = $this->currentIntent();
        $this->fakeHttp(fn () => Http::response([], 422));
        app()->call([$job, 'handle']);
        $this->assertSame(GroupStatus::Approved, $this->group->fresh()->status);
        $this->assertEquals($before, $this->group->fresh()->only(array_keys($before)));
        $this->actingAs($this->admin)->post('/admin/groups/'.$this->group->id.'/activate', ['confirmed' => 1])->assertForbidden();
        $this->fakeHttp();
        app(GroupWorkflow::class)->retryRenewal($this->group, $this->admin);
        app()->call([$this->currentIntent(), 'handle']);
        $this->assertSame(GroupStatus::Active, $this->group->fresh()->status);
        $missing = Group::create(['owner_id' => $this->owner->id, 'status' => 'expired', 'expires_at' => now()->subDay()]);
        app(GroupLifecycleService::class)->extend($missing, $this->owner);
        Queue::assertNotPushed(SetGroupModxPublication::class, fn ($queued) => $queued->groupId === $missing->id);
        $this->assertSame(GroupStatus::Approved, $missing->fresh()->status);
        $this->assertNull($missing->fresh()->published_at);
        $this->group->update(['status' => 'approved']);
        $this->group->statusHistory()->create(['from_status' => 'moderation', 'to_status' => 'approved', 'actor_type' => 'system']);
        DB::transaction(fn () => app(GroupModxPublicationScheduler::class)->schedule($this->group->fresh(), true));
        $this->fakeHttp();
        app()->call([$this->currentIntent(), 'handle']);
        Http::assertNothingSent();
        $this->assertSame(GroupStatus::Approved, $this->group->fresh()->status);
    }

    public static function renewalResourceIds(): array
    {
        return ['missing resource' => [null], 'existing resource' => [123]];
    }

    #[DataProvider('renewalResourceIds')]
    public function test_renewal_manual_activation_is_forbidden_without_mutation(?int $resourceId): void
    {
        Queue::fake();
        if ($resourceId === null) {
            $this->group = Group::create(['owner_id' => $this->owner->id, 'status' => 'active',
                'published_at' => now()->subDays(40), 'expires_at' => now()->subDays(10), 'placement_days' => 30]);
        }
        $this->group->update(['status' => 'expired', 'expires_at' => now()->subDay(), 'public_site_resource_id' => $resourceId]);
        app(GroupLifecycleService::class)->extend($this->group, $this->owner);
        $renewed = $this->group->fresh();
        $before = $renewed->getAttributes();
        $history = $renewed->statusHistory()->get()->toArray();
        $this->assertSame(GroupStatus::Approved, $renewed->status);
        $this->assertFalse($this->admin->can('activate', $renewed));
        $this->actingAs($this->admin)->post('/admin/groups/'.$renewed->id.'/activate', ['confirmed' => 1])->assertForbidden();
        $this->assertManualActivationRejected($renewed);
        $this->assertSame($before, $renewed->fresh()->getAttributes());
        $this->assertSame($history, $renewed->statusHistory()->get()->toArray());
        $this->get('/admin/groups/'.$renewed->id)->assertOk()->assertDontSee('Отметить активной')
            ->assertDontSee('id="activate"', false)
            ->assertSee($resourceId === null ? 'ID ресурса отсутствует' : 'Новый срок начнётся после подтверждения публикации.');
        Http::assertNothingSent();

        if ($resourceId === null) {
            $renewed->update(['public_site_resource_id' => 124]);
            $this->fakeHttp(fn ($request) => Http::response(['data' => ['resource_id' => 124, 'published' => $request['published'], 'changed' => true], 'meta' => []]));
            app(GroupWorkflow::class)->retryRenewal($renewed, $this->admin);
        }
        $this->assertSame(GroupStatus::Approved, $renewed->fresh()->status);
        $this->assertEquals($before['expires_at'], $renewed->fresh()->getRawOriginal('expires_at'));
        app()->call([$this->currentIntent(), 'handle']);
        $this->assertSame(GroupStatus::Active, $renewed->fresh()->status);
        $this->assertEquals(now()->utc(), $renewed->fresh()->published_at);
        $this->assertEquals(now()->utc()->addDays(30), $renewed->fresh()->expires_at);
        Http::assertSentCount(1);
    }

    #[DataProvider('renewalResourceIds')]
    public function test_initial_approval_still_allows_manual_activation(?int $resourceId): void
    {
        Queue::fake();
        if ($resourceId === null) {
            $this->group = Group::create(['owner_id' => $this->owner->id, 'status' => 'active',
                'published_at' => now()->subDays(40), 'expires_at' => now()->subDays(10), 'placement_days' => 30]);
        }
        $this->group->update(['status' => 'moderation', 'public_site_resource_id' => $resourceId, 'published_at' => null, 'expires_at' => null]);
        app(GroupStatusTransitionService::class)->transition($this->group, GroupStatus::Approved, $this->admin, 'user');
        $approved = $this->group->fresh();
        $this->assertNull($approved->renewalHistoryId());
        $this->assertTrue($this->admin->can('activate', $approved));
        $this->actingAs($this->admin)->get('/admin/groups/'.$approved->id)->assertOk()->assertSee('Отметить активной');
        $this->post('/admin/groups/'.$approved->id.'/activate', ['confirmed' => 1])->assertRedirect();
        $active = $approved->fresh();
        $this->assertSame(GroupStatus::Active, $active->status);
        $this->assertEquals(now()->utc(), $active->published_at);
        $this->assertEquals(now()->utc()->addDays(30), $active->expires_at);
        $this->assertSame(30, $active->placement_days);
        $this->assertSame('published', $active->modx_publication_status);
        Queue::assertNotPushed(SetGroupModxPublication::class);
        Http::assertNothingSent();
    }

    #[DataProvider('renewalResourceIds')]
    public function test_locked_manual_activation_rejects_stale_initial_approval(?int $resourceId): void
    {
        Queue::fake();
        if ($resourceId === null) {
            $this->group = Group::create(['owner_id' => $this->owner->id, 'status' => 'active',
                'published_at' => now()->subDays(40), 'expires_at' => now()->subDays(10), 'placement_days' => 30]);
        }
        $this->group->update(['status' => 'approved', 'public_site_resource_id' => $resourceId, 'expires_at' => now()->subDay()]);
        $this->group->statusHistory()->create(['from_status' => 'moderation', 'to_status' => 'approved', 'actor_type' => 'system']);
        $stale = $this->group->fresh()->load('statusHistory');
        $this->assertTrue($this->admin->can('activate', $stale));

        // Deterministic interleaving after authorization, before the workflow locks the row.
        app(GroupStatusTransitionService::class)->transition($this->group, GroupStatus::Active, $this->admin, 'user');
        app(GroupStatusTransitionService::class)->transition($this->group->fresh(), GroupStatus::Expired);
        app(GroupLifecycleService::class)->extend($this->group, $this->owner);
        $before = $this->group->fresh()->getAttributes();
        $history = $this->group->statusHistory()->get()->toArray();
        $this->assertManualActivationRejected($stale);
        $this->assertSame($before, $this->group->fresh()->getAttributes());
        $this->assertSame($history, $this->group->statusHistory()->get()->toArray());
        $this->assertSame(GroupStatus::Approved, $this->group->fresh()->status);
        Http::assertNothingSent();
    }

    private function assertManualActivationRejected(Group $group): void
    {
        try {
            app(GroupWorkflow::class)->activate($group, $this->admin);
            $this->fail('Renewal manual activation must be rejected under the row lock.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This action is unauthorized.', $exception->getMessage());
        }
    }

    public function test_renewal_stale_delete_and_date_races_cannot_activate(): void
    {
        Queue::fake();
        foreach (['owner-delete', 'admin-delete', 'date', 'unpublish', 'disabled'] as $index => $mutation) {
            $this->group = Group::create(['owner_id' => $this->owner->id, 'status' => 'expired', 'public_site_resource_id' => 124 + $index,
                'published_at' => now()->subDays(40), 'expires_at' => now()->subDays(10), 'placement_days' => 30]);
            app(GroupLifecycleService::class)->extend($this->group, $this->owner);
            $job = $this->currentIntent();
            $this->fakeHttp(function ($request) use ($mutation) {
                match ($mutation) {
                    'owner-delete' => app(GroupWorkflow::class)->delete($this->group, $this->owner),
                    'admin-delete' => app(GroupWorkflow::class)->delete($this->group, $this->admin),
                    'date' => $this->group->fresh()->update(['expires_at' => now()->addDay()]),
                    'disabled' => $this->group->fresh()->update(['disabled' => true]),
                    default => DB::transaction(fn () => app(GroupModxPublicationScheduler::class)->schedule($this->group->fresh(), false)),
                };

                return Http::response(['data' => ['resource_id' => $request['resource_id'], 'published' => $request['published'], 'changed' => true], 'meta' => []]);
            });
            app()->call([$job, 'handle']);
            $group = Group::withTrashed()->findOrFail($this->group->id);
            $this->assertNotSame(GroupStatus::Active, $group->status);
            $this->assertSame('unpublished', $group->modx_publication_desired);
            $this->assertGreaterThan($job->revision, $group->modx_publication_revision);
        }
    }

    public function test_rejected_owner_delete_retains_history_payments_and_admin_marker(): void
    {
        Queue::fake();
        $this->group->update(['status' => 'rejected']);
        $history = $this->group->statusHistory()->create(['from_status' => 'moderation', 'to_status' => 'rejected', 'actor_type' => 'system']);
        $payment = $this->group->payments()->create(['owner_id' => $this->owner->id, 'type' => 'placement', 'status' => 'succeeded', 'order_number' => 'synthetic-retained', 'amount' => 100]);
        app(GroupWorkflow::class)->delete($this->group, $this->owner);
        $group = $this->group->fresh();
        $this->assertSame(GroupStatus::Rejected, $group->status);
        $this->assertNotNull($group->psychologist_deleted_at);
        $this->assertNull($group->deleted_at);
        $this->assertNotNull($history->fresh());
        $this->assertNotNull($payment->fresh());
        $this->assertSame('unpublished', $group->modx_publication_desired);
        $this->actingAs($this->owner)->get('/groups/'.$group->id)->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/groups/'.$group->id)->assertOk()->assertSee('Психолог удалил группу');
    }

    public function test_legacy_queued_pause_job_payload_keeps_resume_defaults(): void
    {
        Queue::fake();
        $this->pause();
        $this->resume();
        $job = $this->currentIntent();
        unset($job->mode, $job->expectedIdentity);
        $restored = unserialize(serialize($job));
        $this->assertSame('resume', $restored->mode);
        $this->assertNull($restored->expectedIdentity);
        app()->call([$restored, 'handle']);
        $this->assertSame(GroupStatus::Active, $this->group->fresh()->status);
    }

    public function test_stale_restore_response_cannot_reenable_newer_unpublish_intent(): void
    {
        Queue::fake();
        app(GroupWorkflow::class)->withdraw($this->group, $this->admin);
        app(GroupWorkflow::class)->restorePlacement($this->group, $this->admin);
        $job = $this->currentIntent();
        $this->fakeHttp(function ($request) {
            DB::transaction(fn () => app(GroupModxPublicationScheduler::class)->schedule($this->group->fresh(), false));

            return Http::response(['data' => ['resource_id' => 123, 'published' => $request['published'], 'changed' => true], 'meta' => []]);
        });
        app()->call([$job, 'handle']);
        $this->assertTrue($this->group->fresh()->disabled);
        $this->assertSame('unpublished', $this->group->fresh()->modx_publication_desired);
        $this->assertSame(GroupStatus::Active, $this->group->fresh()->status);
    }

    public function test_free_and_paid_renewal_queue_failure_does_not_undo_product_effect(): void
    {
        WebpayFixture::configure();
        Setting::create(['key' => 'extension_price_minor_units', 'type' => 'integer', 'value' => '5000']);
        Bus::shouldReceive('dispatch')->twice()->andThrow(new \RuntimeException('Synthetic queue outage'));
        foreach ([true, false] as $free) {
            $this->owner->update(['free' => $free]);
            $this->group->refresh()->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
            $before = $this->group->fresh()->only(['published_at', 'expires_at']);
            if ($free) {
                app(GroupLifecycleService::class)->extend($this->group, $this->owner);
            } else {
                $payment = app(PaymentAttempts::class)->extend($this->group, $this->owner);
                app(PaymentAttempts::class)->start($payment);
                $notify = WebpayFixture::notify($payment);
                $this->post('/webpay/notify', $notify)->assertOk();
                $this->assertSame('succeeded', $payment->fresh()->status->value);
                $this->assertSame('extension-expired', $payment->fresh()->product_effect);
                $this->post('/webpay/notify', $notify)->assertOk();
            }
            $this->assertSame(GroupStatus::Approved, $this->group->fresh()->status);
            $this->assertSame('queue_unavailable', $this->group->fresh()->modx_publication_error_code);
            $this->assertEquals($before, $this->group->fresh()->only(array_keys($before)));
        }
        Http::assertNothingSent();
    }

    public function test_renewal_mutation_before_http_is_rejected_without_remote_publish(): void
    {
        Queue::fake();
        $this->group->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        app(GroupLifecycleService::class)->extend($this->group, $this->owner);
        $job = $this->currentIntent();
        $this->group->fresh()->update(['published_at' => now()->subDays(100)]);
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
        $this->assertSame(GroupStatus::Approved, $this->group->fresh()->status);
    }
}
