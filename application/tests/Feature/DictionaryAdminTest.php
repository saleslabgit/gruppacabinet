<?php

namespace Tests\Feature;

use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DictionaryAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('admin', true)->firstOrFail();
        $this->owner = User::where('admin', false)->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function dictionary(string $code = 'group_format'): Dictionary
    {
        return Dictionary::where('code', $code)->firstOrFail();
    }

    private function item(Dictionary $dictionary, string $code = 'sample'): DictionaryItem
    {
        $this->post('/admin/dictionaries/'.$dictionary->id.'/items', ['code' => $code, 'name' => 'Local '.$dictionary->code.' '.$code, 'sort_order' => 10])->assertSessionHasNoErrors();

        return $dictionary->items()->where('code', $code)->firstOrFail();
    }

    public function test_containers_validation_immutable_codes_and_confirmed_deletion(): void
    {
        $this->post('/admin/dictionaries', ['code' => 'custom', 'name' => 'Local custom', 'id' => 99999])->assertSessionHasNoErrors();
        $dictionary = $this->dictionary('custom');
        $this->assertNotSame(99999, $dictionary->id);
        foreach (['UPPER', 'with-space ', 'dash-code', 'custom', str_repeat('a', 65)] as $code) {
            $this->post('/admin/dictionaries', ['code' => $code, 'name' => 'Invalid'])->assertSessionHasErrors('code');
        }
        $this->put('/admin/dictionaries/'.$dictionary->id, ['code' => 'changed', 'name' => 'Changed'])->assertSessionHasErrors('code');
        $this->put('/admin/dictionaries/'.$dictionary->id, ['name' => 'Renamed', 'id' => 99999])->assertSessionHasNoErrors();
        $this->get('/admin/dictionaries/'.$dictionary->id.'/edit')->assertOk()->assertViewIs('admin.dictionaries.index')->assertSee('Renamed');
        $this->assertSame('custom', $dictionary->fresh()->code);
        $this->delete('/admin/dictionaries/'.$dictionary->id)->assertSessionHasErrors('confirmed');
        $item = $this->item($dictionary);
        $this->delete('/admin/dictionaries/'.$dictionary->id, ['confirmed' => 1])->assertSessionHasErrors('dictionary');
        $this->delete('/admin/dictionaries/'.$dictionary->id.'/items/'.$item->id, ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->delete('/admin/dictionaries/'.$dictionary->id, ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertModelMissing($dictionary);
        foreach (['education_type', 'group_format', 'gender'] as $code) {
            $this->delete('/admin/dictionaries/'.$this->dictionary($code)->id, ['confirmed' => 1])->assertSessionHasErrors('dictionary');
        }
    }

    public function test_items_validation_lifecycle_and_scoped_ownership(): void
    {
        $dictionary = $this->dictionary();
        $item = $this->item($dictionary);
        $other = $this->dictionary('gender');
        $this->item($other);
        $base = '/admin/dictionaries/'.$dictionary->id.'/items';
        $this->assertTrue($item->active);
        $this->post($base, ['code' => 'sample', 'name' => 'Duplicate', 'sort_order' => 0])->assertSessionHasErrors('code');
        $this->post($base, ['code' => 'UPPER', 'name' => 'Invalid', 'sort_order' => -1])->assertSessionHasErrors(['code', 'sort_order']);
        $this->put($base.'/'.$item->id, ['code' => 'forged', 'name' => 'Changed', 'sort_order' => 2])->assertSessionHasErrors('code');
        $this->put($base.'/'.$item->id, ['name' => 'Changed', 'sort_order' => 2, 'dictionary_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($dictionary->id, $item->fresh()->dictionary_id);
        $this->assertSame('sample', $item->fresh()->code);
        $this->assertSame('Changed', $item->fresh()->name);
        $this->assertSame(2, $item->fresh()->sort_order);
        $this->put($base.'/'.$item->id, ['name' => 'Changed', 'sort_order' => 2, 'active' => 0])->assertSessionHasErrors('active');
        $this->assertTrue($item->fresh()->active);
        $this->post($base.'/'.$item->id.'/deactivate')->assertSessionHasErrors('confirmed');
        for ($i = 0; $i < 2; $i++) {
            $this->post($base.'/'.$item->id.'/deactivate', ['confirmed' => 1])->assertSessionHasNoErrors();
            $this->assertFalse($item->fresh()->active);
        }
        $this->post($base.'/'.$item->id.'/activate')->assertSessionHasNoErrors();
        $this->assertTrue($item->fresh()->active);
        $foreign = '/admin/dictionaries/'.$other->id.'/items/'.$item->id;
        $this->get($foreign.'/edit')->assertNotFound();
        $this->put($foreign, ['name' => 'Forged', 'sort_order' => 0])->assertNotFound();
        foreach (['activate', 'deactivate'] as $action) {
            $this->post($foreign.'/'.$action, ['confirmed' => 1])->assertNotFound();
        }
        $this->delete($foreign, ['confirmed' => 1])->assertNotFound();
        $this->delete($base.'/'.$item->id)->assertSessionHasErrors('confirmed');
        $this->delete($base.'/'.$item->id, ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertModelMissing($item);
    }

    public function test_mutations_immediately_change_forms_and_preserve_historical_references(): void
    {
        $items = [];
        foreach (['education_type', 'group_format', 'gender'] as $code) {
            $items[$code] = $this->item($this->dictionary($code));
        }
        $this->owner->update(['education_type_id' => $items['education_type']->id]);
        $group = Group::create(['owner_id' => $this->owner->id, 'format_id' => $items['group_format']->id, 'gender_id' => $items['gender']->id]);
        foreach ($items as $code => $item) {
            $create = $code === 'education_type' ? '/admin/psychologists/create' : '/admin/groups/create';
            $edit = $code === 'education_type' ? '/admin/psychologists/'.$this->owner->id.'/edit' : '/admin/groups/'.$group->id.'/edit';
            $detail = $code === 'education_type' ? '/admin/psychologists/'.$this->owner->id : '/admin/groups/'.$group->id;
            $base = '/admin/dictionaries/'.$item->dictionary_id.'/items/'.$item->id;
            $this->get($create)->assertOk()->assertSee($item->name);
            $this->post($base.'/deactivate', ['confirmed' => 1])->assertSessionHasNoErrors();
            $this->get($create)->assertOk()->assertDontSee($item->name);
            $this->get($edit)->assertOk()->assertSee($item->name)->assertSee('неактивен');
            $this->get($detail)->assertOk()->assertSee($item->name);
            if ($code === 'education_type') {
                $this->put('/admin/psychologists/'.$this->owner->id, ['email' => $this->owner->email, 'education_type_id' => $item->id])->assertSessionHasNoErrors();
                $this->assertSame($item->id, $this->owner->fresh()->education_type_id);
            } else {
                $this->put('/admin/groups/'.$group->id, ['title' => 'Local group', 'description' => 'Local description', 'schedule' => 'Local schedule',
                    'format_id' => $items['group_format']->id, 'gender_id' => $items['gender']->id, 'meeting_duration_minutes' => 60,
                    'participant_capacity' => 10, 'meeting_price' => '0'])->assertSessionHasNoErrors();
                $this->assertSame($item->id, $group->fresh()->getAttribute($code === 'gender' ? 'gender_id' : 'format_id'));
            }
            $this->delete($base, ['confirmed' => 1])->assertSessionHasErrors('item');
            $this->post($base.'/activate')->assertSessionHasNoErrors();
            $this->get($create)->assertOk()->assertSee($item->name);
        }
        $group->delete();
        $this->owner->delete();
        foreach ($items as $item) {
            $this->delete('/admin/dictionaries/'.$item->dictionary_id.'/items/'.$item->id, ['confirmed' => 1])->assertSessionHasErrors('item');
            $this->assertModelExists($item);
        }
    }

    public function test_lists_have_counts_order_pagination_and_constant_query_count(): void
    {
        $dictionary = $this->dictionary();
        $this->item($dictionary, 'first');
        $measure = function (string $url): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get($url)->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            return [$response, count($queries)];
        };
        [$before, $itemCount] = $measure('/admin/dictionaries/'.$dictionary->id.'/items');
        [$before, $dictionaryCount] = $measure('/admin/dictionaries');
        for ($i = 0; $i < 25; $i++) {
            Dictionary::create(['code' => 'custom_'.$i, 'name' => 'Custom '.$i]);
            $dictionary->items()->create(['code' => 'code_'.$i, 'name' => 'Name '.$i, 'sort_order' => $i, 'active' => $i % 2 === 0]);
        }
        [$response, $after] = $measure('/admin/dictionaries/'.$dictionary->id.'/items');
        $this->assertSame($itemCount, $after);
        $rows = $response->viewData('items');
        $this->assertCount(20, $rows);
        $this->assertSame('code_0', $rows->first()->code);
        $this->get('/admin/dictionaries/'.$dictionary->id.'/items?page=2')->assertOk()->assertViewHas('items', fn ($rows) => $rows->count() === 6);
        [$response, $after] = $measure('/admin/dictionaries');
        $this->assertSame($dictionaryCount, $after);
        $this->assertCount(20, $response->viewData('dictionaries'));
        $this->get('/admin/dictionaries?page=2')->assertOk()->assertViewHas('dictionaries', fn ($rows) => $rows->count() === 8);
    }

    public function test_all_stage_eight_routes_deny_psychologist(): void
    {
        $dictionary = $this->dictionary();
        $item = $this->item($dictionary);
        $this->actingAs($this->owner);
        foreach (app('router')->getRoutes() as $route) {
            if (! preg_match('/^admin\\.(dictionaries|settings|payments)\\./', $route->getName() ?? '')) {
                continue;
            }
            $path = '/'.str_replace(['{dictionary}', '{item}'], [$dictionary->id, $item->id], $route->uri());
            $this->call($route->methods()[0], $path, ['confirmed' => 1])->assertForbidden();
        }
    }

    public function test_empty_state_and_revoked_admin_access(): void
    {
        $dictionary = $this->dictionary();
        $this->get('/admin/dictionaries/'.$dictionary->id.'/items')->assertOk()->assertSee('Элементов пока нет');
        foreach (['disabled', 'rejected', 'deleted'] as $state) {
            $actor = User::create(['email' => $state.'@example.test', 'status' => 'approved', 'admin' => true]);
            $this->actingAs($actor);
            if ($state === 'deleted') {
                $actor->delete();
            } else {
                $actor->update($state === 'disabled' ? ['disabled' => true] : ['status' => 'rejected']);
            }
            $this->get('/admin/settings')->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $this->get('/admin/dictionaries')->assertRedirect(route('login'));
    }
}
