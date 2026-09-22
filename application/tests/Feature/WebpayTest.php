<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\PaymentStatus;
use App\Jobs\CheckPayment;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Models\Setting;
use App\Models\User;
use App\Payments\ConfirmPayment;
use App\Payments\PaymentAttempts;
use App\Payments\PaymentRecovery;
use App\Payments\ProviderException;
use App\Payments\Webpay;
use App\Services\GroupLifecycleService;
use App\Services\GroupWorkflow;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WebpayFixture;
use Tests\TestCase;

class WebpayTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        URL::forceRootUrl('http://localhost');
        WebpayFixture::configure();
        Http::preventStrayRequests();
        $this->fakeHttp([]);
        $this->owner = User::create(['email' => 'payment@example.test', 'status' => 'approved', 'free' => false]);
        $this->admin = User::create(['email' => 'payment-admin@example.test', 'status' => 'approved', 'admin' => true]);
        foreach (['placement_price_minor_units' => 5001, 'extension_price_minor_units' => 2500,
            'placement_duration_days' => 30, 'expired_extension_window_days' => 30, 'expiry_warning_days' => 3] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['type' => 'integer', 'value' => $value]);
            app(SettingService::class)->invalidate($key);
        }
    }

    private function fakeHttp($callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function placement(bool $start = true): Payment
    {
        $group = app(GroupWorkflow::class)->create($this->owner, $this->owner);
        $payment = $group->payments()->sole();
        if ($start) {
            app(PaymentAttempts::class)->start($payment);
        }

        return $payment->fresh();
    }

    private function active(): Group
    {
        return Group::create(['owner_id' => $this->owner->id, 'status' => 'active', 'placement_days' => 17,
            'published_at' => now()->subDays(16), 'expires_at' => now()->addDay(), 'expiry_warning_sent_at' => now()]);
    }

    public function test_form_environments_signature_and_integer_money(): void
    {
        $payment = $this->placement(false);
        config(['webpay.secret_key' => '1']);
        $payment->order_number = 'ORDER-12345678';
        $payment->amount = 3140;
        $form = app(Webpay::class)->form($payment, '1242649174');
        $this->assertSame('266e9c04a24dfb5fc75775c42a831a49488f8303', $form['fields']['wsb_signature']);
        $this->assertSame('https://securesandbox.webpay.by/', $form['url']);
        $this->assertSame('1', $form['fields']['wsb_test']);
        $this->assertSame('2', $form['fields']['wsb_version']);
        $this->assertSame('31.40', $form['fields']['wsb_total']);
        $this->assertSame($form['fields']['wsb_total'], $form['fields']['wsb_invoice_item_price[0]']);
        $this->assertArrayHasKey('*scart', $form['fields']);
        $this->assertArrayNotHasKey('secret_key', $form['fields']);
        $this->assertStringNotContainsString('synthetic-password', json_encode($form));
        $this->assertNotSame(app(Webpay::class)->form($payment)['fields']['wsb_seed'], app(Webpay::class)->form($payment)['fields']['wsb_seed']);
        config(['webpay.environment' => 'production']);
        $this->assertSame('https://payment.webpay.by/', app(Webpay::class)->form($payment)['url']);
        $this->assertSame('0', app(Webpay::class)->form($payment)['fields']['wsb_test']);
        $this->assertSame('https://billing.webpay.by', app(Webpay::class)->configuration(true)['api_url']);
        foreach ([0 => '0.00', 1 => '0.01', 100 => '1.00', 10001 => '100.01', PHP_INT_MAX => '92233720368547758.07'] as $minor => $decimal) {
            $this->assertSame($decimal, Webpay::decimal($minor));
            $this->assertSame($minor, Webpay::minor($decimal));
        }
        foreach (['-1', '1e3', '1,20', '1.001', ' 1.00', '99999999999999999999', '92233720368547758.08'] as $bad) {
            try {
                Webpay::minor($bad);
                $this->fail('Invalid money accepted');
            } catch (ProviderException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_missing_price_config_and_free_creation(): void
    {
        Setting::where('key', 'placement_price_minor_units')->update(['value' => null]);
        $this->actingAs($this->owner)->post('/groups')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('gp_groups', 0);
        $this->assertDatabaseCount('gp_payments', 0);
        Setting::where('key', 'placement_price_minor_units')->update(['value' => '100']);
        config(['webpay.secret_key' => null]);
        $this->post('/groups')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('gp_groups', 0);
        $this->owner->update(['free' => true]);
        $this->post('/groups')->assertRedirect();
        $this->assertSame(GroupStatus::Draft, Group::sole()->status);
        $this->assertDatabaseCount('gp_payments', 0);
    }

    public function test_placement_http_flow_notify_duplicate_retry_and_browser_trust(): void
    {
        $this->actingAs($this->owner)->post('/groups')->assertRedirect();
        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Created, $payment->status);
        $this->assertSame(GroupStatus::AwaitingPayment, $payment->group->status);
        $this->get('/payments/'.$payment->id)->assertOk()->assertSee('Оплатить через WEBPAY');
        $this->get('/payments/'.$payment->id)->assertOk();
        $this->assertDatabaseCount('gp_payments', 1);
        $this->post('/payments/'.$payment->id.'/start')->assertOk()->assertSee('wsb_signature')->assertDontSee('synthetic-secret')->assertDontSee('synthetic-password');
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->post('/payments/'.$payment->id.'/start')->assertOk();
        $this->assertDatabaseCount('gp_payments', 1);
        $this->get('/payments/'.$payment->id.'/return?wsb_tid=999')->assertOk()->assertSee('Оплата подтверждается');
        $this->get('/payments/'.$payment->id.'/cancel')->assertOk();
        $this->assertNull($payment->fresh()->transaction_id);
        Http::assertNothingSent();
        $this->post('/payments/'.$payment->id.'/retry')->assertStatus(409);
        $this->post('/webpay/notify', WebpayFixture::notify($payment))->assertOk();
        $this->post('/webpay/notify', WebpayFixture::notify($payment))->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(GroupStatus::Draft, $payment->group->fresh()->status);
        $this->assertSame(2, $payment->group->statusHistory()->count());
        $this->assertNotNull($payment->fresh()->binding_verified_at);
        $this->get('/payments/'.$payment->id.'/return')->assertOk()->assertSee('Оплата подтверждена');
        $this->assertDatabaseCount('gp_payment_notifications', 2);
        $journal = json_encode(PaymentNotification::all()->toArray());
        $this->assertStringNotContainsString('wsb_signature', $journal);
        $this->assertStringNotContainsString('synthetic-secret', $journal);
        $this->assertStringNotContainsString('555444333222', $journal);
    }

    public static function invalidNotifications(): array
    {
        return [[['amount' => '51.00']], [['currency_id' => 'USD']], [['payment_method' => 'test']], [['payment_type' => '10']],
            [['transaction_id' => 'invalid']], [['site_order_id' => 'GP-'.str_repeat('0', 32)]]];
    }

    #[DataProvider('invalidNotifications')]
    public function test_invalid_signed_notify_has_no_effect(array $changes): void
    {
        $payment = $this->placement();
        $this->post('/webpay/notify', WebpayFixture::notify($payment, $changes))->assertStatus(400);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->transaction_id);
        $this->assertSame(GroupStatus::AwaitingPayment, $payment->group->status);
        $this->assertSame(1, $payment->group->statusHistory()->count());
    }

    public function test_bad_signature_card_payload_and_transaction_conflict(): void
    {
        $first = $this->placement();
        $payload = WebpayFixture::notify($first);
        $payload['wsb_signature'] = str_repeat('0', 32);
        $payload['password'] = 'do-not-store';
        $payload['card'] = 'synthetic-card-marker';
        $this->post('/webpay/notify', $payload)->assertStatus(400);
        $this->assertStringNotContainsString('do-not-store', json_encode(PaymentNotification::sole()->payload));
        unset($payload['card']);
        $this->post('/webpay/notify', $payload)->assertStatus(400);
        $this->post('/webpay/notify', WebpayFixture::notify($first))->assertOk();
        $second = $this->placement();
        $this->post('/webpay/notify', WebpayFixture::notify($second))->assertStatus(400);
        $this->assertNull($second->fresh()->transaction_id);
        $this->assertSame(PaymentStatus::Pending, $second->fresh()->status);
    }

    public static function unsuccessful(): array
    {
        return [['2', 'failed'], ['8', 'failed'], ['7', 'cancelled']];
    }

    #[DataProvider('unsuccessful')]
    public function test_trusted_failure_allows_new_current_price_attempt(string $type, string $status): void
    {
        $payment = $this->placement();
        $this->post('/webpay/notify', WebpayFixture::notify($payment, ['payment_type' => $type]))->assertOk();
        $this->assertSame($status, $payment->fresh()->status->value);
        $this->assertSame(GroupStatus::AwaitingPayment, $payment->group->status);
        Setting::where('key', 'placement_price_minor_units')->update(['value' => '999']);
        $this->owner->update(['free' => true]);
        $this->actingAs($this->owner)->post('/payments/'.$payment->id.'/retry')->assertRedirect();
        $next = Payment::latest('id')->first();
        $this->assertNotSame($next->order_number, $payment->order_number);
        $this->assertSame(999, $next->amount);
        $this->assertFalse($next->group->free);
        $this->post('/payments/'.$payment->id.'/retry')->assertRedirect();
        $this->assertDatabaseCount('gp_payments', 2);
    }

    public function test_api_request_validation_and_standalone_result_cannot_bind(): void
    {
        $payment = $this->placement();
        $fields = WebpayFixture::notify($payment);
        $this->fakeHttp(['sandbox.webpay.by' => Http::response(WebpayFixture::xml($fields))]);
        $result = app(Webpay::class)->transaction('987654');
        $this->assertNull($result->merchantOrder);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.webpay.by'
            && $request['*API'] === '' && str_contains($request['API_XML_REQUEST'], '<command>get_transaction</command>')
            && str_contains($request['API_XML_REQUEST'], '<password>'.md5('synthetic-password').'</password>')
            && str_contains($request['API_XML_REQUEST'], '<transaction_id>987654</transaction_id>')
            && ! str_contains($request['API_XML_REQUEST'], 'synthetic-password'));
        try {
            app(ConfirmPayment::class)->apply($payment->id, $result);
            $this->fail('Unbound API result accepted');
        } catch (ProviderException $e) {
            $this->assertSame('unbound_transaction', $e->getMessage());
        }
        $this->assertNull($payment->fresh()->transaction_id);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->post('/webpay/notify', $fields)->assertOk();
        $this->assertSame('duplicate', app(ConfirmPayment::class)->apply($payment->id, $result));
    }

    public function test_api_rejects_malformed_signature_mismatch_and_network_failures(): void
    {
        $payment = $this->placement();
        $xml = WebpayFixture::xml(WebpayFixture::notify($payment));
        foreach (['', '<broken', '<!DOCTYPE x [<!ENTITY foo SYSTEM "file:///etc/passwd">]><x/>',
            str_replace('<amount>50.01</amount>', '<amount>50.02</amount>', $xml),
            WebpayFixture::xml(WebpayFixture::notify($payment), ['transaction_id' => '111'])] as $invalid) {
            $this->fakeHttp(['sandbox.webpay.by' => Http::response($invalid)]);
            try {
                app(Webpay::class)->transaction('987654');
                $this->fail('Invalid API response');
            } catch (ProviderException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->fakeHttp(['sandbox.webpay.by' => Http::response('unavailable', 503)]);
        try {
            app(Webpay::class)->transaction('987654');
            $this->fail('HTTP error accepted');
        } catch (ProviderException $e) {
            $this->assertSame('provider_unavailable', $e->getMessage());
        }
        $this->fakeHttp(fn () => throw new ConnectionException('synthetic-password'));
        try {
            app(Webpay::class)->transaction('987654');
            $this->fail('Timeout accepted');
        } catch (ProviderException $e) {
            $this->assertSame('provider_unavailable', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_paid_active_and_expired_extensions_apply_once_across_window(): void
    {
        foreach (['active', 'expired'] as $index => $status) {
            $group = $this->active();
            if ($status === 'expired') {
                $group->update(['status' => 'expired', 'expires_at' => now()->subDays(30)]);
            }
            $before = $group->expires_at->toDateTimeString();
            $payment = app(PaymentAttempts::class)->extend($group, $this->owner);
            $this->assertInstanceOf(Payment::class, $payment);
            $this->assertSame($before, $group->fresh()->expires_at->toDateTimeString());
            app(PaymentAttempts::class)->start($payment);
            $this->travel(5)->minutes();
            $payload = WebpayFixture::notify($payment, ['transaction_id' => (string) (987654 + $index)]);
            $this->post('/webpay/notify', $payload)->assertOk();
            $this->post('/webpay/notify', $payload)->assertOk();
            if ($status === 'active') {
                $this->assertTrue($group->fresh()->expires_at->equalTo($group->expires_at->copy()->addDays(17)));
                $this->assertNull($group->fresh()->expiry_warning_sent_at);
            } else {
                $this->assertSame(GroupStatus::Approved, $group->fresh()->status);
                $this->assertSame($before, $group->fresh()->expires_at->toDateTimeString());
                $this->assertSame(1, $group->statusHistory()->count());
            }
            $this->actingAs($this->owner)->get('/payments/'.$payment->id)->assertOk()->assertSee('Оплата подтверждена');
        }
    }

    public function test_extension_uses_current_tariff_and_blocks_missing_price_window(): void
    {
        $this->owner->update(['free' => true]);
        $group = $this->active();
        $this->owner->update(['free' => false]);
        Setting::where('key', 'extension_price_minor_units')->update(['value' => null]);
        $this->actingAs($this->owner)->post('/groups/'.$group->id.'/extension', ['confirmed' => '1'])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('gp_payments', 0);
        Setting::where('key', 'extension_price_minor_units')->update(['value' => '2500']);
        $this->post('/groups/'.$group->id.'/extension', ['confirmed' => '1'])->assertRedirect();
        $payment = Payment::sole();
        $this->owner->update(['free' => true]);
        $this->post('/groups/'.$group->id.'/extension', ['confirmed' => '1'])->assertRedirect('/payments/'.$payment->id);
        $this->assertDatabaseCount('gp_payments', 1);
        $this->assertSame(2500, $payment->amount);
        $second = $this->active();
        $second->update(['status' => 'expired', 'expires_at' => now()->subDays(31)]);
        $this->owner->update(['free' => false]);
        $this->post('/groups/'.$second->id.'/extension', ['confirmed' => '1'])->assertSessionHasErrors('extension');
        $this->owner->update(['free' => true]);
        config(['webpay.secret_key' => null]);
        $paidSnapshot = $this->active();
        DB::table('gp_groups')->where('id', $paidSnapshot->id)->update(['free' => false]);
        $this->post('/groups/'.$paidSnapshot->id.'/extension', ['confirmed' => '1'])->assertRedirect();
        $this->assertDatabaseCount('gp_payments', 1);
        $this->assertTrue($paidSnapshot->fresh()->expires_at->equalTo($paidSnapshot->expires_at->copy()->addDays(17)));
    }

    public function test_unbound_recovery_no_http_manual_review_and_access_controls(): void
    {
        $payment = $this->placement();
        $recovery = app(PaymentRecovery::class);
        $this->assertFalse($recovery->due($payment));
        $this->travel(21)->minutes();
        $recovery->check($payment->id);
        $this->artisan('payments:queue-recovery-checks')->expectsOutput('Queued payment checks: 0')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertTrue($payment->fresh()->manualReview());
        $this->assertSame(0, $payment->fresh()->status_check_attempts);
        $other = User::create(['email' => 'other-payment@example.test', 'status' => 'approved']);
        $this->actingAs($other)->get('/payments/'.$payment->id)->assertNotFound();
        $this->post('/payments/'.$payment->id.'/start')->assertNotFound();
        $this->get('/payments/'.$payment->id.'/return')->assertNotFound();
        $this->get('/admin/payments')->assertForbidden();
        $this->actingAs($this->owner)->get('/payments/'.$payment->id)->assertOk()->assertSee('ручная проверка');
    }

    private function boundPending(): Payment
    {
        $payment = $this->placement();
        // Signed notify establishes binding but cannot apply to unexpected group state.
        DB::table('gp_groups')->where('id', $payment->group_id)->update(['status' => 'approved']);
        $this->post('/webpay/notify', WebpayFixture::notify($payment))->assertOk();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->binding_verified_at);
        DB::table('gp_groups')->where('id', $payment->group_id)->update(['status' => 'awaiting_payment']);

        return $payment->fresh();
    }

    public function test_bound_recovery_finite_schedule_network_failures_and_uniqueness(): void
    {
        $payment = $this->boundPending();
        $recovery = app(PaymentRecovery::class);
        $this->fakeHttp(['sandbox.webpay.by' => Http::response('', 503)]);
        $recovery->check($payment->id);
        Http::assertNothingSent();
        $this->travel(20)->minutes();
        Queue::fake();
        $this->artisan('payments:queue-recovery-checks')->assertSuccessful();
        $this->artisan('payments:queue-recovery-checks')->assertSuccessful();
        Queue::assertPushed(CheckPayment::class, 1);
        foreach ([0, 10, 20, 25] as $wait) {
            $this->travel($wait)->minutes();
            $recovery->check($payment->id);
            $recovery->check($payment->id);
        }
        $this->assertSame(4, $payment->fresh()->status_check_attempts);
        Http::assertSentCount(4);
        $this->travel(2)->hours();
        $recovery->check($payment->id);
        Http::assertSentCount(4);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertTrue($payment->fresh()->manualReview());
    }

    public function test_bound_recovery_can_confirm_and_refund_accounting_is_local(): void
    {
        $payment = $this->boundPending();
        $this->fakeHttp(['sandbox.webpay.by' => Http::response(WebpayFixture::xml(WebpayFixture::notify($payment)))]);
        $this->travel(20)->minutes();
        app(PaymentRecovery::class)->check($payment->id);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertFalse($this->owner->can('delete', $payment->group->fresh()));
        $this->actingAs($this->owner)->post('/admin/payments/'.$payment->id.'/refund', ['refund_comment' => 'Done', 'confirmed' => '1'])->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/payments/'.$payment->id.'/refund', ['confirmed' => '1'])->assertSessionHasErrors('refund_comment');
        $this->fakeHttp([]);
        $this->post('/admin/payments/'.$payment->id.'/refund', ['refund_comment' => 'Synthetic refund recorded', 'confirmed' => '1'])->assertRedirect();
        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->refunded_at);
        $this->assertDatabaseHas('gp_audit_log', ['entity_type' => 'payment', 'entity_id' => $payment->id, 'action' => 'payment.refunded']);
        $this->assertTrue($this->owner->can('delete', $payment->group->fresh()));
        $this->post('/admin/payments/'.$payment->id.'/refund', ['refund_comment' => 'Again', 'confirmed' => '1'])->assertForbidden();
    }

    public function test_admin_pages_filters_pagination_safe_journal_and_constant_queries(): void
    {
        $payment = $this->placement();
        $this->travel(21)->minutes();
        $this->actingAs($this->admin)->get('/admin/payments')->assertOk()->assertSee($payment->order_number)->assertSee('ручная проверка');
        $this->get('/admin/payments/'.$payment->id)->assertOk()->assertSee('Последний проверенный ответ');
        $this->get('/admin/payments?search=absent')->assertOk()->assertSee('Платежи не найдены');
        $this->get('/admin/payments?type=extension')->assertOk()->assertDontSee($payment->order_number);
        $this->get('/admin/payments?status=pending&owner_id='.$this->owner->id)->assertOk()->assertSee($payment->order_number);
        $this->get('/admin/payments?from=2000-01-01&to=2000-01-02')->assertOk()->assertDontSee($payment->order_number);
        $counts = [];
        foreach ([1, 21] as $total) {
            while (Payment::count() < $total) {
                $this->placement();
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get('/admin/payments')->assertOk();
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame($counts[0], $counts[1]);
        $this->get('/admin/payments?page=2')->assertOk()->assertSee($payment->order_number);
        Http::assertNothingSent();
    }

    public function test_api_transport_keeps_tls_and_rejects_every_bound_mismatch(): void
    {
        $payment = $this->boundPending();
        $options = [];
        $xml = WebpayFixture::xml(WebpayFixture::notify($payment));
        $this->fakeHttp(function ($request, $transport) use (&$options, $xml) {
            $options = $transport;

            return Http::response($xml);
        });
        app(Webpay::class)->transaction('987654');
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(15, $options['timeout']);
        foreach ([['amount' => '50.02'], ['currency_id' => 'USD'], ['payment_method' => 'apple'],
            ['payment_type' => '11'], ['order_id' => '111']] as $changes) {
            $this->fakeHttp(['sandbox.webpay.by' => Http::response(WebpayFixture::xml(WebpayFixture::notify($payment), $changes))]);
            $result = app(Webpay::class)->transaction('987654');
            try {
                app(ConfirmPayment::class)->apply($payment->id, $result);
                $this->fail('Mismatched API result applied');
            } catch (ProviderException) {
                $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
                $this->assertSame(GroupStatus::AwaitingPayment, $payment->group->fresh()->status);
            }
        }
    }

    public function test_recovery_stops_at_one_hour_even_when_attempt_slots_remain(): void
    {
        $payment = $this->boundPending();
        $this->fakeHttp(['sandbox.webpay.by' => Http::response('', 503)]);
        $this->travel(20)->minutes();
        app(PaymentRecovery::class)->check($payment->id);
        $this->travel(60)->minutes();
        app(PaymentRecovery::class)->check($payment->id);
        Http::assertSentCount(1);
        $this->assertTrue($payment->fresh()->manualReview());
        $this->assertSame(1, $payment->fresh()->status_check_attempts);
    }

    public function test_payment_actions_require_csrf_but_notify_is_stateless(): void
    {
        $payment = $this->placement(false);
        $this->actingAs($this->owner);
        $this->app['env'] = 'local';
        try {
            $this->post('/payments/'.$payment->id.'/start')->assertStatus(419);
            app(PaymentAttempts::class)->start($payment);
            $response = $this->post('/webpay/notify', WebpayFixture::notify($payment));
            $response->assertOk();
            $this->assertSame([], $response->headers->getCookies());
            $this->actingAs($this->admin)->post('/admin/payments/'.$payment->id.'/refund', ['confirmed' => '1', 'refund_comment' => 'Done'])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_extension_started_while_active_can_expire_before_success(): void
    {
        $group = $this->active();
        $payment = app(PaymentAttempts::class)->extend($group, $this->owner);
        app(PaymentAttempts::class)->start($payment);
        $this->travel(2)->days();
        app(GroupLifecycleService::class)->expireOne($group->id);
        $before = $group->fresh()->expires_at->toDateTimeString();
        $this->post('/webpay/notify', WebpayFixture::notify($payment))->assertOk();
        $this->assertSame(GroupStatus::Approved, $group->fresh()->status);
        $this->assertSame($before, $group->fresh()->expires_at->toDateTimeString());
        $this->assertSame('extension-expired', $payment->fresh()->product_effect);
    }

    public function test_fixed_notify_and_xml_signature_vectors(): void
    {
        $fields = ['batch_timestamp' => '1790000000', 'currency_id' => 'BYN', 'amount' => '50.01',
            'payment_method' => 'cc', 'order_id' => '123456', 'site_order_id' => 'GP-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'transaction_id' => '987654', 'payment_type' => '1', 'rrn' => '555444333222',
            'wsb_signature' => '72235c56d642352715606d0aab59b99a'];
        $this->assertSame($fields['site_order_id'], app(Webpay::class)->notify($fields)->merchantOrder);
        $xml = '<wsb_api_response><transaction_id>987654</transaction_id><batch_timestamp>1790000000</batch_timestamp><currency_id>BYN</currency_id><amount>50.01</amount><payment_method>cc</payment_method><payment_type>1</payment_type><order_id>123456</order_id><rrn>555444333222</rrn><wsb_signature>7d0f6d5082e26b9f526e7de94051da32</wsb_signature></wsb_api_response>';
        $this->fakeHttp(['sandbox.webpay.by' => Http::response($xml)]);
        $result = app(Webpay::class)->transaction('987654');
        $this->assertSame('987654', $result->fields['transaction_id']);
        $this->assertNull($result->merchantOrder);
    }
}
