<?php

namespace Tests\Feature;

use App\Jobs\SendAdminTelegram;
use App\Jobs\SendGroupModeration;
use App\Mail\GroupModerationMail;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Support\SuccessfulMailFake;
use Tests\TestCase;

class ActionNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->owner = User::create(['email' => 'notification@example.test', 'first_name' => 'Synthetic', 'last_name' => 'Psychologist', 'status' => 'approved']);
        config(['services.telegram.bot_token' => '123:synthetic_token', 'services.telegram.admin_chat_id' => '-123']);
    }

    public function test_feedback_is_authenticated_text_only_trimmed_rate_limited_and_queued(): void
    {
        Queue::fake();
        $this->get('/feedback')->assertRedirect('/login');
        $admin = User::create(['email' => 'notification-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $this->actingAs($admin)->get('/feedback')->assertForbidden();
        $this->actingAs($this->owner)->get('/feedback')->assertOk()->assertSee('Сообщить об ошибке');
        $this->post('/feedback', ['message' => '  <b>Synthetic feedback</b>  '])->assertRedirect('/feedback');
        $this->get('/feedback')->assertSee('Сообщение принято. Спасибо за обратную связь.')->assertDontSee('очеред');
        Queue::assertPushed(SendAdminTelegram::class, fn ($job) => $job->entityId === $this->owner->id && $job->feedback === '<b>Synthetic feedback</b>' && $job->connection === 'database');
        $this->post('/feedback', ['message' => 'Second'])->assertRedirect();
        $this->post('/feedback', ['message' => 'Third'])->assertTooManyRequests();
        Queue::assertPushed(SendAdminTelegram::class, 2);
    }

    public function test_feedback_rejects_empty_oversized_and_file_input_and_reports_queue_failure(): void
    {
        Queue::fake();
        $this->actingAs($this->owner)->post('/feedback', ['message' => '   '])->assertSessionHasErrors('message');
        $this->post('/feedback', ['message' => str_repeat('я', 2801)])->assertSessionHasErrors('message');
        $this->travel(61)->seconds();
        $this->post('/feedback', ['message' => 'File', 'photo' => UploadedFile::fake()->create('test.txt')])->assertSessionHasErrors('message');
        Queue::assertNothingPushed();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Synthetic queue failure'));
        $this->from('/feedback')->post('/feedback', ['message' => 'Retry'])->assertRedirect('/feedback')->assertSessionHasErrors(['message' => 'Не удалось отправить сообщение. Попробуйте ещё раз позже.']);
        $this->get('/feedback')->assertOk()->assertSee('Не удалось отправить сообщение. Попробуйте ещё раз позже.')->assertDontSee('очеред');
    }

    public function test_telegram_plain_text_request_contains_identity_and_exact_https_endpoint(): void
    {
        URL::forceScheme('https');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        (new SendAdminTelegram('feedback', $this->owner->id, '<script>text</script>'))->handle();
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot123:synthetic_token/sendMessage'
            && $request['chat_id'] === '-123' && ! isset($request['parse_mode'])
            && str_contains($request['text'], 'Психолог #'.$this->owner->id)
            && str_contains($request['text'], 'Synthetic') && str_contains($request['text'], $this->owner->email)
            && str_contains($request['text'], '<script>text</script>')
            && str_contains($request['text'], 'https://localhost/admin/psychologists/'.$this->owner->id));
        $group = Group::create(['owner_id' => $this->owner->id, 'title' => 'Synthetic moderation']);
        (new SendAdminTelegram('group_moderation', $group->id))->handle();
        (new SendAdminTelegram('psychologist_pending', $this->owner->id))->handle();
        Http::assertSentCount(3);
    }

    public function test_telegram_transport_exception_and_configuration_never_expose_token(): void
    {
        Log::spy();
        Http::fake(fn () => throw new ConnectionException('https://api.telegram.org/bot123:synthetic_token/sendMessage'));
        foreach (['123:synthetic_token', 'invalid-secret'] as $token) {
            config(['services.telegram.bot_token' => $token]);
            try {
                (new SendAdminTelegram('feedback', $this->owner->id, 'Private feedback'))->handle();
                $this->fail('Expected safe failure');
            } catch (\RuntimeException $exception) {
                $this->assertNull($exception->getPrevious());
                $this->assertStringNotContainsString($token, (string) $exception);
                $this->assertStringNotContainsString('Private feedback', (string) $exception);
            }
        }
        Log::shouldHaveReceived('error')->twice()->withArgs(fn ($event, $context) => $event === 'telegram.delivery_failed'
            && array_keys($context) === ['event', 'entity_id', 'attempt']);
    }

    public function test_moderation_mail_delivers_committed_result_comment_owner_and_https_link(): void
    {
        SuccessfulMailFake::install();
        config(['mail.default' => 'smtp']);
        URL::forceScheme('https');
        $group = Group::create(['owner_id' => $this->owner->id, 'title' => 'Synthetic group']);
        foreach (['approved', 'revision', 'rejected'] as $result) {
            $history = $group->statusHistory()->create(['from_status' => 'moderation', 'to_status' => $result, 'actor_type' => 'system', 'comment' => 'Synthetic reason']);
            (new SendGroupModeration($history->id))->handle();
            Mail::assertSent(GroupModerationMail::class, fn ($mail) => $mail->hasTo($this->owner->email) && $mail->result === $result
                && $mail->comment === 'Synthetic reason' && $mail->groupTitle === $group->title
                && str_starts_with($mail->groupUrl, 'https://'));
        }
        Mail::assertSent(GroupModerationMail::class, 3);
    }

    public function test_mail_failure_is_safe_and_never_changes_moderation(): void
    {
        $group = Group::create(['owner_id' => $this->owner->id, 'status' => 'rejected', 'title' => 'Synthetic']);
        $history = $group->statusHistory()->create(['from_status' => 'moderation', 'to_status' => 'rejected', 'actor_type' => 'system', 'comment' => 'Private reason']);
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic private SMTP credentials'));
        Log::spy();
        try {
            (new SendGroupModeration($history->id))->handle();
            $this->fail('Expected safe delivery error');
        } catch (\RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('credentials', (string) $exception);
        }
        $this->assertSame('rejected', $group->fresh()->status->value);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($event, $context) => $event === 'mail.moderation.failed'
            && array_keys($context) === ['history_id', 'event', 'transport', 'attempt']);
    }
}
