<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WebpayFixture;
use Tests\TestCase;

class PaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        WebpayFixture::configure();
        Mail::fake();
        Queue::fake();
        $this->owner = User::create(['email' => 'status@example.test', 'status' => 'approved']);
        $group = Group::create(['owner_id' => $this->owner->id, 'status' => 'awaiting_payment', 'placement_days' => 30]);
        $this->payment = Payment::create(['owner_id' => $this->owner->id, 'group_id' => $group->id,
            'type' => 'placement', 'order_number' => 'GP-'.str_repeat('a', 32), 'amount' => 5001,
            'currency' => 'BYN', 'status' => 'pending', 'started_at' => now()->subHour()]);
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], ['created', 'pending', 'succeeded', 'failed', 'cancelled', 'refunded']);
    }

    #[DataProvider('statuses')]
    public function test_status_is_exact_private_local_json_without_side_effects(string $status): void
    {
        $this->payment->update(['status' => $status, 'transaction_id' => '987654',
            'provider_order_id' => '123456', 'binding_verified_at' => now()->subHour()]);
        $this->mock(PaymentRecovery::class, fn ($mock) => $mock->shouldNotReceive('check'));
        $tables = ['gp_payments', 'gp_groups', 'gp_group_status_history', 'gp_payment_notifications', 'jobs'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->actingAs($this->owner);
        for ($i = 0; $i < 3; $i++) {
            $response = $this->getJson('/payments/'.$this->payment->id.'/status?status=succeeded&wsb_tid=untrusted');
            $response->assertOk()->assertExactJson(['status' => $status])->assertHeader('Content-Type', 'application/json');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson());
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_status_denies_guest_other_owner_admin_and_missing_payment(): void
    {
        $url = '/payments/'.$this->payment->id.'/status';
        $this->getJson($url)->assertUnauthorized();
        $other = User::create(['email' => 'status-other@example.test', 'status' => 'approved']);
        $this->actingAs($other)->getJson($url)->assertNotFound();
        $other->update(['admin' => true]);
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $this->actingAs($this->owner)->getJson('/payments/999999/status')->assertNotFound();
        Http::assertNothingSent();
    }

    public static function revokedAccounts(): array
    {
        return [['disabled'], ['pending'], ['rejected'], ['deleted']];
    }

    #[DataProvider('revokedAccounts')]
    public function test_status_preserves_revoked_account_redirect(string $state): void
    {
        $this->actingAs($this->owner);
        if ($state === 'deleted') {
            $this->owner->delete();
        } else {
            $this->owner->update($state === 'disabled' ? ['disabled' => true] : ['status' => $state]);
        }
        $this->getJson('/payments/'.$this->payment->id.'/status')->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    #[DataProvider('statuses')]
    public function test_owner_presentation_polls_only_pending_and_discloses_order(string $status): void
    {
        $this->payment->update(['status' => $status]);
        $response = $this->actingAs($this->owner)->get('/payments/'.$this->payment->id)->assertOk();
        if ($status === 'pending') {
            $response->assertSee('data-payment-status-poll')->assertSee('Обновить страницу')->assertSee('Продолжить эту оплату');
        } else {
            $response->assertDontSee('data-payment-status-poll');
        }
        if ($status !== 'created') {
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($document);
            $details = $xpath->query('//details[summary="Детали платежа"]');
            $this->assertSame(1, $details->length);
            $this->assertFalse($details->item(0)->hasAttribute('open'));
            $this->assertSame($this->payment->order_number, $xpath->query('.//dd', $details->item(0))->item(0)->textContent);
            $this->assertSame(1, substr_count($response->getContent(), $this->payment->order_number));
            $this->assertSame(1, $xpath->query('//dl[not(ancestor::details)]//dt[contains(text(), "Сумма")]')->length);
            $this->assertSame(0, $xpath->query('//dl[not(ancestor::details)]//dt[text()="Номер заказа"]')->length);
        } else {
            $this->post('/payments/'.$this->payment->id.'/start')->assertOk()->assertDontSee('data-payment-status-poll');
        }
    }

    public function test_pending_show_return_cancel_use_https_canonical_urls_and_admin_stays_unchanged(): void
    {
        URL::forceRootUrl('https://gruppa.info/cabinet');
        URL::forceScheme('https');
        $path = '/payments/'.$this->payment->id;
        foreach (['', '/return', '/cancel'] as $suffix) {
            $this->actingAs($this->owner)->get('http://localhost'.$path.$suffix)->assertOk()
                ->assertSee('data-status-url="https://gruppa.info/cabinet'.$path.'/status"', false)
                ->assertSee('data-show-url="https://gruppa.info/cabinet'.$path.'"', false);
        }
        $admin = User::create(['email' => 'status-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $this->actingAs($admin)->get('http://localhost/admin/payments/'.$this->payment->id)->assertOk()
            ->assertSee($this->payment->order_number)->assertDontSee('data-payment-status-poll')->assertDontSee('<details', false);
        Http::assertNothingSent();
    }

    public function test_order_is_escaped_inside_native_disclosure(): void
    {
        $this->payment->update(['order_number' => 'GP-'.str_repeat('b', 32).'<support>&']);
        $this->actingAs($this->owner)->get('/payments/'.$this->payment->id)->assertOk()
            ->assertSee($this->payment->order_number)->assertDontSee('<support>', false);
    }

    public static function paidEffects(): array
    {
        return [['placement', 'awaiting_payment', 'Заполнить группу', GroupStatus::Draft],
            ['extension', 'active', 'К группе', GroupStatus::Active], ['extension', 'expired', 'К группе', GroupStatus::Approved]];
    }

    #[DataProvider('paidEffects')]
    public function test_status_observes_trusted_notify_and_server_renders_correct_action(string $type, string $state, string $action, GroupStatus $target): void
    {
        $group = $this->payment->group;
        $group->update(['status' => $state, 'expires_at' => $state === 'active' ? now()->addDay() : now()->subDay()]);
        if ($type === 'extension') {
            $this->payment->update(['type' => $type, 'extension_days' => 30,
                'extension_expires_at' => $group->expires_at, 'extension_status' => $state]);
        }
        $url = '/payments/'.$this->payment->id;
        $this->actingAs($this->owner)->getJson($url.'/status')->assertExactJson(['status' => 'pending']);
        $this->post('/webpay/notify', WebpayFixture::notify($this->payment))->assertOk();
        $this->getJson($url.'/status')->assertExactJson(['status' => 'succeeded']);
        $this->get($url)->assertOk()->assertSee('Оплата подтверждена')->assertSee($action)->assertDontSee('data-payment-status-poll');
        $this->assertSame($target, $group->fresh()->status);
        Http::assertNothingSent();
    }
}
