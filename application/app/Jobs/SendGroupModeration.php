<?php

namespace App\Jobs;

use App\Mail\GroupModerationMail;
use App\Models\Group;
use App\Models\GroupStatusHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendGroupModeration implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public array $backoff = [60, 300];

    public function __construct(public int $historyId) {}

    public function handle(): void
    {
        $history = GroupStatusHistory::find($this->historyId);
        $group = $history ? Group::withTrashed()->with('owner')->find($history->group_id) : null;
        if (! $group?->owner || $history->from_status?->value !== 'moderation'
            || ! in_array($history->to_status->value, ['approved', 'revision', 'rejected'], true)) {
            return;
        }
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        $context = ['history_id' => $this->historyId, 'event' => $history->to_status->value,
            'transport' => in_array($transport, ['smtp', 'sendmail'], true) ? $transport : 'unsupported', 'attempt' => $this->attempts()];
        try {
            if (! in_array($transport, ['smtp', 'sendmail'], true)) {
                throw new RuntimeException('Supported mail delivery transport required.');
            }
            $sent = Mail::to($group->owner->email)->send(new GroupModerationMail($group->title, $history->to_status->value,
                $history->comment, route('psychologist.groups.show', $group)));
            if ($sent === null) {
                throw new RuntimeException('Mail was not sent.');
            }
            Log::info('mail.moderation.accepted_by_transport', $context);
        } catch (Throwable) {
            Log::error('mail.moderation.failed', $context);
            throw new RuntimeException('Group moderation delivery failed; retry the queued job.');
        }
    }
}
