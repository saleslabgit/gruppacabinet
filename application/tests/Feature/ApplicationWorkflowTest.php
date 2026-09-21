<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupApplication;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    private User $admin;

    private Group $group;

    private Group $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->owner = User::create(['email' => 'applications-owner@example.test', 'last_name' => 'Тестовый', 'first_name' => 'Психолог', 'status' => 'approved']);
        $this->other = User::create(['email' => 'applications-other@example.test', 'status' => 'approved']);
        $this->admin = User::create(['email' => 'applications-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $this->group = Group::create(['owner_id' => $this->owner->id, 'title' => 'Тестовая группа Альфа']);
        $this->foreign = Group::create(['owner_id' => $this->other->id, 'title' => 'Тестовая группа Бета']);
    }

    private function path(?GroupApplication $application = null, ?Group $group = null): string
    {
        return '/groups/'.($group ?? $this->group)->id.'/applications'.($application ? '/'.$application->id : '');
    }

    public static function phones(): array
    {
        return [
            ['+375 (29) 123-45-67', '+375291234567'],
            ['00375 29 123 45 67', '+375291234567'],
            ['+375291234567', '+375291234567'],
            [" \t+1 (202).555-0100 \n", '+12025550100'],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone_normalization(string $raw, string $expected): void
    {
        $phones = new PhoneNormalizer;
        $this->assertSame($expected, $phones->normalizeForStorage($raw));
        $this->assertSame(substr($expected, 1), $phones->digitsForSearch($raw));
    }

    public static function invalidPhones(): array
    {
        return [['0291234567'], ['375291234567'], ['not a phone'], ['+1abc2025550100'], ['++12025550100'], ['+0123456789'], ['+12'], ['+1234567890123456'], ['']];
    }

    #[DataProvider('invalidPhones')]
    public function test_ambiguous_or_malformed_phone_fails_explicitly(string $phone): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PhoneNormalizer)->normalizeForStorage($phone);
    }

    public function test_factory_is_synthetic_normalizes_overridden_phone_and_supports_states(): void
    {
        $record = GroupApplication::factory()->for($this->group)->create(['phone' => '+1 (202) 555-0101']);
        $this->assertSame('+12025550101', $record->phone_normalized);
        $this->assertStringContainsString('Тестовый', $record->last_name);
        $this->assertNull($record->processed_at);
        $this->assertNotNull(GroupApplication::factory()->for($this->group)->processed()->create()->processed_at);
        $this->assertNull(GroupApplication::factory()->for($this->group)->processed()->unprocessed()->create()->processed_at);
        $this->assertSame('', (new PhoneNormalizer)->digitsForSearch('Текст 123'));
        $this->expectException(\LogicException::class);
        GroupApplication::factory()->create();
    }

    public function test_owner_counters_details_and_idempotent_processing_without_side_effects(): void
    {
        Mail::fake();
        Queue::fake();
        $this->actingAs($this->owner)->get('/')->assertOk()->assertViewHas('groups', fn ($groups) => $groups->first()['all_count'] === 0);
        $this->get($this->path())->assertOk()->assertSee('Заявок не найдено');
        $record = GroupApplication::factory()->for($this->group)->create();
        $before = $this->group->fresh()->getAttributes();
        $this->get('/')->assertOk()->assertSee($this->path())->assertViewHas('groups', fn ($groups) => $groups->first()['new_count'] === 1);
        $this->get('/groups/'.$this->group->id)->assertOk()->assertSee('Все заявки группы')->assertSee($record->phone);
        $this->get($this->path($record))->assertOk()->assertSee($record->phone)->assertDontSee('phone_normalized')->assertDontSee('_prototype');
        $this->travelTo(now('UTC')->startOfSecond());
        $this->post($this->path($record).'/processed')->assertRedirect();
        $processed = $record->fresh()->processed_at;
        $updated = $record->fresh()->updated_at;
        $this->assertTrue($processed->equalTo(now('UTC')));
        $this->travel(5)->minutes();
        $this->post($this->path($record).'/processed')->assertRedirect();
        $this->assertTrue($record->fresh()->processed_at->equalTo($processed));
        $this->assertTrue($record->fresh()->updated_at->equalTo($updated));
        $this->get('/')->assertViewHas('groups', fn ($groups) => $groups->first()['new_count'] === 0 && $groups->first()['processed_count'] === 1 && $groups->first()['all_count'] === 1);
        $this->get('/groups/'.$this->group->id)->assertViewHas('group', fn ($group) => $group['processed_count'] === 1);
        $this->post($this->path($record).'/unprocessed')->assertRedirect();
        $updated = $record->fresh()->updated_at;
        $this->travel(5)->minutes();
        $this->post($this->path($record).'/unprocessed')->assertRedirect();
        $this->assertNull($record->fresh()->processed_at);
        $this->assertTrue($record->fresh()->updated_at->equalTo($updated));
        $this->get('/')->assertViewHas('groups', fn ($groups) => $groups->first()['new_count'] === 1 && $groups->first()['processed_count'] === 0);
        $this->assertSame($before, $this->group->fresh()->getAttributes());
        foreach (['gp_payments', 'gp_group_status_history', 'gp_audit_log', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_owner_filters_order_pagination_and_scoped_idor(): void
    {
        $records = GroupApplication::factory()->for($this->group)->count(22)->create(['created_at' => '2026-01-01 00:00:00']);
        $processed = GroupApplication::factory()->for($this->group)->processed()->create();
        $foreign = GroupApplication::factory()->for($this->foreign)->create(['last_name' => 'Чужой участник']);
        $this->actingAs($this->owner);
        $this->get($this->path().'?processed=new')->assertOk()->assertDontSee('Чужой участник')
            ->assertSee('processed=new&amp;page=2', false)
            ->assertViewHas('applications', fn ($rows) => $rows->pluck('id')->all() === $records->reverse()->take(20)->pluck('id')->values()->all());
        $this->get($this->path().'?processed=new&page=2')->assertViewHas('applications', fn ($rows) => $rows->count() === 2);
        $this->get($this->path().'?processed=processed')->assertViewHas('applications', fn ($rows) => $rows->pluck('id')->all() === [$processed->id]);
        $this->get($this->path().'?processed=all')->assertViewHas('applications', fn ($rows) => $rows->count() === 20 && $rows->first()['id'] === $processed->id);
        $this->get($this->path().'?processed=invalid')->assertSessionHasErrors('processed');
        $secondOwned = Group::create(['owner_id' => $this->owner->id]);
        foreach ([$this->path($foreign), $this->path($foreign, $this->foreign), $this->path($processed, $secondOwned)] as $path) {
            $this->get($path)->assertNotFound();
            $this->post($path.'/processed')->assertNotFound();
            $this->post($path.'/unprocessed')->assertNotFound();
        }
        $this->get($this->path(null, $this->foreign))->assertNotFound();
        $this->get('/admin/applications')->assertForbidden();
        $this->get('/admin/applications/'.$foreign->id)->assertForbidden();
        $this->actingAs($this->admin)->get($this->path())->assertForbidden();
        $this->post($this->path($processed).'/processed')->assertForbidden();
        $this->assertFalse($this->admin->can('process', $processed));
        $this->assertFalse($this->other->can('view', $processed));
    }

    public function test_owner_can_read_historical_and_disabled_groups_applications(): void
    {
        $record = GroupApplication::factory()->for($this->group)->create();
        $this->actingAs($this->owner);
        foreach (['draft', 'active', 'expired', 'rejected'] as $status) {
            $this->group->update(['status' => $status, 'disabled' => true]);
            $this->get($this->path())->assertOk();
            $this->get($this->path($record))->assertOk();
            $this->post($this->path($record).'/processed')->assertRedirect();
        }
    }

    public function test_admin_search_filters_details_and_historical_parents(): void
    {
        $record = GroupApplication::factory()->for($this->group)->create(['last_name' => 'Синтетическая', 'first_name' => 'Заявка', 'phone' => '+1 (202) 555-0100']);
        $foreign = GroupApplication::factory()->for($this->foreign)->processed()->create(['phone' => '+1 (202) 555-0199']);
        $this->actingAs($this->admin)->get('/admin/applications')->assertOk()->assertViewHas('applications', fn ($rows) => $rows->count() === 2);
        foreach (['Синтетическая', 'Заявка', 'Синтетическая Заявка', '+1 (202) 555-0100', '0012025550100', '12025550100', '202.555.0100', 'Альфа', 'Тестовый Психолог', $this->owner->email] as $search) {
            $this->get('/admin/applications?search='.urlencode($search))->assertOk()
                ->assertViewHas('applications', fn ($rows) => $rows->pluck('id')->all() === [$record->id]);
        }
        $this->get('/admin/applications?search=Ничего')->assertOk()->assertSee('Заявок не найдено');
        $this->get('/admin/applications?processed=new')->assertViewHas('applications', fn ($rows) => $rows->pluck('id')->all() === [$record->id]);
        $this->get('/admin/applications?processed=processed')->assertViewHas('applications', fn ($rows) => $rows->pluck('id')->all() === [$foreign->id]);
        $this->get('/admin/applications/'.$record->id)->assertOk()->assertSee('/admin/groups/'.$this->group->id)
            ->assertSee('/admin/psychologists/'.$this->owner->id)->assertDontSee('Отметить обработанной')->assertDontSee('_prototype');
        $this->group->delete();
        $this->owner->delete();
        $this->get('/admin/applications?search=Альфа')->assertOk()->assertSee('Синтетическая');
        $this->get('/admin/applications/'.$record->id)->assertOk()->assertSee('Тестовый Психолог');
    }

    public function test_application_lists_and_group_counters_have_constant_queries(): void
    {
        $counts = [];
        GroupApplication::factory()->for($this->group)->create();
        foreach ([1, 24] as $size) {
            if ($size > 1) {
                GroupApplication::factory()->for($this->group)->count(23)->create();
                for ($i = 0; $i < 23; $i++) {
                    $owner = User::create(['email' => 'synthetic-'.$i.'@example.test', 'status' => 'approved']);
                    $group = Group::create(['owner_id' => $owner->id]);
                    GroupApplication::factory()->for($group)->create();
                    Group::create(['owner_id' => $this->owner->id]);
                }
            }
            foreach (['groups' => '/', 'owner' => $this->path(), 'admin' => '/admin/applications'] as $surface => $url) {
                $this->actingAs($surface === 'admin' ? $this->admin : $this->owner);
                DB::enableQueryLog();
                DB::flushQueryLog();
                $this->get($url)->assertOk();
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $counts[$surface][$size] = count($queries);
                foreach ($queries as $query) {
                    $this->assertStringNotContainsString('gp_payments', $query['query']);
                }
                if ($surface === 'groups') {
                    $this->assertCount(1, array_filter($queries, fn ($q) => str_contains($q['query'], 'gp_group_applications')));
                }
            }
        }
        foreach ($counts as $surface => $sizes) {
            $this->assertSame($sizes[1], $sizes[24], $surface);
        }
        $this->get('/admin/applications?search=Тестовый&processed=new')->assertOk()->assertSee('processed=new&amp;page=2', false)
            ->assertViewHas('applications', fn ($rows) => $rows->count() === 20);
    }

    public static function revoked(): array
    {
        $cases = [];
        foreach ([false, true] as $admin) {
            foreach (['disabled', 'pending', 'rejected', 'deleted'] as $state) {
                $cases[] = [$admin, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('revoked')]
    public function test_revoked_accounts_lose_application_access(bool $admin, string $state): void
    {
        $record = GroupApplication::factory()->for($this->group)->create();
        $actor = $admin ? $this->admin : $this->owner;
        if ($state === 'deleted') {
            $actor->delete();
        } else {
            $actor->update($state === 'disabled' ? ['disabled' => true] : ['status' => $state]);
        }
        foreach ($admin ? ['/admin/applications', '/admin/applications/'.$record->id] : [$this->path(), $this->path($record)] as $url) {
            $this->actingAs($actor)->get($url)->assertRedirect(route('login'));
            $this->assertGuest();
        }
        if (! $admin) {
            $this->actingAs($actor)->post($this->path($record).'/processed')->assertRedirect(route('login'));
            $this->assertNull($record->fresh()->processed_at);
        }
    }

    public function test_routes_have_no_application_intake_or_admin_mutations(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'applications') && ! str_contains($route->uri(), '_prototype'));
        $this->assertCount(6, $routes);
        foreach ($routes as $route) {
            $this->assertStringNotContainsString('api/', $route->uri());
            $this->assertStringNotContainsString('create', $route->uri());
            if (in_array('POST', $route->methods())) {
                $this->assertStringStartsWith('groups/{group}/applications/{application}/', $route->uri());
            }
        }
        $this->actingAs($this->admin)->post('/admin/applications')->assertStatus(405);
        $this->actingAs($this->owner)->post($this->path())->assertStatus(405);
    }
}
