<?php

namespace App\Console\Commands;

use App\Jobs\CheckPayment;
use App\Models\Payment;
use App\Payments\PaymentRecovery;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Throwable;

class QueuePaymentChecks extends Command
{
    protected $signature = 'payments:queue-recovery-checks';

    protected $description = 'Queue finite checks of trusted-bound pending WEBPAY payments';

    public function handle(PaymentRecovery $recovery): int
    {
        $queued = 0;
        $lock = new UniqueLock(app(Repository::class));
        Payment::query()->where('status', 'pending')->whereNotNull('binding_verified_at')->whereNotNull('transaction_id')
            ->where('started_at', '<=', now()->utc()->subMinutes(20))->where('status_check_attempts', '<', 4)
            ->where(fn ($q) => $q->whereNull('recovery_started_at')->orWhere('recovery_started_at', '>', now()->utc()->subHour()))
            ->chunkById(200, function ($payments) use ($recovery, $lock, &$queued): void {
                foreach ($payments as $payment) {
                    if (! $recovery->due($payment)) {
                        continue;
                    }
                    $job = (new CheckPayment($payment->id))->onConnection('database');
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
        $this->info('Queued payment checks: '.$queued);

        return self::SUCCESS;
    }
}
