<?php

namespace App\Jobs;

use App\Mail\PasswordSetupMail;
use App\Models\User;
use App\Services\PasswordSetupService;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendPasswordSetup implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public array $backoff = [60, 300];

    public function __construct(public int $userId, #[\SensitiveParameter] private string $token) {}

    public function handle(PasswordSetupService $setup, SettingService $settings): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        $context = [
            'user_id' => $this->userId,
            'transport' => in_array($transport, ['smtp', 'sendmail'], true) ? $transport : 'unsupported',
            'attempt' => $this->attempts(),
        ];
        Log::info('mail.password_setup.started', $context);
        try {
            $user = User::query()->find($this->userId);
            if (! PasswordSetupService::eligible($user) || ! $setup->broker()->tokenExists($user, $this->token)) {
                return;
            }
            // Never allow a log transport to write a live setup URL.
            if (! in_array($transport, ['smtp', 'sendmail'], true)) {
                throw new RuntimeException('Supported mail delivery transport required.');
            }
            $sent = Mail::to($user->email)->send(new PasswordSetupMail(
                route('password.setup', ['token' => $this->token, 'email' => $user->email]),
                $settings->passwordSetupLinkTtlHours(),
            ));
            if ($sent === null) {
                throw new RuntimeException('Mail was not sent.');
            }
            Log::info('mail.password_setup.accepted_by_transport', $context);
        } catch (Throwable $exception) {
            Log::error('mail.password_setup.failed', $context + ['exception_class' => $exception::class]);
            // Transport exceptions may contain recipients, server credentials or message data.
            throw new RuntimeException('Password setup delivery failed; retry the queued job.');
        }
    }
}
