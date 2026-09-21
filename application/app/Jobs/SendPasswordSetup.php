<?php

namespace App\Jobs;

use App\Mail\PasswordSetupMail;
use App\Models\User;
use App\Services\PasswordSetupService;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        try {
            $user = User::query()->find($this->userId);
            if (! PasswordSetupService::eligible($user) || ! $setup->broker()->tokenExists($user, $this->token)) {
                return;
            }
            // Never allow a log transport to write a live setup URL.
            if (config('mail.mailers.'.config('mail.default').'.transport') !== 'smtp') {
                throw new RuntimeException('SMTP transport required.');
            }
            $sent = Mail::to($user->email)->send(new PasswordSetupMail(
                route('password.setup', ['token' => $this->token, 'email' => $user->email]),
                $settings->passwordSetupLinkTtlHours(),
            ));
            if ($sent === null) {
                throw new RuntimeException('Mail was not sent.');
            }
        } catch (Throwable) {
            // Transport exceptions may contain recipients, server credentials or message data.
            throw new RuntimeException('Password setup delivery failed; retry the queued job.');
        }
    }
}
