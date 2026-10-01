<?php

namespace Tests\Feature;

use App\Exceptions\ModxDictionarySyncException;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Group;
use App\Models\User;
use App\Services\DictionaryManagement;
use App\Services\ModxDictionarySyncService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ModxDictionaryFixture as Fixture;
use Tests\TestCase;

class ModxDictionarySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Fixture::configure();
        URL::forceRootUrl('http://localhost');
    }

    private function dictionary(string $code = 'group_format'): Dictionary
    {
        return Dictionary::where('code', $code)->firstOrFail();
    }

    private function sync(): array
    {
        return app(ModxDictionarySyncService::class)->sync();
    }

    private function snapshot(): array
    {
        return [DB::table('gp_dictionaries')->orderBy('id')->get()->toJson(),
            DB::table('gp_dictionary_items')->orderBy('id')->get()->toJson()];
    }

    private function assertSyncFailsWithoutChanges(): void
    {
        $before = $this->snapshot();
        try {
            $this->sync();
            $this->fail('Expected a safe sync failure.');
        } catch (ModxDictionarySyncException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('synthetic-modx-secret', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_complete_import_exact_values_and_same_labels_are_distinct(): void
    {
        $document = Fixture::document();
        $values = ['51', '105', 'a', 'A', 'á', 'a ', '01', '1'];
        $document['data']['dictionaries']['tags']['options'] = array_map(
            fn ($value, $position) => ['value' => $value, 'label' => 'Одинаковая метка', 'position' => $position], $values, array_keys($values));
        Fixture::fake($document);
        $counts = $this->sync();
        $this->assertSame(['dictionaries' => 5, 'options' => 12, 'created' => 12, 'linked' => 0, 'deactivated' => 0], $counts);
        $this->assertSame($values, $this->dictionary('group_tag')->items()->orderBy('sort_order')->pluck('modx_value')->all());
        $this->assertSame(5, Dictionary::whereNotNull('last_synced_at')->count());
        foreach ($document['data']['dictionaries'] as $tv => $definition) {
            $dictionary = Dictionary::where('modx_tv_name', $tv)->firstOrFail();
            foreach ($definition['options'] as $option) {
                $item = $dictionary->items()->where('modx_value', $option['value'])->firstOrFail();
                $this->assertSame($option['label'], $item->name);
                $this->assertSame($option['position'], $item->sort_order);
                $this->assertTrue($item->active);
                $this->assertNotNull($item->last_synced_at);
                $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,64}\z/', $item->code);
            }
        }
        $ids = DictionaryItem::orderBy('id')->pluck('code', 'id')->all();
        $this->assertSame(0, $this->sync()['created']);
        $this->assertSame($ids, DictionaryItem::orderBy('id')->pluck('code', 'id')->all());
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://modx.example.test/api/v1/cabinet/dictionaries'
            && $request->hasHeader('Authorization', 'Bearer synthetic-modx-secret') && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_local_dictionaries_are_untouched_by_import(): void
    {
        foreach (['education_type', 'custom'] as $code) {
            $dictionary = Dictionary::create(['code' => $code, 'name' => $code]);
            $item = $dictionary->items()->create(['code' => 'local', 'name' => 'Local value', 'active' => true]);
            $before[$code] = [$dictionary->fresh()->toArray(), $item->fresh()->toArray()];
        }
        Fixture::fake();
        $this->sync();
        foreach ($before as $code => [$dictionary, $item]) {
            $this->assertSame($dictionary, $this->dictionary($code)->toArray());
            $this->assertSame($item, $this->dictionary($code)->items()->firstOrFail()->toArray());
        }
    }

    public function test_legacy_unicode_bootstrap_preserves_ids_codes_group_fks_and_unmatched_history(): void
    {
        $format = $this->dictionary()->items()->create(['code' => 'legacy_format', 'name' => "\u{00a0}ОФЛАЙН\t "]);
        $gender = $this->dictionary('gender')->items()->create(['code' => 'legacy_gender', 'name' => " СМЕШАННАЯ\n "]);
        $unmatched = $this->dictionary()->items()->create(['code' => 'old', 'name' => 'Исторический формат']);
        $owner = User::create(['email' => 'owner@example.test', 'status' => 'approved']);
        $group = Group::create(['owner_id' => $owner->id, 'format_id' => $format->id, 'gender_id' => $gender->id]);
        Fixture::fake();
        $this->assertSame(2, $this->sync()['linked']);
        foreach ([$format, $gender] as $item) {
            $this->assertSame($item->code, $item->fresh()->code);
            $this->assertNotNull($item->fresh()->modx_value);
        }
        $this->assertSame($format->id, $group->fresh()->format_id);
        $this->assertSame($gender->id, $group->fresh()->gender_id);
        $this->assertFalse($unmatched->fresh()->active);
        $this->assertNull($unmatched->fresh()->modx_value);
    }

    public function test_rename_disappearance_and_reappearance_preserve_identity(): void
    {
        Fixture::fake();
        $this->sync();
        $item = $this->dictionary()->items()->firstOrFail();
        $document = Fixture::document();
        $document['data']['dictionaries']['format']['options'][0]['label'] = 'Новое название';
        Fixture::fake($document);
        $this->sync();
        $this->assertSame('Новое название', $item->fresh()->name);
        $document['data']['dictionaries']['format']['options'] = [];
        Fixture::fake($document);
        $this->assertSame(1, $this->sync()['deactivated']);
        $this->assertFalse($item->fresh()->active);
        Fixture::fake();
        $this->sync();
        $this->assertTrue($item->fresh()->active);
        $this->assertSame($item->code, $item->fresh()->code);
        $this->assertSame(1, $this->dictionary()->items()->count());
    }

    public function test_ambiguous_legacy_match_rolls_back_even_earlier_dictionaries(): void
    {
        $tags = $this->dictionary('group_tag');
        $tags->items()->create(['code' => 'one', 'name' => 'тестовая метка']);
        $tags->items()->create(['code' => 'two', 'name' => " ТЕСТОВАЯ\tМЕТКА "]);
        Fixture::fake();
        $this->assertSyncFailsWithoutChanges();
    }

    public function test_code_collision_and_database_failure_roll_back_all_five(): void
    {
        $collision = $this->dictionary('group_tag')->items()->create([
            'code' => 'modx_'.substr(hash('sha256', '77'), 0, 56), 'name' => 'Unrelated legacy']);
        Fixture::fake();
        $this->assertSyncFailsWithoutChanges();
        $collision->delete();
        // Fail in MySQL after the last dictionary insert, without privileged DDL.
        $failInsert = true;
        DB::listen(function ($query) use (&$failInsert): void {
            if ($failInsert && str_starts_with($query->sql, 'insert into `gp_dictionary_items`')
                && in_array('77', $query->bindings, true)) {
                DB::statement("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic-modx-secret'");
            }
        });
        try {
            $this->assertSyncFailsWithoutChanges();
        } finally {
            $failInsert = false;
        }
    }

    public static function invalidDocuments(): array
    {
        return array_map(fn ($case) => [$case], ['incomplete', 'truthy', 'missing', 'tv', 'type', 'options-object', 'value-number',
            'empty-value', 'empty-label', 'long-value', 'long-label', 'position-negative', 'position-string', 'position-large',
            'duplicate', 'malformed-json', 'http', 'redirect', 'connection', 'dictionary-list']);
    }

    #[DataProvider('invalidDocuments')]
    public function test_bad_remote_response_preserves_last_successful_copy(string $case): void
    {
        Fixture::fake();
        $this->sync();
        $document = Fixture::document();
        $option = &$document['data']['dictionaries']['tags']['options'][0];
        switch ($case) {
            case 'incomplete': $document['data']['complete'] = false;
                break;
            case 'truthy': $document['data']['complete'] = 1;
                break;
            case 'missing': unset($document['data']['dictionaries']['tags']);
                break;
            case 'tv': $document['data']['dictionaries']['tags']['tv_name'] = 'wrong';
                break;
            case 'type': $document['data']['dictionaries']['tags']['type'] = 'listbox';
                break;
            case 'options-object': $document['data']['dictionaries']['tags']['options'] = (object) [];
                break;
            case 'value-number': $option['value'] = 77;
                break;
            case 'empty-value': $option['value'] = ' ';
                break;
            case 'empty-label': $option['label'] = '';
                break;
            case 'long-value': $option['value'] = str_repeat('я', 256);
                break;
            case 'long-label': $option['label'] = str_repeat('я', 256);
                break;
            case 'position-negative': $option['position'] = -1;
                break;
            case 'position-string': $option['position'] = '1';
                break;
            case 'position-large': $option['position'] = 4294967296;
                break;
            case 'duplicate': $document['data']['dictionaries']['tags']['options'][] = $option;
                break;
            case 'dictionary-list': $document['data']['dictionaries'] = array_values($document['data']['dictionaries']);
                break;
        }
        Fixture::resetHttp();
        if ($case === 'connection') {
            Http::fake(fn () => throw new ConnectionException('synthetic-modx-secret remote body'));
        } elseif (in_array($case, ['http', 'redirect', 'malformed-json'], true)) {
            Http::fake(['*' => Http::response('synthetic-modx-secret remote body', $case === 'http' ? 503 : ($case === 'redirect' ? 302 : 200))]);
        } else {
            Fixture::fake($document);
        }
        $this->assertSyncFailsWithoutChanges();
    }

    public function test_missing_configuration_and_lock_contention_never_fetch_or_mutate(): void
    {
        Fixture::fake();
        config(['services.modx.token' => '']);
        $this->assertSyncFailsWithoutChanges();
        Http::assertNothingSent();
        Fixture::configure();
        $lock = Cache::lock(ModxDictionarySyncService::LOCK, 600);
        $this->assertTrue($lock->get());
        try {
            $this->assertSyncFailsWithoutChanges();
            $this->artisan('modx:sync-dictionaries')->assertFailed();
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        $this->assertSame(5, $this->sync()['dictionaries']);
    }

    public function test_lock_is_held_during_fetch_and_released_after_failure(): void
    {
        $blocked = false;
        Http::fake(function () use (&$blocked) {
            $blocked = ! Cache::lock(ModxDictionarySyncService::LOCK, 600)->get();
            throw new ConnectionException('synthetic-modx-secret');
        });
        $this->assertSyncFailsWithoutChanges();
        $this->assertTrue($blocked);
        $lock = Cache::lock(ModxDictionarySyncService::LOCK, 600);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_database_cache_lock_blocks_reentry_and_lost_ownership_rolls_back(): void
    {
        config(['cache.default' => 'database']);
        Fixture::fake();
        $lock = Cache::lock(ModxDictionarySyncService::LOCK, 600);
        $this->assertTrue($lock->get());
        try {
            $this->assertSyncFailsWithoutChanges();
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        Fixture::resetHttp();
        Http::fake(function () {
            Cache::lock(ModxDictionarySyncService::LOCK)->forceRelease();

            return Http::response(Fixture::document());
        });
        $this->assertSyncFailsWithoutChanges();
    }

    public static function invalidConfiguration(): array
    {
        return [
            ['base_url', ''], ['base_url', 'http://modx.example.test/api/v1'],
            ['base_url', 'https://user:password@modx.example.test/api/v1'],
            ['base_url', 'https://modx.example.test/api/v1?secret=value'],
            ['token', ''], ['token', "bad\nheader"], ['timeout', 0], ['timeout', 61],
            ['timeout', 'invalid'], ['connect_timeout', 0], ['connect_timeout', 11],
        ];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_configuration_never_sends_http(string $key, mixed $value): void
    {
        Fixture::fake();
        config(['services.modx.'.$key => $value]);
        $this->assertSyncFailsWithoutChanges();
        $this->artisan('modx:sync-dictionaries')->expectsOutput('Не настроено подключение к MODX.')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_timeout_bounds_and_redirect_policy_are_passed_to_http_client(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(Fixture::document());
        });
        $this->sync();
        Http::assertSentCount(1);
    }

    public function test_cli_and_schedule_are_deterministic_and_output_is_safe(): void
    {
        Fixture::fake();
        $this->artisan('modx:sync-dictionaries')->expectsOutput('Справочников: 5; вариантов: 5; создано: 5; связано: 0; деактивировано: 0.')->assertSuccessful();
        Log::spy();
        Fixture::resetHttp();
        Http::fake(fn () => throw new ConnectionException('synthetic-modx-secret remote body'));
        $this->artisan('modx:sync-dictionaries')->expectsOutput('Не удалось подключиться к MODX.')->assertFailed();
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'modx:sync-dictionaries'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_admin_sync_access_feedback_and_managed_views(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('admin', true)->firstOrFail();
        $owner = User::where('admin', false)->firstOrFail();
        Fixture::fake();
        $url = '/admin/dictionaries/sync-modx';
        $this->post($url)->assertRedirect(route('login'));
        $this->actingAs($owner)->post($url)->assertForbidden();
        Http::assertNothingSent();
        $this->actingAs($admin)->post($url)->assertRedirect('/admin/dictionaries')->assertSessionHas('success', 'Обновлено справочников: 5; вариантов: 5.');
        $this->get('/admin/dictionaries')->assertOk()->assertSee('Обновить из MODX')->assertSee('Управляется MODX');
        $dictionary = $this->dictionary();
        $item = $dictionary->items()->firstOrFail();
        $this->get('/admin/dictionaries/'.$dictionary->id.'/items')->assertOk()->assertSee('Офлайн')
            ->assertSee('Последняя синхронизация')->assertDontSee('Добавить элемент')->assertDontSee('Редактировать')->assertDontSee('Деактивировать');
        $this->get('/admin/dictionaries/'.$dictionary->id.'/items/'.$item->id.'/edit')->assertForbidden();
        Log::spy();
        Fixture::resetHttp();
        Http::fake(['*' => Http::response('synthetic-modx-secret remote body', 500)]);
        $this->post($url)->assertSessionHasErrors(['sync' => 'MODX вернул ошибку HTTP.']);
        $this->get('/admin/dictionaries')->assertDontSee('synthetic-modx-secret')->assertDontSee('remote body')->assertSee('MODX вернул ошибку HTTP.');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $admin->update(['disabled' => true]);
        $this->post($url)->assertRedirect(route('login'));
    }

    public function test_managed_item_mutations_are_blocked_at_service_and_http_boundaries(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('admin', true)->firstOrFail());
        Fixture::fake();
        $this->sync();
        $before = $this->snapshot();
        foreach (Dictionary::whereNotNull('modx_tv_name')->get() as $dictionary) {
            $item = $dictionary->items()->firstOrFail();
            $base = '/admin/dictionaries/'.$dictionary->id;
            $this->post($base.'/items', ['code' => 'manual', 'name' => 'Manual', 'sort_order' => 0])->assertSessionHasErrors('item');
            $this->put($base.'/items/'.$item->id, ['name' => 'Manual', 'sort_order' => 0])->assertSessionHasErrors('item');
            foreach (['activate', 'deactivate'] as $action) {
                $this->post($base.'/items/'.$item->id.'/'.$action, ['confirmed' => 1])->assertSessionHasErrors('item');
            }
            $this->delete($base.'/items/'.$item->id, ['confirmed' => 1])->assertSessionHasErrors('item');
            $this->delete($base, ['confirmed' => 1])->assertSessionHasErrors('dictionary');
            $management = app(DictionaryManagement::class);
            foreach ([fn () => $management->saveItem($dictionary, null, ['code' => 'manual', 'name' => 'Manual']),
                fn () => $management->saveItem($dictionary, $item, ['active' => false, 'confirmed' => true]),
                fn () => $management->deleteItem($dictionary, $item)] as $mutation) {
                try {
                    $mutation();
                    $this->fail('Expected managed item rejection.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('item', $exception->errors());
                }
            }
        }
        $this->assertSame($before, $this->snapshot());
    }
}
