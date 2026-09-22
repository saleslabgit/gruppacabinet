<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property PaymentStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $recovery_started_at
 * @property Carbon|null $binding_verified_at
 * @property Carbon|null $extension_expires_at
 */
class Payment extends Model
{
    use SoftDeletes;

    protected $table = 'gp_payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'started_at' => 'datetime',
            'binding_verified_at' => 'datetime',
            'recovery_started_at' => 'datetime',
            'extension_expires_at' => 'datetime',
            'extension_days' => 'integer',
            'provider_response' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'last_status_check_at' => 'datetime',
            'status_check_attempts' => 'integer',
        ];
    }

    public function manualReview(): bool
    {
        return $this->status === PaymentStatus::Pending && $this->started_at
            && $this->started_at->lte(now()->utc()->subMinutes(20))
            && (! $this->binding_verified_at || $this->status_check_attempts >= 4
                || ($this->recovery_started_at && $this->recovery_started_at->lte(now()->utc()->subHour())));
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return HasMany<PaymentNotification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(PaymentNotification::class);
    }
}
