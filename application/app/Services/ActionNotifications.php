<?php

namespace App\Services;

use App\Jobs\SendAdminTelegram;
use App\Jobs\SendGroupModeration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActionNotifications
{
    public function telegram(string $event, int $id): void
    {
        DB::afterCommit(function () use ($event, $id): void {
            try {
                Bus::dispatch((new SendAdminTelegram($event, $id))->onConnection('database'));
            } catch (Throwable) {
                Log::error('telegram.queue_unavailable', ['event' => $event, 'entity_id' => $id]);
            }
        });
    }

    public function moderation(int $historyId): void
    {
        DB::afterCommit(function () use ($historyId): void {
            try {
                Bus::dispatch((new SendGroupModeration($historyId))->onConnection('database'));
            } catch (Throwable) {
                Log::error('mail.moderation.queue_unavailable', ['history_id' => $historyId]);
            }
        });
    }
}
