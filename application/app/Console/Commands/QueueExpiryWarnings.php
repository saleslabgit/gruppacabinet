<?php

namespace App\Console\Commands;

use App\Enums\GroupStatus;
use App\Enums\UserStatus;
use App\Jobs\SendExpiryWarning;
use App\Models\Group;
use App\Services\SettingService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Throwable;

class QueueExpiryWarnings extends Command
{
    protected $signature = 'groups:queue-expiry-warnings';

    protected $description = 'Queue warnings for eligible placement periods';

    public function handle(SettingService $settings): int
    {
        $now = now()->utc();
        $threshold = $now->copy()->addDays($settings->expiryWarningDays());
        $queued = 0;
        $lock = new UniqueLock(app(Repository::class));
        Group::query()->where('status', GroupStatus::Active)->where('disabled', false)
            ->where('expires_at', '>', $now)->where('expires_at', '<=', $threshold)
            ->whereNull('expiry_warning_sent_at')
            ->whereHas('owner', fn ($query) => $query->where('admin', false)->where('disabled', false)->where('status', UserStatus::Approved))
            ->select(['id', 'expires_at'])->chunkById(200, function ($groups) use (&$queued, $lock): void {
                foreach ($groups as $group) {
                    $job = (new SendExpiryWarning($group->id, $group->expires_at->utc()->format('Y-m-d H:i:s')))->onConnection('database');
                    // Bus dispatch bypasses PendingDispatch: acquire the framework unique lock explicitly
                    // so the count is the actual queue insert count. The worker releases this same lock.
                    if (! $lock->acquire($job)) {
                        continue;
                    }
                    try {
                        Bus::dispatch($job);
                        $queued++;
                    } catch (Throwable $exception) {
                        $lock->release($job);
                        throw $exception;
                    }
                }
            });
        $this->info('Queued expiry warnings: '.$queued);

        return self::SUCCESS;
    }
}
