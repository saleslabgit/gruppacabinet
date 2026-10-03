<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupApplication;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CabinetImprovementsUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_admin_group_profile_and_history_presentation(): void
    {
        URL::forceRootUrl('http://localhost');
        $owner = User::create(['email' => 'ui-owner@example.test', 'status' => 'approved', 'free' => true]);
        $admin = User::create(['email' => 'ui-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $group = Group::create(['owner_id' => $owner->id, 'title' => 'Synthetic group', 'description' => 'Synthetic description', 'status' => 'moderation']);
        $group->statusHistory()->create(['from_status' => 'draft', 'to_status' => 'moderation', 'actor_type' => 'system']);
        GroupApplication::factory()->for($group)->create();
        $this->actingAs($owner)->get('/')->assertOk()->assertDontSee('Группа №')->assertDontSee('<dt>Формат</dt>', false);
        $this->get('/groups/'.$group->id)->assertOk()->assertSee('<dt>Формат</dt>', false)
            ->assertSee('Администратор проверяет группу. Дождитесь решения.')
            ->assertSeeInOrder(['Заявки участников', 'Краткое описание', 'История статусов и замечаний']);
        $this->get('/profile')->assertOk()->assertDontSee('Подтверждения и согласие');
        $this->actingAs($admin)->get('/admin/psychologists/'.$owner->id)->assertOk()->assertSee('Подтверждения и согласие');
        $response = $this->get('/admin/groups/'.$group->id)->assertOk()->assertDontSee('Администратор проверяет группу. Дождитесь решения.')
            ->assertSeeInOrder(['Оплата размещения', 'Заявки участников', 'История статусов и замечаний']);
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, '</header>'), strpos($html, 'id="admin-menu-toggle"'));
        $this->assertSame(1, substr_count($html, 'id="admin-navigation"'));
        $this->assertStringContainsString('class="sidebar"', $html);
        $this->assertStringContainsString('aria-controls="admin-navigation"', $html);
        $this->assertStringContainsString('Выход', $html);
    }

    public function test_trusted_terminal_payment_pages_have_retry_without_pending_actions_or_provider_brand(): void
    {
        URL::forceRootUrl('http://localhost');
        $owner = User::create(['email' => 'ui-payment@example.test', 'status' => 'approved']);
        $admin = User::create(['email' => 'ui-payment-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $group = Group::create(['owner_id' => $owner->id, 'status' => 'awaiting_payment']);
        foreach (['failed', 'cancelled', 'pending'] as $status) {
            $payment = Payment::create(['owner_id' => $owner->id, 'group_id' => $group->id, 'order_number' => 'synthetic-'.$status, 'type' => 'placement', 'status' => $status, 'amount' => 100]);
            $response = $this->actingAs($owner)->get('/payments/'.$payment->id)->assertOk()->assertDontSee('WEBPAY');
            if ($status === 'pending') {
                $response->assertSee('Оплата подтверждается')->assertSee('Продолжить эту оплату')->assertDontSee('Повторить оплату');
            } else {
                $response->assertSee($status === 'failed' ? 'Оплата не прошла' : 'Отмена оплаты подтверждена')->assertSee('Повторить оплату')
                    ->assertDontSee('Обновить страницу')->assertDontSee('Продолжить эту оплату')->assertDontSee('Оплата подтверждается');
            }
            $this->actingAs($admin)->get('/admin/payments/'.$payment->id)->assertOk()->assertDontSee('WEBPAY');
        }
    }
}
