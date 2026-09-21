<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Group;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PsychologistActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PsychologistAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'http://localhost');
        URL::forceRootUrl('http://localhost');
        $this->admin = $this->person(['admin' => true]);
        $this->actingAs($this->admin);
    }

    private function person(array $data = []): User
    {
        return User::query()->create(array_merge([
            'email' => (string) Str::uuid().'@example.test', 'status' => UserStatus::Approved, 'disabled' => false,
            'admin' => false, 'free' => false, 'remember_token' => 'old-token',
        ], $data));
    }

    public function test_real_pages_and_prototype_forms_are_separate_modes(): void
    {
        $person = $this->person();
        foreach (['/admin', '/admin/psychologists', '/admin/psychologists/create', '/admin/psychologists/'.$person->id, '/admin/psychologists/'.$person->id.'/edit', '/admin/psychologists/'.$person->id.'/documents'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('_prototype')->assertDontSee('data-noop')->assertDontSee('data-prototype-form');
        }
        $this->get('/admin/psychologists/'.$person->id)->assertDontSee('Повторно отправить')->assertDontSee('old-token');
        $this->get('/_prototype/admin-user-form/create')->assertOk()->assertSee('data-prototype-form');
    }

    public function test_list_search_filters_pagination_exclusions_and_constant_queries(): void
    {
        $target = $this->person(['first_name' => 'UniqueFirst', 'last_name' => 'UniqueLast', 'middle_name' => 'UniqueMiddle', 'email' => 'needle@example.test', 'phone' => '37512345', 'status' => 'pending', 'free' => true]);
        $deleted = $this->person(['email' => 'deleted@example.test']);
        $deleted->delete();
        foreach (['UniqueFirst', 'UniqueLast', 'UniqueMiddle', 'UniqueLast%20UniqueFirst', 'needle@', '375123'] as $search) {
            $this->get('/admin/psychologists?search='.$search)->assertOk()->assertSee($target->email)->assertDontSee($deleted->email);
        }
        $this->get('/admin/psychologists?status=pending&free=free')->assertSee($target->email);
        $this->get('/admin/psychologists?status=approved')->assertDontSee($target->email);
        $this->get('/admin/psychologists?free=paid')->assertDontSee($target->email);
        $this->get('/admin/psychologists?search=missing')->assertSee('Психологи не найдены');
        $this->get('/admin/psychologists?search=0')->assertSee('Психологи не найдены');
        $countQueries = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get('/admin/psychologists')->assertOk();
            $this->assertNotContains($this->admin->id, $response->viewData('users')->pluck('id')->all());
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $small = $countQueries();
        $this->assertSame(3, $small);
        for ($i = 0; $i < 24; $i++) {
            $this->person(['first_name' => 'PageNeedle']);
        }
        $this->assertSame($small, $countQueries());
        $response = $this->get('/admin/psychologists?search=PageNeedle&status=approved&free=paid')->assertOk();
        $this->assertCount(20, $response->viewData('users'));
        $response->assertSee('search=PageNeedle&amp;status=approved&amp;free=paid&amp;page=2', false);
        $this->get('/admin/psychologists?search=PageNeedle&status=approved&free=paid&page=2')->assertOk()->assertViewHas('users', fn ($users) => $users->count() === 4);
    }

    public function test_create_forces_safe_fields_and_update_only_writes_profile(): void
    {
        Mail::fake();
        Queue::fake();
        $forbidden = ['admin' => true, 'status' => 'approved', 'accept' => true, 'disabled' => true, 'password' => 'secret', 'remember_token' => 'bad', 'deleted_at' => '2020-01-01'];
        $this->post('/admin/psychologists', array_merge($forbidden, [
            'email' => ' NEW@example.test ', 'free' => true, 'first_name' => 'New',
            'personal_data_consent_at' => '2026-09-21T12:30',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $person = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertFalse($person->admin);
        $this->assertFalse($person->disabled);
        $this->assertFalse($person->accept);
        $this->assertTrue($person->free);
        $this->assertNull($person->password);
        $this->assertNull($person->remember_token);
        $this->assertNull($person->deleted_at);
        $this->assertSame(UserStatus::Pending, $person->status);
        $this->assertSame('2026-09-21 09:30:00', $person->personal_data_consent_at->format('Y-m-d H:i:s'));
        $this->get('/admin/psychologists/'.$person->id.'/edit')->assertSee('2026-09-21T12:30')->assertDontSee('name="free"', false)->assertDontSee('name="disabled"', false);
        $this->put('/admin/psychologists/'.$person->id, array_merge($forbidden, [
            'email' => 'updated@example.test', 'first_name' => 'Edited', 'free' => false,
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $person->refresh();
        $this->assertSame('Edited', $person->first_name);
        $this->assertFalse($person->admin);
        $this->assertFalse($person->disabled);
        $this->assertTrue($person->free);
        $this->assertNull($person->password);
        $this->assertNull($person->remember_token);
        $this->assertNull($person->deleted_at);
        $this->assertSame(UserStatus::Pending, $person->status);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_validation_active_email_uniqueness_and_old_input(): void
    {
        $person = $this->person();
        $this->from('/admin/psychologists/create')->post('/admin/psychologists', ['email' => $person->email, 'free' => 0])->assertSessionHasErrors('email');
        $this->from('/admin/psychologists/create')->post('/admin/psychologists', ['email' => 'invalid', 'free' => 0, 'training_hours' => -1])->assertSessionHasErrors(['email', 'training_hours']);
        $this->get('/admin/psychologists/create')->assertOk()->assertSee('value="invalid"', false)->assertSee('is-invalid');
        $this->put('/admin/psychologists/'.$person->id, ['email' => $this->admin->email])->assertSessionHasErrors('email');
        $person->delete();
        $this->post('/admin/psychologists', ['email' => $person->email, 'free' => 0])->assertSessionHasNoErrors();
        $this->assertSame(1, User::query()->where('email', $person->email)->count());
    }

    public function test_education_options_preserve_existing_inactive_item_only(): void
    {
        $dictionary = Dictionary::query()->create(['code' => 'education_type', 'name' => 'Education']);
        $active = DictionaryItem::query()->create(['dictionary_id' => $dictionary->id, 'code' => 'active', 'name' => 'Active education', 'active' => true]);
        $inactive = DictionaryItem::query()->create(['dictionary_id' => $dictionary->id, 'code' => 'inactive', 'name' => 'Inactive education', 'active' => false]);
        $person = $this->person(['education_type_id' => $inactive->id]);
        $this->get('/admin/psychologists/create')->assertSee($active->name)->assertDontSee($inactive->name);
        $this->get('/admin/psychologists/'.$person->id.'/edit')->assertSee($inactive->name.' (неактивен)');
        $this->put('/admin/psychologists/'.$person->id, ['email' => $person->email, 'education_type_id' => $inactive->id])->assertSessionHasNoErrors();
        $this->post('/admin/psychologists', ['email' => 'invalid-dictionary@example.test', 'free' => 0, 'education_type_id' => $inactive->id])->assertSessionHasErrors('education_type_id');
    }

    public function test_approve_uses_transition_and_invalid_transition_has_no_effect_or_mail(): void
    {
        Mail::fake();
        Queue::fake();
        $person = $this->person(['status' => 'pending']);
        $this->post('/admin/psychologists/'.$person->id.'/approve')->assertSessionHasErrors('confirmed');
        $this->post('/admin/psychologists/'.$person->id.'/approve', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(UserStatus::Approved, $person->fresh()->status);
        $this->assertTrue($person->fresh()->accept);
        $this->assertNull($person->fresh()->password);
        $audit = AuditLog::query()->sole();
        $this->assertSame('user.approved', $audit->action);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertEquals(['old_status' => 'pending', 'new_status' => 'approved'], $audit->metadata);
        $this->post('/admin/psychologists/'.$person->id.'/reject', ['confirmed' => 1])->assertSessionHasErrors('action');
        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame(UserStatus::Approved, $person->fresh()->status);
        $this->get('/admin/psychologists/'.$person->id)->assertOk()
            ->assertSeeInOrder(['Анкета получена', 'Анкета принята', $this->admin->email]);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public static function revocations(): array
    {
        return [['disable', 'disabled'], ['reject', 'rejected'], ['destroy', 'deleted']];
    }

    #[DataProvider('revocations')]
    public function test_actions_revoke_only_target_sessions_and_record_minimal_audit(string $route, string $action): void
    {
        $person = $this->person(['status' => $route === 'reject' ? 'pending' : 'approved']);
        $group = Group::query()->create(['owner_id' => $person->id, 'title' => 'Historical']);
        foreach (['target1' => $person->id, 'target2' => $person->id, 'other' => $this->admin->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }
        $path = '/admin/psychologists/'.$person->id;
        ($route === 'destroy' ? $this->delete($path, ['confirmed' => 1]) : $this->post($path.'/'.$route, ['confirmed' => 1]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['user_id' => $person->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other']);
        $fresh = User::withTrashed()->findOrFail($person->id);
        $this->assertNotSame('old-token', $fresh->remember_token);
        $this->assertDatabaseHas('gp_groups', ['id' => $group->id]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('user.'.$action, $audit->action);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertEquals(match ($action) {
            'disabled' => ['old_disabled' => false, 'new_disabled' => true],
            'rejected' => ['old_status' => 'pending', 'new_status' => 'rejected'],
            default => null,
        }, $audit->metadata);
        if ($route === 'destroy') {
            $this->assertSoftDeleted($person);
            $this->get($path)->assertNotFound();
        } elseif ($route === 'disable') {
            $this->post($path.'/disable', ['confirmed' => 1])->assertSessionHasErrors('action');
            $this->assertSame(1, AuditLog::query()->count());
            $this->post($path.'/enable', ['confirmed' => 1])->assertSessionHasNoErrors();
            $this->assertFalse($person->fresh()->disabled);
            $this->assertDatabaseMissing('sessions', ['user_id' => $person->id]);
            $this->assertEquals(['old_disabled' => true, 'new_disabled' => false], AuditLog::query()->latest('id')->first()->metadata);
        }
    }

    public function test_tariff_preserves_group_snapshot_and_audits_once(): void
    {
        $person = $this->person(['free' => true]);
        $group = Group::query()->create(['owner_id' => $person->id, 'title' => 'Historical']);
        $this->post('/admin/psychologists/'.$person->id.'/tariff', ['confirmed' => 1, 'free' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($person->fresh()->free);
        $this->assertTrue($group->fresh()->free);
        $audit = AuditLog::query()->sole();
        $this->assertSame('user.tariff_changed', $audit->action);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertEquals(['old_free' => true, 'new_free' => false], $audit->metadata);
        $this->post('/admin/psychologists/'.$person->id.'/tariff', ['confirmed' => 1, 'free' => 0])->assertSessionHasErrors('action');
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_audit_failure_rolls_back_state_tokens_and_sessions(): void
    {
        $person = $this->person();
        DB::table('sessions')->insert(['id' => 'rollback', 'user_id' => $person->id, 'payload' => '', 'last_activity' => time()]);
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        try {
            app(PsychologistActions::class)->run($person, $this->admin, 'disabled');
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        }
        $this->assertFalse($person->fresh()->disabled);
        $this->assertSame('old-token', $person->fresh()->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'rollback']);
        $this->assertDatabaseCount('gp_audit_log', 0);
    }

    public function test_admin_targets_and_psychologist_role_are_denied_on_all_management_routes(): void
    {
        $target = $this->person(['admin' => true]);
        $paths = [
            ['GET', ''], ['GET', '/edit'], ['PUT', ''], ['DELETE', ''],
            ['POST', '/approve'], ['POST', '/reject'], ['POST', '/enable'], ['POST', '/disable'], ['POST', '/tariff'],
            ['GET', '/documents'], ['POST', '/documents'],
        ];
        foreach ($paths as [$method, $suffix]) {
            $this->call($method, '/admin/psychologists/'.$target->id.$suffix, ['confirmed' => 1])->assertForbidden();
        }
        $psychologist = $this->person();
        $this->actingAs($psychologist);
        $this->get('/admin/psychologists')->assertForbidden();
        $this->get('/admin/psychologists/create')->assertForbidden();
        $this->post('/admin/psychologists')->assertForbidden();
        foreach ($paths as [$method, $suffix]) {
            $this->call($method, '/admin/psychologists/'.$psychologist->id.$suffix, ['confirmed' => 1])->assertForbidden();
        }
        $this->actingAs($this->admin);
        $this->admin->update(['disabled' => true]);
        $this->get('/admin/psychologists')->assertRedirect(route('login'));
    }
}
