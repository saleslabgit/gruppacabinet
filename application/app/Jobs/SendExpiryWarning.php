<?php

namespace App\Jobs;

use App\Enums\GroupStatus;
use App\Enums\UserStatus;
use App\Mail\ExpiryWarningMail;
use App\Models\Group;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendExpiryWarning implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public array $backoff = [60, 300];

    public function __construct(public int $groupId, public string $expectedExpiry) {}

    public function uniqueId(): string
    {
        return 'group-expiry-warning:'.$this->groupId.':'.$this->expectedExpiry;
    }

    public function handle(SettingService $settings): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        $context = [
            'group_id' => $this->groupId,
            'transport' => in_array($transport, ['smtp', 'sendmail'], true) ? $transport : 'unsupported',
            'attempt' => $this->attempts(),
        ];
        Log::info('mail.expiry_warning.started', $context);
        $group = Group::query()->with('owner')->find($this->groupId);
        $now = now()->utc();
        if (! $group || $group->status !== GroupStatus::Active || $group->disabled
            || ! $group->expires_at || $group->expires_at->utc()->format('Y-m-d H:i:s') !== $this->expectedExpiry
            || $group->expires_at->lte($now) || $group->expires_at->gt($now->copy()->addDays($settings->expiryWarningDays()))
            || $group->expiry_warning_sent_at !== null || ! $group->owner || $group->owner->admin
            || $group->owner->disabled || $group->owner->status !== UserStatus::Approved) {
            return;
        }
        try {
            if (! in_array($transport, ['smtp', 'sendmail'], true)) {
                throw new RuntimeException('Supported mail delivery transport required.');
            }
            $sent = Mail::to($group->owner->email)->send(new ExpiryWarningMail(
                $group->title, $group->expires_at->copy()->timezone('Europe/Minsk')->format('d.m.Y H:i'),
                max(1, (int) ceil(($group->expires_at->timestamp - $now->timestamp) / 86400)),
                route('psychologist.groups.show', $group),
            ));
            if ($sent === null) {
                throw new RuntimeException('Mail was not sent.');
            }
            Log::info('mail.expiry_warning.accepted_by_transport', $context);
        } catch (Throwable $exception) {
            Log::error('mail.expiry_warning.failed', $context + ['exception_class' => $exception::class]);
            throw new RuntimeException('Expiry warning delivery failed; retry the queued job.');
        }
        DB::transaction(function (): void {
            $group = Group::query()->lockForUpdate()->find($this->groupId);
            if ($group && $group->status === GroupStatus::Active && $group->expiry_warning_sent_at === null
                && $group->expires_at?->utc()->format('Y-m-d H:i:s') === $this->expectedExpiry) {
                $group->update(['expiry_warning_sent_at' => now()->utc()]);
            }
        });
    }
}
