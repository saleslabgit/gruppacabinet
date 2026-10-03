<?php

namespace Tests\Feature;

use App\Jobs\SendAdminTelegram;
use App\Mail\GroupModerationMail;
use App\Mail\PasswordSetupMail;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Webpay;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\WebpayFixture;
use Tests\TestCase;

class ProductionUrlGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_pages_mail_telegram_and_payment_callbacks_use_https(): void
    {
        $this->app->instance('env', 'production');
        config(['app.url' => 'https://gruppa.info/cabinet']);
        URL::forceRootUrl('http://gruppa.info/cabinet');
        (new AppServiceProvider($this->app))->boot();
        $owner = User::create(['email' => 'https-owner@example.test', 'status' => 'approved']);
        $admin = User::create(['email' => 'https-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $group = Group::create(['owner_id' => $owner->id, 'title' => 'HTTPS Synthetic']);
        foreach ([[$owner, '/'], [$admin, '/admin/groups/'.$group->id]] as [$user, $path]) {
            $this->actingAs($user)->get('http://localhost'.$path)->assertOk()->assertDontSee('http://gruppa.info')
                ->assertSee('https://gruppa.info/cabinet');
        }
        $this->assertStringStartsWith('https://', asset('ui.css'));
        $mail = new GroupModerationMail('Synthetic', 'approved', null, route('psychologist.groups.show', $group));
        $this->assertStringNotContainsString('http://gruppa.info', $mail->render());
        $passwordMail = new PasswordSetupMail(route('password.setup', ['token' => 'synthetic-token', 'email' => $owner->email]), 24);
        $this->assertStringContainsString('https://gruppa.info/cabinet/password/setup/', $passwordMail->render());
        $this->assertStringNotContainsString('http://gruppa.info', $passwordMail->render());
        WebpayFixture::configure();
        $payment = Payment::create(['owner_id' => $owner->id, 'group_id' => $group->id, 'order_number' => 'synthetic-https', 'type' => 'placement', 'status' => 'created', 'amount' => 100]);
        $form = app(Webpay::class)->form($payment);
        foreach (['wsb_return_url', 'wsb_cancel_return_url', 'wsb_notify_url'] as $key) {
            $this->assertStringStartsWith('https://gruppa.info/cabinet/', $form['fields'][$key]);
        }
        config(['services.telegram.bot_token' => '123:synthetic_token', 'services.telegram.admin_chat_id' => '-123']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        (new SendAdminTelegram('psychologist_pending', $owner->id))->handle();
        Http::assertSent(fn ($request) => str_contains($request['text'], 'https://gruppa.info/cabinet/admin/psychologists/') && ! str_contains($request['text'], 'http://gruppa.info'));
    }

    public function test_testing_http_links_remain_supported(): void
    {
        URL::forceRootUrl('http://localhost');
        (new AppServiceProvider($this->app))->boot();
        $this->assertStringStartsWith('http://localhost', route('login'));
        $this->assertStringStartsWith('http://', asset('ui.css'));
    }
}
