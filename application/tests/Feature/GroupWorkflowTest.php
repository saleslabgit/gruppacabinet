<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\GroupStatusTransitionService;
use App\Services\GroupWorkflow;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private array $fields;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->owner = User::query()->create(['email' => 'owner@example.test', 'first_name' => 'Owner', 'status' => 'approved', 'free' => false]);
        $this->admin = User::query()->create(['email' => 'admin@example.test', 'status' => 'approved', 'admin' => true]);
        $ids = [];
        foreach (['group_format', 'gender'] as $code) {
            $dictionary = Dictionary::query()->create(['code' => $code, 'name' => $code]);
            $ids[$code] = DictionaryItem::query()->create(['dictionary_id' => $dictionary->id, 'code' => 'test', 'name' => 'Test '.$code, 'active' => true])->id;
        }
        $this->fields = ['title' => 'Тестовая группа', 'description' => 'Описание группы', 'schedule' => 'По средам в 19:00',
            'format_id' => $ids['group_format'], 'gender_id' => $ids['gender'], 'meeting_duration_minutes' => '90',
            'participant_capacity' => '10', 'meeting_price' => '35,01'];
        Setting::query()->create(['key' => SettingService::PLACEMENT_DURATION_DAYS, 'type' => 'integer', 'value' => '37']);
        app(SettingService::class)->invalidate(SettingService::PLACEMENT_DURATION_DAYS);
        foreach ([SettingService::EXPIRY_WARNING_DAYS => '3', SettingService::EXPIRED_EXTENSION_WINDOW_DAYS => '30'] as $key => $value) {
            Setting::query()->create(['key' => $key, 'type' => 'integer', 'value' => $value]);
            app(SettingService::class)->invalidate($key);
        }
    }

    private function draft(): Group
    {
        return app(GroupWorkflow::class)->create($this->owner, $this->owner);
    }

    public static function tariffs(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('tariffs')]
    public function test_complete_workflow_has_initial_history_comments_immutable_uuid_and_no_payments(bool $free): void
    {
        $this->owner->update(['free' => $free]);
        $this->actingAs($this->owner)->post('/groups', ['owner_id' => $this->admin->id, 'status' => 'awaiting_payment', 'public_uuid' => 'forged'])->assertRedirect();
        $group = Group::query()->sole();
        $uuid = $group->public_uuid;
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        $this->assertSame($free, $group->free);
        $this->assertSame($this->owner->id, $group->owner_id);
        $this->assertSame(GroupStatus::Draft, $group->status);
        $initial = $group->statusHistory()->sole();
        $this->assertNull($initial->from_status);
        $this->assertSame($this->owner->id, $initial->actor_id);
        $this->assertSame('user', $initial->actor_type);
        $this->get('/groups/'.$group->id.'/edit')->assertOk()->assertSee('Сохранить изменения')->assertSee('Отправить на модерацию')->assertDontSee('name="public_uuid"', false);
        $this->put('/groups/'.$group->id, $this->fields)->assertRedirect();
        $this->assertSame(GroupStatus::Draft, $group->fresh()->status);
        $this->assertSame(3501, $group->fresh()->meeting_price);
        $this->post('/groups/'.$group->id.'/submit', $this->fields)->assertRedirect();
        $this->get('/groups/'.$group->id.'/edit')->assertForbidden();
        foreach (['Первое замечание модератора', 'Второе замечание модератора'] as $comment) {
            $this->actingAs($this->admin)->post('/admin/groups/'.$group->id.'/revision', ['confirmed' => 1, 'moderator_comment' => $comment])->assertRedirect();
            $this->actingAs($this->owner)->get('/groups/'.$group->id.'/edit')->assertOk()->assertSee($comment);
            $this->put('/groups/'.$group->id, $this->fields)->assertRedirect();
            $this->assertSame(GroupStatus::Revision, $group->fresh()->status);
            $this->post('/groups/'.$group->id.'/submit', $this->fields)->assertRedirect();
        }
        $this->actingAs($this->admin)->post('/admin/groups/'.$group->id.'/approve', ['confirmed' => 1])->assertRedirect();
        $this->assertNull($group->fresh()->published_at);
        $this->get('/admin/groups/'.$group->id)->assertOk()->assertSee('ID группы для gruppa.info')->assertSee($uuid)->assertSee('data-copy="public_uuid"', false)
            ->assertSee('Первое замечание модератора')->assertSee('Второе замечание модератора')->assertDontSee('WEBPAY')->assertDontSee('Оплата размещения');
        $this->travelTo(now()->utc()->setDate(2026, 9, 21)->setTime(12, 30));
        $group->update(['expiry_warning_sent_at' => now()->subDay()]);
        $this->post('/admin/groups/'.$group->id.'/activate', ['confirmed' => 1])->assertRedirect();
        $group->refresh();
        $this->assertSame(GroupStatus::Active, $group->status);
        $this->assertTrue($group->published_at->eq(now()));
        $this->assertSame(37, $group->placement_days);
        $this->assertTrue($group->expires_at->eq(now()->addDays(37)));
        $this->assertNull($group->expiry_warning_sent_at);
        $this->assertSame($uuid, $group->public_uuid);
        $last = $group->statusHistory()->latest('id')->first();
        $this->assertSame($this->admin->id, $last->actor_id);
        $before = $group->getAttributes();
        $historyCount = $group->statusHistory()->count();
        $this->travel(1)->days();
        $this->post('/admin/groups/'.$group->id.'/activate', ['confirmed' => 1])->assertForbidden();
        $this->post('/admin/groups/'.$group->id.'/approve', ['confirmed' => 1])->assertForbidden();
        $this->assertSame($before, $group->fresh()->getAttributes());
        $this->assertSame($historyCount, $group->statusHistory()->count());
        $this->assertDatabaseCount('gp_payments', 0);
        $this->actingAs($this->owner)->get('/groups/'.$group->id)->assertOk()->assertDontSee('_prototype')->assertDontSee('WEBPAY')->assertSee('Продлить размещение')->assertSee('Все заявки группы');
    }

    public function test_validation_and_protected_fields_for_both_roles(): void
    {
        $group = $this->draft();
        $immutable = $group->only(['owner_id', 'public_uuid', 'free', 'status', 'accept', 'disabled', 'published_at', 'expires_at', 'placement_days', 'expiry_warning_sent_at', 'deleted_at']);
        foreach ([$this->owner, $this->admin] as $actor) {
            $prefix = $actor->admin ? '/admin/groups/' : '/groups/';
            $this->actingAs($actor)->put($prefix.$group->id, $this->fields + [
                'owner_id' => $this->admin->id, 'public_uuid' => 'forged', 'free' => true, 'status' => 'active', 'accept' => true,
                'disabled' => true, 'published_at' => '2026-01-01', 'expires_at' => '2027-01-01', 'placement_days' => 99,
                'expiry_warning_sent_at' => '2026-01-01', 'deleted_at' => '2026-01-01', 'moderator_comment' => 'Forged comment', 'rejection_reason' => 'Forged reason',
            ])->assertRedirect();
            $this->assertEquals($immutable, $group->fresh()->only(array_keys($immutable)));
            $this->assertNull($group->fresh()->moderator_comment);
            $this->assertNull($group->fresh()->rejection_reason);
        }
        $this->actingAs($this->owner)->from('/groups/'.$group->id.'/edit')->post('/groups/'.$group->id.'/submit', array_replace($this->fields, ['title' => '', 'meeting_price' => '1.234']))
            ->assertSessionHasErrors(['title', 'meeting_price']);
        $this->assertSame(GroupStatus::Draft, $group->fresh()->status);
        $this->assertSame(1, $group->statusHistory()->count());
        $this->get('/groups/'.$group->id.'/edit')->assertOk()->assertSee('1.234');
        $this->assertNotSame($group->public_uuid, $this->draft()->public_uuid);
    }

    public static function money(): array
    {
        return [['35', 3500], ['35.1', 3510], ['0,01', 1], ['0', 0], ['9999999999999999.99', 999999999999999999], ['1.234', null], ['-1', null], ['1e3', null], ['1,2,3', null], ['NaN', null], ['99999999999999999', null]];
    }

    #[DataProvider('money')]
    public function test_money_is_converted_without_float(string $input, ?int $expected): void
    {
        $group = $this->draft();
        $response = $this->actingAs($this->owner)->put('/groups/'.$group->id, array_replace($this->fields, ['meeting_price' => $input]));
        if ($expected === null) {
            $response->assertSessionHasErrors('meeting_price');
            $this->assertNull($group->fresh()->meeting_price);
        } else {
            $response->assertSessionHasNoErrors();
            $this->assertSame($expected, $group->fresh()->meeting_price);
        }
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status->value], GroupStatus::cases());
    }

    #[DataProvider('statuses')]
    public function test_status_edit_and_submit_boundaries_and_admin_edit(string $status): void
    {
        $group = $this->draft();
        $group->update(['status' => $status]); // Deliberately seed historical states, not an application transition.
        $this->actingAs($this->owner);
        $allowed = in_array($status, ['draft', 'revision']);
        $this->get('/groups/'.$group->id.'/edit')->assertStatus($allowed ? 200 : 403);
        $this->put('/groups/'.$group->id, $this->fields)->assertStatus($allowed ? 302 : 403);
        $this->post('/groups/'.$group->id.'/submit', $this->fields)->assertStatus($allowed ? 302 : 403);
        $this->actingAs($this->admin)->put('/admin/groups/'.$group->id, $this->fields)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($allowed ? GroupStatus::Moderation : GroupStatus::from($status), $group->fresh()->status);
    }

    public function test_dictionary_selection_preserves_only_current_inactive_items(): void
    {
        $group = $this->draft();
        $this->actingAs($this->owner)->put('/groups/'.$group->id, $this->fields)->assertRedirect();
        DictionaryItem::query()->whereKey($this->fields['format_id'])->update(['active' => false]);
        $this->get('/groups/'.$group->id.'/edit')->assertOk()->assertSee('(неактивен)');
        $this->put('/groups/'.$group->id, $this->fields)->assertSessionHasNoErrors();
        $new = $this->draft();
        $this->get('/groups/'.$new->id.'/edit')->assertOk()->assertDontSee('Test group_format');
        $this->put('/groups/'.$new->id, $this->fields)->assertSessionHasErrors('format_id');
        $this->put('/groups/'.$group->id, array_replace($this->fields, ['format_id' => $this->fields['gender_id']]))->assertSessionHasErrors('format_id');
    }

    public function test_revision_rejection_confirmation_and_trim_validation_are_atomic(): void
    {
        $group = $this->draft();
        $this->actingAs($this->owner)->post('/groups/'.$group->id.'/submit', $this->fields)->assertRedirect();
        $this->actingAs($this->admin);
        $this->post('/admin/groups/'.$group->id.'/approve')->assertSessionHasErrors('confirmed');
        foreach (['revision' => 'moderator_comment', 'reject' => 'rejection_reason'] as $action => $field) {
            foreach (['', 'short', '          ', '    short    '] as $comment) {
                $this->post('/admin/groups/'.$group->id.'/'.$action, ['confirmed' => 1, $field => $comment])->assertSessionHasErrors($field);
                $this->assertSame(GroupStatus::Moderation, $group->fresh()->status);
                $this->assertNull($group->fresh()->$field);
                $this->assertSame(2, $group->statusHistory()->count());
            }
        }
        $this->post('/admin/groups/'.$group->id.'/reject', ['confirmed' => 1, 'rejection_reason' => '  Подробная причина отклонения  '])->assertRedirect();
        $this->assertSame('Подробная причина отклонения', $group->fresh()->rejection_reason);
        $this->assertSame('Подробная причина отклонения', $group->statusHistory()->latest('id')->first()->comment);
        $this->post('/admin/groups/'.$group->id.'/revision', ['confirmed' => 1, 'moderator_comment' => 'Нельзя менять отклонённую'])->assertForbidden();
        $this->assertNull($group->fresh()->moderator_comment);
        $this->actingAs($this->owner)->get('/groups/'.$group->id)->assertOk()->assertSee('Подробная причина отклонения');
        $this->post('/groups/'.$group->id.'/submit', $this->fields)->assertForbidden();
        $this->delete('/groups/'.$group->id, ['confirmed' => 1])->assertRedirect();
        $this->assertSoftDeleted($group);
        $this->assertSame(3, $group->statusHistory()->count());
    }

    public function test_owner_idor_and_admin_role_boundaries(): void
    {
        $group = $this->draft();
        $other = User::query()->create(['email' => 'other@example.test', 'status' => 'approved']);
        $this->actingAs($other);
        $this->get('/groups/'.$group->id)->assertNotFound();
        $this->get('/groups/'.$group->id.'/edit')->assertNotFound();
        $this->put('/groups/'.$group->id, $this->fields)->assertNotFound();
        $this->post('/groups/'.$group->id.'/submit', $this->fields)->assertNotFound();
        $this->delete('/groups/'.$group->id, ['confirmed' => 1])->assertNotFound();
        foreach (['/admin/groups', '/admin/groups/create', '/admin/groups/'.$group->id, '/admin/groups/'.$group->id.'/edit'] as $path) {
            $this->get($path)->assertForbidden();
        }
        foreach (['approve', 'revision', 'reject', 'activate'] as $action) {
            $this->post('/admin/groups/'.$group->id.'/'.$action, ['confirmed' => 1])->assertForbidden();
        }
        $this->post('/admin/groups', $this->fields + ['owner_id' => $other->id])->assertForbidden();
        $this->put('/admin/groups/'.$group->id, $this->fields)->assertForbidden();
        $this->delete('/admin/groups/'.$group->id, ['confirmed' => 1])->assertForbidden();
        $this->actingAs($this->admin)->get('/groups/'.$group->id)->assertForbidden();
    }

    public function test_admin_create_requires_eligible_owner_and_records_admin_actor(): void
    {
        $this->actingAs($this->admin)->get('/admin/groups/create')->assertOk()->assertSee($this->owner->email);
        $this->post('/admin/groups', $this->fields + ['owner_id' => $this->owner->id, 'public_uuid' => 'forged'])->assertRedirect()->assertSessionHasNoErrors();
        $group = Group::query()->sole();
        $this->assertSame(GroupStatus::Draft, $group->status);
        $this->assertFalse($group->free);
        $this->assertSame($this->admin->id, $group->statusHistory()->sole()->actor_id);
        $this->get('/admin/groups/'.$group->id.'/edit')->assertOk()->assertDontSee('name="owner_id"', false)->assertDontSee('name="public_uuid"', false)->assertSee('вручную перенести в каталог');
        foreach ([['disabled' => true], ['disabled' => false, 'status' => 'pending'], ['status' => 'rejected'], ['status' => 'approved', 'admin' => true]] as $state) {
            $this->owner->update($state);
            $this->post('/admin/groups', $this->fields + ['owner_id' => $this->owner->id])->assertSessionHasErrors('owner_id');
        }
        $this->owner->delete();
        $this->post('/admin/groups', $this->fields + ['owner_id' => $this->owner->id])->assertSessionHasErrors('owner_id');
        $this->assertDatabaseCount('gp_groups', 1);
        $this->assertDatabaseCount('gp_payments', 0);
    }

    public function test_delete_payment_safety_including_soft_deleted_historical_payment(): void
    {
        $group = $this->draft();
        $payment = Payment::query()->create(['owner_id' => $this->owner->id, 'group_id' => $group->id, 'type' => 'placement', 'order_number' => 'test-order', 'amount' => 3500, 'status' => 'succeeded']);
        $payment->delete();
        $this->actingAs($this->owner)->delete('/groups/'.$group->id, ['confirmed' => 1])->assertForbidden();
        $payment->update(['status' => 'refunded', 'refunded_at' => now()]);
        $this->delete('/groups/'.$group->id)->assertSessionHasErrors('confirmed');
        $this->delete('/groups/'.$group->id, ['confirmed' => 1])->assertRedirect();
        $this->assertSoftDeleted($group);
        $this->assertSame(1, $group->statusHistory()->count());
        $this->assertSame(1, Payment::withTrashed()->count());
        $this->get('/groups/'.$group->id)->assertNotFound();
    }

    public function test_abandoned_cutoff_matches_filter_and_delete_policy(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 21)->startOfDay());
        $old = $this->draft();
        $old->update(['created_at' => now()->subDays(30)]);
        $young = $this->draft();
        $young->update(['created_at' => now()->subDays(30)->addSecond()]);
        $this->actingAs($this->admin)->get('/admin/groups?quick=abandoned')->assertOk()->assertViewHas('groups', fn ($groups) => $groups->pluck('id')->all() === [$old->id]);
        $this->delete('/admin/groups/'.$young->id, ['confirmed' => 1])->assertForbidden();
        $this->delete('/admin/groups/'.$old->id, ['confirmed' => 1])->assertRedirect();
        $this->assertSoftDeleted($old);
        $this->assertSame(1, $old->statusHistory()->count());
        $young->update(['created_at' => now()->subDays(31), 'status' => 'active']);
        $this->delete('/admin/groups/'.$young->id, ['confirmed' => 1])->assertForbidden();
    }

    public function test_lists_filters_pagination_and_constant_queries_without_payments(): void
    {
        $first = $this->draft();
        $first->update(['title' => 'Unique title', 'status' => 'approved']);
        $other = User::query()->create(['email' => 'foreign@example.test', 'status' => 'approved', 'free' => true]);
        $foreign = app(GroupWorkflow::class)->create($other, $other, ['title' => 'Foreign title']);
        foreach (['Unique title', (string) $first->id, 'owner@example.test', 'Owner'] as $search) {
            $this->actingAs($this->admin)->get('/admin/groups?search='.urlencode($search))->assertOk()
                ->assertViewHas('groups', fn ($groups) => $groups->pluck('id')->all() === [$first->id]);
        }
        foreach (['status=approved', 'quick=approved', 'free=paid'] as $filter) {
            $this->get('/admin/groups?'.$filter)->assertOk()->assertViewHas('groups', fn ($groups) => $groups->pluck('id')->all() === [$first->id]);
        }
        $this->get('/admin/groups?sort=invalid')->assertSessionHasErrors('sort');
        foreach (['created_at', 'published_at', 'expires_at'] as $sort) {
            $this->get('/admin/groups?sort='.$sort)->assertOk();
        }
        $counts = [];
        foreach ([1, 24] as $size) {
            if ($size > 1) {
                for ($i = 1; $i < $size; $i++) {
                    $this->draft();
                }
            }
            foreach (['owner' => '/', 'admin' => '/admin/groups'] as $role => $path) {
                $this->actingAs($this->$role);
                DB::enableQueryLog();
                DB::flushQueryLog();
                $response = $this->get($path)->assertOk()->assertDontSee('_prototype')->assertDontSee('WEBPAY')->assertDontSee('successful_payment');
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                foreach ($queries as $query) {
                    $this->assertStringNotContainsString('gp_payments', $query['query']);
                    if ($role === 'admin') {
                        $this->assertStringNotContainsString('gp_group_applications', $query['query']);
                    }
                }
                $counts[$role][$size] = count($queries);
                if ($role === 'owner') {
                    $response->assertDontSee('Foreign title');
                }
                $response->assertViewHas('groups', fn ($groups) => $groups->count() === min(20, $size + ($role === 'admin' ? 1 : 0)));
            }
        }
        foreach ($counts as $sizes) {
            $this->assertSame($sizes[1], $sizes[24]);
        }
        $this->get('/admin/groups?free=paid&sort=expires_at')->assertOk()->assertSee('free=paid&amp;sort=expires_at&amp;page=2', false);
        $this->actingAs($this->owner)->get('/?page=2')->assertOk()->assertViewHas('groups', fn ($groups) => $groups->count() === 4);
        $first->delete();
        $this->get('/')->assertOk()->assertDontSee('Unique title');
        $this->assertFalse($this->owner->can('view', $foreign));
    }

    public function test_content_and_history_rollback_when_transition_fails(): void
    {
        $group = $this->draft();
        $transitions = \Mockery::mock(GroupStatusTransitionService::class);
        $transitions->shouldReceive('transition')->once()->andThrow(new \RuntimeException('Simulated history persistence failure'));
        $workflow = new GroupWorkflow($transitions, app(SettingService::class));
        try {
            $workflow->save($group, $this->owner, ['title' => 'Must roll back'], true);
            $this->fail('Expected a transition failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated history persistence failure', $exception->getMessage());
        }
        $this->assertNull($group->fresh()->title);
        $this->assertSame(GroupStatus::Draft, $group->fresh()->status);
        $this->assertSame(1, $group->statusHistory()->count());
    }

    public function test_sort_order_and_historical_actor_display(): void
    {
        $first = $this->draft();
        $second = $this->draft();
        $first->update(['created_at' => '2026-01-01', 'published_at' => '2026-03-01', 'expires_at' => '2026-04-01']);
        $second->update(['created_at' => '2026-02-01', 'published_at' => '2026-02-01', 'expires_at' => '2026-03-01']);
        $this->actingAs($this->admin);
        foreach (['created_at' => [$second->id, $first->id], 'published_at' => [$first->id, $second->id], 'expires_at' => [$first->id, $second->id]] as $sort => $expected) {
            $this->get('/admin/groups?sort='.$sort)->assertOk()->assertViewHas('groups', fn ($groups) => $groups->pluck('id')->all() === $expected);
        }
        $this->owner->delete();
        $this->get('/admin/groups/'.$first->id)->assertOk()->assertSee('Owner');
    }

    public static function revoked(): array
    {
        return [['disabled'], ['pending'], ['rejected'], ['deleted']];
    }

    #[DataProvider('revoked')]
    public function test_revoked_account_cannot_use_group_routes(string $state): void
    {
        $group = $this->draft();
        $this->actingAs($this->owner);
        if ($state === 'deleted') {
            $this->owner->delete();
        } else {
            $this->owner->update($state === 'disabled' ? ['disabled' => true] : ['status' => $state]);
        }
        $this->post('/groups/'.$group->id.'/submit', $this->fields)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(GroupStatus::Draft, $group->fresh()->status);
        $this->assertSame(1, $group->statusHistory()->count());
    }

    public function test_disabled_group_and_non_deletable_statuses_are_protected(): void
    {
        $group = $this->draft();
        $group->update(['disabled' => true]);
        $this->actingAs($this->owner)->put('/groups/'.$group->id, $this->fields)->assertForbidden();
        $this->post('/groups/'.$group->id.'/submit', $this->fields)->assertForbidden();
        $this->delete('/groups/'.$group->id, ['confirmed' => 1])->assertForbidden();
        foreach (['moderation', 'revision', 'approved', 'active', 'expired', 'awaiting_payment'] as $status) {
            $group->update(['disabled' => false, 'status' => $status]);
            $this->delete('/groups/'.$group->id, ['confirmed' => 1])->assertForbidden();
        }
        $this->assertFalse($group->fresh()->trashed());
    }
}
