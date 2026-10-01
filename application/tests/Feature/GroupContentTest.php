<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\User;
use App\Services\DictionaryUsage;
use App\Services\GroupStatusTransitionService;
use App\Services\GroupWorkflow;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GroupContentFixture as Fixture;
use Tests\TestCase;

class GroupContentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Group $group;

    private array $fields;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('local');
        URL::forceRootUrl('http://localhost');
        $this->owner = User::create(['email' => 'content@example.test', 'first_name' => 'Synthetic', 'last_name' => 'Owner', 'status' => 'approved', 'free' => true]);
        $this->admin = User::create(['email' => 'admin-content@example.test', 'status' => 'approved', 'admin' => true]);
        $this->group = app(GroupWorkflow::class)->create($this->owner, $this->owner);
        $this->fields = Fixture::fields() + ['title' => 'Content group', 'description' => '<script>Short plain text</script>',
            'format_id' => Fixture::item('group_format')->id, 'gender_id' => Fixture::item('gender')->id,
            'meeting_duration_minutes' => 90, 'participant_capacity' => 8, 'meeting_price' => '35.00'];
    }

    public function test_content_is_sanitized_normalized_rendered_and_persisted_without_http(): void
    {
        $this->group->update(['schedule' => 'Legacy arbitrary text']);
        $fields = array_replace($this->fields, ['full_description_html' => '<p class="evil" onclick="bad()">Full <b>text</b><script>attack</script></p>', 'schedule' => 'Do not overwrite']);
        $this->actingAs($this->owner)->put('/groups/'.$this->group->id, $fields)->assertSessionHasNoErrors();
        $group = $this->group->fresh();
        $this->assertSame('<p>Full <strong>text</strong></p>', $group->full_description_html);
        $this->assertSame(['mon', 'wed'], $group->meeting_days);
        $this->assertSame('19:00', $group->start_time);
        $this->assertSame('Минск', $group->city);
        $this->assertSame('Еженедельно', $group->frequency);
        $this->assertSame('Legacy arbitrary text', $group->schedule);
        $this->assertSame($this->fields['group_type_id'], $group->groupType->id);
        $this->assertSame($this->fields['approach_ids'], $group->approaches->modelKeys());
        $this->assertSame($this->fields['tag_ids'], $group->tags->modelKeys());
        $this->assertIsInt($group->cover_size);
        $this->get('/groups/'.$group->id)->assertOk()->assertSee('<p>Full <strong>text</strong></p>', false)
            ->assertSee('&lt;script&gt;Short plain text&lt;/script&gt;', false)->assertSee('Понедельник')->assertSee('Минск');
        $this->get('/groups/'.$group->id.'/edit')->assertOk()->assertSee('data-rich-editor', false)->assertSee('data-multi-select', false)
            ->assertSee('name="full_description_html"', false)->assertSee('name="approach_ids[]"', false)
            ->assertSee('name="tag_ids[]"', false)->assertSee(' multiple', false)
            ->assertDontSee('name="schedule"', false)->assertDontSee('name="leader"', false)->assertSee('multipart/form-data');
        Http::assertNothingSent();
    }

    public static function invalidFields(): array
    {
        return [
            [['meeting_days' => ['wed', 'mon', 'wed']], 'meeting_days.0'],
            [['meeting_days' => ['monday']], 'meeting_days.0'],
            [['start_time' => '9:00'], 'start_time'], [['start_time' => '24:00'], 'start_time'],
            [['start_time' => '12:60'], 'start_time'], [['start_time' => '12:00:00'], 'start_time'],
            [['frequency' => '  '], 'frequency'], [['city' => '  '], 'city'],
            [['city' => str_repeat('a', 256)], 'city'], [['full_description_html' => '<p><br></p>'], 'full_description_html'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_structured_content_is_rejected(array $changes, string $error): void
    {
        $this->actingAs($this->owner)->post('/groups/'.$this->group->id.'/submit', array_replace($this->fields, $changes))->assertSessionHasErrors($error);
        $this->assertSame(GroupStatus::Draft, $this->group->fresh()->status);
        $this->assertNull($this->group->fresh()->title);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_dictionary_ownership_active_rules_duplicates_and_retained_inactive_values(): void
    {
        foreach (['group_type_id' => 'group_type', 'approach_ids' => 'group_approach', 'tag_ids' => 'group_tag'] as $field => $code) {
            foreach ([$this->fields['format_id'], Fixture::item($code, false)->id, 'not-integer'] as $id) {
                $value = $field === 'group_type_id' ? $id : [$id];
                $this->actingAs($this->owner)->put('/groups/'.$this->group->id, array_replace($this->fields, [$field => $value]))->assertSessionHasErrors($field === 'group_type_id' ? $field : $field.'.0');
            }
        }
        foreach (['approach_ids', 'tag_ids'] as $field) {
            $this->put('/groups/'.$this->group->id, array_replace($this->fields, [$field => [$this->fields[$field][0], $this->fields[$field][0]]]))->assertSessionHasErrors($field.'.0');
        }
        $this->put('/groups/'.$this->group->id, $this->fields)->assertSessionHasNoErrors();
        $group = $this->group->fresh();
        foreach ([$group->groupType, $group->approaches->sole(), $group->tags->sole()] as $item) {
            $item->update(['active' => false]);
        }
        $this->get('/groups/'.$group->id.'/edit')->assertOk()->assertSee('неактив');
        $this->put('/groups/'.$group->id, $this->fields)->assertSessionHasNoErrors();
        $group->delete();
        foreach ([$group->groupType, $group->approaches->sole(), $group->tags->sole()] as $item) {
            $this->assertTrue(app(DictionaryUsage::class)->used($item->dictionary, $item));
        }
    }

    public function test_submit_and_admin_create_require_every_new_field_but_legacy_updates_preserve_data(): void
    {
        foreach (['full_description_html', 'meeting_days', 'start_time', 'frequency', 'city', 'group_type_id', 'approach_ids', 'tag_ids', 'cover'] as $field) {
            $fields = $this->fields;
            unset($fields[$field]);
            $this->actingAs($this->owner)->post('/groups/'.$this->group->id.'/submit', $fields)->assertSessionHasErrors($field);
            $this->actingAs($this->admin)->post('/admin/groups', $fields + ['owner_id' => $this->owner->id])->assertSessionHasErrors($field);
        }
        $legacy = array_diff_key($this->fields, array_flip(['full_description_html', 'meeting_days', 'start_time', 'frequency', 'city', 'group_type_id', 'approach_ids', 'tag_ids', 'cover']));
        $this->group->update(['schedule' => 'Every other Tuesday, maybe']);
        $this->actingAs($this->admin)->put('/admin/groups/'.$this->group->id, $legacy)->assertSessionHasNoErrors();
        $this->get('/admin/groups/'.$this->group->id)->assertOk()->assertSee('Every other Tuesday, maybe');
        $this->put('/admin/groups/'.$this->group->id, $this->fields)->assertSessionHasNoErrors();
        $before = $this->group->fresh();
        $this->put('/admin/groups/'.$this->group->id, $legacy)->assertSessionHasNoErrors();
        $this->assertSame($before->full_description_html, $this->group->fresh()->full_description_html);
        $this->assertSame($this->fields['approach_ids'], $this->group->fresh()->approaches->modelKeys());
        $this->assertSame($this->fields['tag_ids'], $this->group->fresh()->tags->modelKeys());
        $this->post('/admin/groups', $this->fields + ['owner_id' => $this->owner->id])->assertSessionHasNoErrors();
        $fields = $this->fields;
        unset($fields['cover']);
        $this->actingAs($this->owner)->post('/groups/'.$this->group->id.'/submit', $fields)->assertSessionHasNoErrors();
        $this->assertSame(GroupStatus::Moderation, $this->group->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_multiple_selections_exact_sync_empty_sentinel_and_old_input(): void
    {
        $fields = $this->fields;
        $fields['approach_ids'][] = Fixture::item('group_approach')->id;
        $fields['tag_ids'][] = Fixture::item('group_tag')->id;
        $this->actingAs($this->owner)->put('/groups/'.$this->group->id, $fields)->assertSessionHasNoErrors();
        $this->assertCount(2, $this->group->fresh()->approaches);
        $this->assertCount(2, $this->group->fresh()->tags);
        $this->from('/groups/'.$this->group->id.'/edit')->put('/groups/'.$this->group->id, array_replace($fields, ['title' => '']))->assertSessionHasErrors('title')->assertSessionHasInput('tag_ids', $fields['tag_ids']);
        $this->get('/groups/'.$this->group->id.'/edit')->assertOk()->assertSee('Synthetic group_tag');
        $this->put('/groups/'.$this->group->id, array_replace($fields, ['approach_ids' => '', 'tag_ids' => '', 'meeting_days' => '']))->assertSessionHasNoErrors();
        $this->assertCount(0, $this->group->fresh()->approaches);
        $this->assertCount(0, $this->group->fresh()->tags);
        $this->assertSame([], $this->group->fresh()->meeting_days);
    }

    public function test_failed_transition_rolls_back_scalars_pivots_history_and_new_cover(): void
    {
        app(GroupWorkflow::class)->save($this->group, $this->owner, $this->fields);
        $before = $this->group->fresh()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $transitions = \Mockery::mock(GroupStatusTransitionService::class);
        $transitions->shouldReceive('transition')->once()->andThrow(new \RuntimeException('Synthetic transition failure'));
        $workflow = new GroupWorkflow($transitions, app(SettingService::class));
        try {
            $workflow->save($this->group, $this->owner, array_replace($this->fields, ['title' => 'Rollback', 'meeting_price' => 3500,
                'approach_ids' => [Fixture::item('group_approach')->id], 'tag_ids' => [Fixture::item('group_tag')->id]]), true);
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic transition failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->group->fresh()->getAttributes());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($this->fields['approach_ids'], $this->group->fresh()->approaches->modelKeys());
        $this->assertSame($this->fields['tag_ids'], $this->group->fresh()->tags->modelKeys());
        $this->assertSame(1, $this->group->statusHistory()->count());
    }
}
