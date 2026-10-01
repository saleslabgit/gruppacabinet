<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Exceptions\ModxGroupSyncException;
use App\Jobs\SetGroupModxPublication;
use App\Jobs\SyncGroupToModx;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Services\GroupLifecycleService;
use App\Services\GroupStatusTransitionService;
use App\Services\GroupWorkflow;
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
}
