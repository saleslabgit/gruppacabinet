<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupApplication;
use App\Models\Payment;
use App\Models\User;
use App\Services\GroupWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\GroupContentFixture as Fixture;
use Tests\TestCase;

class CabinetCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_pages_keep_product_states_without_technical_diagnostics(): void
    {
        URL::forceRootUrl('http://localhost');
        $owner = User::create(['email' => 'copy@example.test', 'status' => 'approved', 'free' => true]);
        $admin = User::create(['email' => 'copy-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $this->seed(DatabaseSeeder::class);
        $group = Group::create(['owner_id' => $owner->id, 'title' => 'Synthetic group', 'status' => 'expired',
            'public_site_resource_id' => 432, 'published_at' => now()->subDays(31), 'expires_at' => now()->subDay(), 'placement_days' => 30]);
        $application = GroupApplication::factory()->for($group)->create();
        $payment = Payment::create(['owner_id' => $owner->id, 'group_id' => $group->id, 'order_number' => 'copy-pending',
            'type' => 'extension', 'status' => 'pending', 'amount' => 100]);
        $this->actingAs($owner);
        foreach (['/', '/profile', '/feedback', '/payments/'.$payment->id, '/groups/'.$group->id,
            '/groups/'.$group->id.'/applications', '/groups/'.$group->id.'/applications/'.$application->id] as $path) {
            $this->assertPlainCopy($this->get($path)->assertOk()->getContent());
        }
        $this->get('/payments/'.$payment->id)->assertSee('Ожидается подтверждение оплаты. Не повторяйте оплату, пока статус не обновится.');
        $payment->update(['status' => 'cancelled']);
        $this->get('/groups/'.$group->id.'/extension')->assertOk()
            ->assertSee('Если группа уже публиковалась, после продления она будет опубликована автоматически.')
            ->assertDontSee('Синхронизированная');
        $group->update(['status' => 'awaiting_payment']);
        $this->get('/groups/'.$group->id)->assertOk()->assertSee('подтверждения оплаты')->assertDontSee('доверенного');
        $group->update(['status' => 'draft']);
        $this->assertPlainCopy($this->get('/groups/'.$group->id.'/edit')->assertOk()->getContent());
        $group->update(['status' => 'paused', 'expires_at' => now()->addDay(), 'modx_publication_desired' => 'published']);
        foreach (['pending', 'syncing', 'failed', 'conflict'] as $state) {
            $group->update(['modx_publication_status' => $state, 'modx_publication_error_code' => 'resource_id_conflict']);
            $response = $this->get('/groups/'.$group->id)->assertOk();
            $this->assertPlainCopy($response->getContent());
            $response->assertSee(match ($state) {
                'pending', 'syncing' => 'Возобновление публикации выполняется.',
                'failed' => 'Не удалось изменить публикацию.',
                'conflict' => 'Требуется проверка публикации администратором.',
            });
        }
        $this->actingAs($admin)->get('/admin/groups/'.$group->id)->assertOk()
            ->assertSee('MODX Resource ID')->assertSee('resource_id_conflict');
    }

    public function test_owner_submission_validation_is_plain_and_admin_validation_stays_technical(): void
    {
        URL::forceRootUrl('http://localhost');
        Queue::fake();
        Storage::fake('local');
        $owner = User::create(['email' => 'validation-copy@example.test', 'first_name' => 'Synthetic', 'last_name' => 'Owner', 'status' => 'approved', 'free' => true]);
        $admin = User::create(['email' => 'validation-copy-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $fields = Fixture::fields() + ['title' => 'Synthetic group', 'description' => 'Synthetic description', 'meeting_price' => 1200,
            'meeting_duration_minutes' => 90, 'participant_capacity' => 8,
            'format_id' => Fixture::item('group_format')->id, 'gender_id' => Fixture::item('gender')->id];
        $group = app(GroupWorkflow::class)->create($owner, $admin, $fields);
        $group->update(['meeting_price' => 1201]);
        $group->format->update(['modx_value' => null]);
        $expected = ['meeting_price' => 'Укажите стоимость в целых BYN, без копеек.',
            'format_id' => 'Формат: выберите доступный вариант. Если нужного варианта нет, обратитесь к администратору.'];
        $this->actingAs($owner)->from('/groups/'.$group->id)->post('/groups/'.$group->id.'/submit-stored', ['confirmed' => '1'])
            ->assertRedirect('/groups/'.$group->id)->assertSessionHasErrors($expected);
        $this->assertPlainCopy($this->get('/groups/'.$group->id)->assertOk()->getContent());
        $fields['meeting_price'] = '12.01';
        unset($fields['cover']);
        $this->from('/groups/'.$group->id.'/edit')->post('/groups/'.$group->id.'/submit', $fields)->assertSessionHasErrors($expected);
        $this->assertPlainCopy($this->get('/groups/'.$group->id.'/edit')->assertOk()->getContent());
        $this->assertSame('draft', $group->fresh()->status->value);
        $group->update(['status' => 'moderation']);
        $this->actingAs($admin)->from('/admin/groups/'.$group->id)->post('/admin/groups/'.$group->id.'/approve', ['confirmed' => '1'])
            ->assertSessionHasErrors(['meeting_price' => 'Для синхронизации укажите стоимость в целых BYN, без копеек.',
                'format_id' => 'Формат: выберите доступное значение справочника, связанное с MODX.']);
        Queue::assertNothingPushed();
    }

    public function test_feedback_navigation_and_prototype_messages(): void
    {
        URL::forceRootUrl('http://localhost');
        $owner = User::create(['email' => 'icon-copy@example.test', 'status' => 'approved']);
        $html = $this->actingAs($owner)->get('/feedback')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<a[^>]+href="http://localhost/feedback"[^>]*><i class="bi bi-bug" aria-hidden="true"></i>\s*<span class="control-label">Сообщить об ошибке</span>~', $html);
        foreach (['success' => 'Сообщение принято. Спасибо за обратную связь.', 'error' => 'Не удалось отправить сообщение. Попробуйте ещё раз позже.'] as $state => $message) {
            $this->get('/_prototype/feedback/'.$state)->assertOk()->assertSee($message)->assertDontSee('очеред');
        }
    }

    private function assertPlainCopy(string $html): void
    {
        $text = html_entity_decode(strip_tags($html));
        $this->assertDoesNotMatchRegularExpression('/очеред|синхрониз|доверенн|MODX|Resource ID|ревизи|revision|финансовый результат|браузер|resource_id_conflict|queue_unavailable|worker_failed/ui', $text);
    }
}
