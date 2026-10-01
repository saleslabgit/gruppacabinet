<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Exceptions\ModxGroupSyncException;
use App\Jobs\SyncGroupToModx;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Services\GroupModxPayloadBuilder;
use App\Services\GroupStatusTransitionService;
use App\Services\GroupWorkflow;
use App\Services\SettingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GroupContentFixture as Fixture;
use Tests\TestCase;

class ModxGroupSyncTest extends TestCase
{
    use RefreshDatabase;

    private Group $group;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        URL::forceRootUrl('http://localhost');
        config(['services.modx.base_url' => 'https://modx.example.test/api/v1', 'services.modx.token' => 'synthetic-private-token',
            'services.modx.connect_timeout' => 5, 'services.modx.sync_timeout' => 60]);
        $this->fakeHttp(['*' => Http::response($this->response())]);
        $this->owner = User::create(['email' => 'sync-owner@example.test', 'first_name' => '  First  Name ', 'last_name' => ' Last ',
            'middle_name' => 'OmittedMiddle', 'status' => 'approved', 'free' => true]);
        $this->admin = User::create(['email' => 'sync-admin@example.test', 'admin' => true, 'status' => 'approved']);
        $this->group = app(GroupWorkflow::class)->create($this->owner, $this->admin, Fixture::fields() + [
            'title' => 'Synthetic title', 'description' => 'Short description', 'meeting_price' => 12300,
            'meeting_duration_minutes' => 90, 'participant_capacity' => 8,
            'format_id' => Fixture::item('group_format')->id, 'gender_id' => Fixture::item('gender')->id,
        ]);
        $this->group->update(['status' => 'moderation']);
        Setting::create(['key' => 'placement_duration_days', 'type' => 'integer', 'value' => '30']);
    }

    private function fakeHttp(array|callable $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function response(int $id = 412, bool $created = true): array
    {
        return ['data' => ['resource_id' => $id, 'created' => $created, 'updated' => ! $created,
            'cover_path' => 'images/cabinet-group-'.$id.'-synthetic.png', 'cover_cleanup_warning' => false], 'meta' => []];
    }

    private function approve(): Group
    {
        return app(GroupWorkflow::class)->moderate($this->group, $this->admin, GroupStatus::Approved);
    }

    private function runJob(?int $revision = null): SyncGroupToModx
    {
        $job = new SyncGroupToModx($this->group->id, $revision ?? $this->group->fresh()->modx_sync_revision);
        app()->call([$job, 'handle']);

        return $job;
    }

    public function test_exact_mapping_alias_days_values_order_price_and_cover(): void
    {
        $this->group->update(['meeting_days' => ['sun', 'sat', 'fri', 'thu', 'wed', 'tue', 'mon']]);
        foreach (['format' => 'Онлайн', 'gender' => 'All', 'groupType' => '17'] as $relation => $value) {
            $this->group->$relation->update(['modx_value' => $value]);
        }
        foreach (['approaches' => 'group_approach', 'tags' => 'group_tag'] as $relation => $code) {
            $a = Fixture::item($code);
            $a->update(['modx_value' => '099', 'sort_order' => 5]);
            $b = Fixture::item($code);
            $b->update(['modx_value' => '2', 'sort_order' => 1]);
            $this->group->$relation()->sync([$a->id, $b->id]);
        }
        $payload = app(GroupModxPayloadBuilder::class)->build($this->group);
        $body = json_decode($payload['body'], true);
        $this->assertSame(['pagetitle' => 'Synthetic title', 'alias' => 'synthetic-title-g'.$this->group->id], $body['resource']);
        $this->assertSame([
            'groupid' => $this->group->public_uuid, 'title' => 'Synthetic title', 'shortDescription' => 'Short description',
            'desc' => '<p>Полное описание <strong>группы</strong>.</p>',
            'leader' => [['MIGX_id' => '1', 'text' => 'First Name Last']], 'days' => 'пн,вт,ср,чт,пт,сб,вс',
            'startAt' => '19:00', 'duration' => '90', 'frequency' => 'Еженедельно', 'format' => 'Онлайн', 'city' => 'Минск',
            'groupType' => '17', 'approaches' => '2||099', 'participantsCount' => '8', 'gender' => 'All', 'price' => '123', 'tags' => '2||099',
        ], $body['tvs']);
        $this->assertArrayNotHasKey('resource_id', $body);
        $this->assertSame('image/png', $body['cover']['mime_type']);
        $this->assertSame(Storage::disk('local')->get($this->group->cover_path), base64_decode($body['cover']['content_base64']));
        $this->assertSame($this->group->cover_path, $payload['cover_source']);
        $this->assertSame(hash('sha256', $payload['body']), $payload['hash']);
        $this->group->update(['title' => str_repeat('a', 255)]);
        $this->assertSame(191, strlen(json_decode(app(GroupModxPayloadBuilder::class)->build($this->group)['body'], true)['resource']['alias']));
        $this->group->update(['title' => '★']);
        $this->assertSame('group-g'.$this->group->id, json_decode(app(GroupModxPayloadBuilder::class)->build($this->group)['body'], true)['resource']['alias']);
    }

    public function test_commit_dispatch_rollback_and_failed_approval(): void
    {
        Queue::fake();
        DB::beginTransaction();
        $this->approve();
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertSame('moderation', $this->group->fresh()->status->value);
        $this->assertSame(0, $this->group->fresh()->modx_sync_revision);
        DB::beginTransaction();
        $this->approve();
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SyncGroupToModx::class, 1);
        Queue::assertPushed(SyncGroupToModx::class, fn ($job) => $job->revision === 1 && $job->groupId === $this->group->id && $job->connection === 'database');
        Http::assertNothingSent();
        $this->assertNull($this->group->fresh()->published_at);
    }

    public function test_create_persists_identity_and_cover_then_update_reuses_remote_image(): void
    {
        $this->approve();
        $this->runJob();
        $group = $this->group->fresh();
        $this->assertSame(412, $group->public_site_resource_id);
        $this->assertSame('synced', $group->modx_sync_status);
        $this->assertNotNull($group->modx_synced_at);
        $this->assertSame($group->cover_path, $group->modx_remote_cover_source_path);
        Http::assertSent(fn ($request) => $request->header('Idempotency-Key') === ['group-create:'.$group->public_uuid]
            && isset($request['cover']) && ! isset($request['tvs']['image']) && ! isset($request['resource']['published']));
        $this->runJob();
        Http::assertSentCount(1);
        app(GroupWorkflow::class)->save($group, $this->admin, ['title' => 'Updated title']);
        $this->fakeHttp(['*' => Http::response($this->response(412, false))]);
        $this->runJob();
        Http::assertSent(fn ($request) => $request['resource_id'] === 412 && ! isset($request['cover'])
            && ! isset($request['resource']['alias']) && $request['tvs']['image'] === $group->modx_remote_cover_path
            && $request->header('Idempotency-Key') === ['group-update:'.$group->public_uuid.':2']);
        $this->assertSame(412, $group->fresh()->public_site_resource_id);
        $this->assertSame($group->modx_remote_cover_source_path, $group->fresh()->modx_remote_cover_source_path);
    }

    public function test_replaced_cover_uploads_again_and_failed_upload_preserves_tracking(): void
    {
        $this->approve();
        $this->runJob();
        $old = $this->group->fresh()->modx_remote_cover_source_path;
        app(GroupWorkflow::class)->save($this->group, $this->admin, ['cover' => Fixture::cover('jpg')]);
        $this->fakeHttp(['*' => Http::response([], 422)]);
        $this->runJob();
        $this->assertSame($old, $this->group->fresh()->modx_remote_cover_source_path);
        $this->assertSame('failed', $this->group->fresh()->modx_sync_status);
        app(GroupWorkflow::class)->syncModx($this->group, $this->admin);
        $this->fakeHttp(['*' => Http::response($this->response(412, false))]);
        $this->runJob();
        Http::assertSent(fn ($request) => $request['cover']['mime_type'] === 'image/jpeg' && ! isset($request['tvs']['image']));
        $this->assertSame($this->group->fresh()->cover_path, $this->group->fresh()->modx_remote_cover_source_path);
    }

    public function test_stale_create_success_preserves_resource_and_leaves_new_revision_pending(): void
    {
        $this->approve();
        $this->fakeHttp(function () {
            $this->assertSame(1, DB::transactionLevel()); // Only RefreshDatabase's outer test transaction.
            app(GroupWorkflow::class)->save($this->group, $this->admin, ['title' => 'Newer title']);

            return Http::response($this->response());
        });
        $this->runJob(1);
        $group = $this->group->fresh();
        $this->assertSame(412, $group->public_site_resource_id);
        $this->assertSame('pending', $group->modx_sync_status);
        $this->assertSame(2, $group->modx_sync_revision);
        $this->assertNull($group->modx_synced_at);
        $this->runJob(1);
        Http::assertSentCount(1);
        $this->fakeHttp(['*' => Http::response($this->response(412, false))]);
        $this->runJob(2);
        Http::assertSent(fn ($request) => $request['resource_id'] === 412 && $request['resource']['pagetitle'] === 'Newer title');
    }

    public function test_retry_reuses_exact_body_and_key_and_drift_is_a_conflict(): void
    {
        $this->approve();
        $bodies = [];
        $keys = [];
        $this->fakeHttp(function ($request) use (&$bodies, &$keys) {
            $bodies[] = $request->body();
            $keys[] = $request->header('Idempotency-Key');

            return Http::response([], 503);
        });
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->runJob();
                $this->fail('Expected retry');
            } catch (ModxGroupSyncException $e) {
                $this->assertTrue($e->retryable);
                $this->assertSame('pending', $this->group->fresh()->modx_sync_status);
                $this->assertNull($e->getPrevious());
            }
        }
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame('approved', $this->group->fresh()->status->value);
        // Owner/dictionary changes do not themselves schedule a group revision.
        $this->owner->update(['first_name' => 'Changed']);
        $this->runJob();
        $this->assertSame('conflict', $this->group->fresh()->modx_sync_status);
        $this->assertSame('idempotency_conflict', $this->group->fresh()->modx_sync_error_code);
        Http::assertSentCount(2);
        app(GroupWorkflow::class)->syncModx($this->group, $this->admin);
        $this->runJob();
        Http::assertSentCount(2);
    }

    public function test_idempotency_conflict_never_rotates_create_key_and_remote_id_mismatch_never_overwrites(): void
    {
        $this->approve();
        $this->fakeHttp(['*' => Http::response(['error' => ['message' => 'Idempotency-Key was reused with a different request body']], 409)]);
        $this->runJob();
        $this->assertSame('conflict', $this->group->fresh()->modx_sync_status);
        app(GroupWorkflow::class)->syncModx($this->group, $this->admin);
        $this->runJob();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->header('Idempotency-Key') === ['group-create:'.$this->group->public_uuid]);
        $this->group->update(['public_site_resource_id' => 412]);
        app(GroupWorkflow::class)->syncModx($this->group, $this->admin);
        $this->fakeHttp(['*' => Http::response($this->response(999, false))]);
        $this->runJob();
        $this->assertSame(412, $this->group->fresh()->public_site_resource_id);
        $this->assertSame('resource_id_conflict', $this->group->fresh()->modx_sync_error_code);
    }

    public function test_stale_failures_future_jobs_ineligible_and_deleted_groups_do_not_write(): void
    {
        $this->approve();
        $this->fakeHttp(function () {
            app(GroupWorkflow::class)->save($this->group, $this->admin, ['title' => 'New revision']);

            return Http::response([], 422);
        });
        $this->runJob(1);
        (new SyncGroupToModx($this->group->id, 1))->failed(new ModxGroupSyncException('connection', true));
        $this->assertSame('pending', $this->group->fresh()->modx_sync_status);
        $this->assertNull($this->group->fresh()->modx_sync_error_code);
        $this->runJob(3);
        foreach (['draft', 'moderation', 'revision', 'rejected', 'awaiting_payment'] as $status) {
            $this->group->update(['status' => $status]);
            $this->runJob(2);
        }
        $this->group->delete();
        $this->runJob(2);
        Http::assertSentCount(1);
    }

    public static function notReady(): array
    {
        return [['price', 'meeting_price'], ['first', 'owner_id'], ['last', 'owner_id'], ['unicode_name', 'owner_id'], ['inactive', 'format_id'],
            ['unlinked', 'gender_id'], ['missing', 'format_id'], ['wrong_dictionary', 'format_id'],
            ['tag', 'tag_ids'], ['cover_missing', 'cover'], ['cover_mime', 'cover'], ['cover_size', 'cover']];
    }

    #[DataProvider('notReady')]
    public function test_approval_and_resync_reject_incomplete_content_safely(string $case, string $field): void
    {
        Queue::fake();
        match ($case) {
            'price' => $this->group->update(['meeting_price' => 12301]),
            'first' => $this->owner->update(['first_name' => '']),
            'last' => $this->owner->update(['last_name' => ' ']),
            'unicode_name' => $this->owner->update(['first_name' => "\u{00a0}"]),
            'inactive' => $this->group->format->update(['active' => false]),
            'unlinked' => $this->group->gender->update(['modx_value' => null]),
            'missing' => $this->group->update(['format_id' => null]),
            'wrong_dictionary' => $this->group->update(['format_id' => $this->group->gender_id]),
            'tag' => $this->group->tags->first()->update(['active' => false]),
            'cover_missing' => Storage::disk('local')->delete($this->group->cover_path),
            'cover_mime' => $this->group->update(['cover_mime_type' => 'image/jpeg']),
            'cover_size' => $this->group->update(['cover_size' => 5242881]),
        };
        $this->actingAs($this->admin)->post('/admin/groups/'.$this->group->id.'/approve', ['confirmed' => 1])->assertSessionHasErrors($field);
        $this->assertSame('moderation', $this->group->fresh()->status->value);
        $this->group->update(['status' => 'approved']);
        $this->post('/admin/groups/'.$this->group->id.'/sync-modx')->assertSessionHasErrors($field);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_submit_blocks_fractional_price_and_inactive_values_but_ordinary_legacy_edit_still_works(): void
    {
        $this->group->update(['status' => 'draft']);
        $fields = $this->group->only(GroupWorkflow::FIELDS) + ['approach_ids' => $this->group->approaches->modelKeys(), 'tag_ids' => $this->group->tags->modelKeys()];
        $fields['meeting_price'] = '123.01';
        $this->actingAs($this->owner)->post('/groups/'.$this->group->id.'/submit', $fields)->assertSessionHasErrors('meeting_price');
        $fields['meeting_price'] = '123';
        $this->group->format->update(['active' => false]);
        $this->post('/groups/'.$this->group->id.'/submit', $fields)->assertSessionHasErrors('format_id');
        $fields['meeting_price'] = '123.01';
        $this->put('/groups/'.$this->group->id, $fields)->assertSessionHasNoErrors();
        $this->assertSame(12301, $this->group->fresh()->meeting_price);
        $this->assertNull($this->group->fresh()->modx_sync_status);
        Http::assertNothingSent();
    }

    public function test_admin_panel_action_authorization_and_no_synchronous_http(): void
    {
        Queue::fake();
        $this->approve();
        foreach (['pending' => 'В очереди', 'syncing' => 'Выполняется', 'synced' => 'Синхронизировано', 'failed' => 'Ошибка', 'conflict' => 'Конфликт'] as $state => $label) {
            $this->group->update(['modx_sync_status' => $state, 'modx_sync_error_code' => 'idempotency_conflict']);
            $this->actingAs($this->admin)->get('/admin/groups/'.$this->group->id)->assertOk()->assertSee($label)
                ->assertSee('idempotency_conflict')->assertSee('Синхронизировать повторно')
                ->assertDontSee('synthetic-private-token')->assertDontSee($this->group->cover_path);
        }
        $this->post('/admin/groups/'.$this->group->id.'/sync-modx')->assertSessionHasNoErrors();
        $this->assertSame(2, $this->group->fresh()->modx_sync_revision);
        $this->actingAs($this->owner)->post('/admin/groups/'.$this->group->id.'/sync-modx')->assertForbidden();
        $this->get('/groups/'.$this->group->id)->assertOk()->assertDontSee('Синхронизация MODX')->assertDontSee('idempotency_conflict');
        Http::assertNothingSent();
    }

    public function test_only_content_edits_schedule_and_other_business_actions_do_not(): void
    {
        Queue::fake();
        app(GroupWorkflow::class)->moderate($this->group, $this->admin, GroupStatus::Revision, 'Synthetic revision comment');
        $fields = $this->group->fresh()->only(GroupWorkflow::FIELDS) + ['approach_ids' => $this->group->approaches->modelKeys(), 'tag_ids' => $this->group->tags->modelKeys()];
        app(GroupWorkflow::class)->save($this->group, $this->owner, $fields, true);
        Queue::assertNothingPushed();
        $this->approve();
        app(GroupWorkflow::class)->save($this->group, $this->admin, ['published_at' => now()]);
        $this->assertSame(1, $this->group->fresh()->modx_sync_revision);
        app(GroupWorkflow::class)->save($this->group, $this->admin, ['title' => 'Admin content']);
        $this->assertSame(2, $this->group->fresh()->modx_sync_revision);
        app(GroupWorkflow::class)->activate($this->group, $this->admin);
        app(GroupWorkflow::class)->delete($this->group, $this->admin);
        Queue::assertPushed(SyncGroupToModx::class, 2);
        Http::assertNothingSent();
    }

    public function test_schema_defaults_unique_remote_identity_and_model_immutability(): void
    {
        $this->assertSame(0, $this->group->fresh()->modx_sync_revision);
        $this->assertNull($this->group->fresh()->public_site_resource_id);
        $this->assertNull($this->group->fresh()->modx_sync_payload_hash);
        $other = Group::create(['owner_id' => $this->owner->id]);
        $this->group->update(['public_site_resource_id' => 412]);
        try {
            $this->group->update(['public_site_resource_id' => 999]);
            $this->fail('Expected immutable remote identity');
        } catch (\DomainException) {
            $this->assertSame(412, $this->group->fresh()->public_site_resource_id);
        }
        try {
            $other->update(['public_site_resource_id' => 412]);
            $this->fail('Expected unique remote identity');
        } catch (UniqueConstraintViolationException) {
            $this->assertNull($other->fresh()->public_site_resource_id);
        }
    }

    public function test_failing_approval_and_queue_outage_preserve_transaction_boundaries(): void
    {
        $transitions = \Mockery::mock(GroupStatusTransitionService::class);
        $transitions->shouldReceive('transition')->once()->andThrow(new \RuntimeException('Synthetic history failure'));
        $workflow = new GroupWorkflow($transitions, app(SettingService::class));
        try {
            $workflow->moderate($this->group, $this->admin, GroupStatus::Approved);
            $this->fail('Expected failed approval');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic history failure', $e->getMessage());
        }
        $this->assertSame('moderation', $this->group->fresh()->status->value);
        $this->assertSame(0, $this->group->fresh()->modx_sync_revision);
        $this->assertDatabaseCount('jobs', 0);
        Bus::shouldReceive('dispatch')->twice()->andThrow(new \RuntimeException('Synthetic queue outage'));
        $this->approve();
        $this->assertSame('approved', $this->group->fresh()->status->value);
        $this->assertSame('queue_unavailable', $this->group->fresh()->modx_sync_error_code);
        $this->actingAs($this->admin)->post('/admin/groups/'.$this->group->id.'/sync-modx')->assertSessionHasErrors('modx_sync');
        Http::assertNothingSent();
    }

    public function test_cleanup_warning_no_cover_path_and_safe_unknown_diagnostic(): void
    {
        $this->approve();
        $response = $this->response();
        $response['data']['cover_cleanup_warning'] = true;
        $response['data']['cover_path'] = null;
        $this->fakeHttp(['*' => Http::response($response)]);
        $this->runJob();
        $this->assertSame(412, $this->group->fresh()->public_site_resource_id);
        $this->assertNull($this->group->fresh()->modx_remote_cover_source_path);
        $this->assertTrue($this->group->fresh()->modx_cover_cleanup_warning);
        $this->group->update(['modx_sync_error_code' => 'unexpected-private-text']);
        $this->actingAs($this->admin)->get('/admin/groups/'.$this->group->id)->assertOk()
            ->assertSee('не удалось удалить предыдущую обложку')->assertDontSee('unexpected-private-text');
        (new SyncGroupToModx($this->group->id, 1))->failed(new ModxGroupSyncException('connection', true));
        $this->assertSame('synced', $this->group->fresh()->modx_sync_status);
    }

    public function test_database_worker_success_retry_exhaustion_and_overlap_lock(): void
    {
        config(['cache.default' => 'database']);
        $this->approve();
        $job = new SyncGroupToModx($this->group->id, 1);
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 120);
        $this->assertTrue($lock->get());
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        Http::assertNothingSent();
        $this->assertDatabaseCount('jobs', 1);
        $lock->release();
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        $this->fakeHttp(['*' => Http::response([], 503)]);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('approved', $this->group->fresh()->status->value);
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        $this->fakeHttp(function () use ($middleware, $job) {
            $this->assertFalse(Cache::lock($middleware->getLockKey($job), 120)->get());

            return Http::response($this->response());
        });
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('synced', $this->group->fresh()->modx_sync_status);
        app(GroupWorkflow::class)->syncModx($this->group, $this->admin);
        $this->fakeHttp(['*' => Http::response([], 503)]);
        for ($i = 0; $i < 4; $i++) {
            DB::table('jobs')->update(['available_at' => now()->timestamp]);
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        }
        $this->assertSame('failed', $this->group->fresh()->modx_sync_status);
        $this->assertSame('remote_unavailable', $this->group->fresh()->modx_sync_error_code);
        $this->assertSame(412, $this->group->fresh()->public_site_resource_id);
        $failed = DB::table('failed_jobs')->sole();
        foreach (['synthetic-private-token', 'Short description', 'First Name', $this->group->cover_path, 'content_base64'] as $private) {
            $this->assertStringNotContainsString($private, $failed->exception.$failed->payload);
        }
    }
}
