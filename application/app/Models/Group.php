<?php

namespace App\Models;

use App\Domain\Compatibility\AcceptFromStatus;
use App\Enums\GroupStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** @property GroupStatus $status */
class Group extends Model
{
    use SoftDeletes;

    protected $table = 'gp_groups';

    protected $guarded = ['id', 'public_uuid', 'free'];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            $owner = User::query()->findOrFail($group->owner_id);

            $group->public_uuid = (string) Str::uuid();
            $group->free = $owner->free;
        });

        static::saving(function (self $group): void {
            if ($group->exists && $group->isDirty('public_uuid')) {
                throw new DomainException('A group public UUID cannot be changed.');
            }

            $group->accept = AcceptFromStatus::forGroup($group->status);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
            'disabled' => 'boolean',
            'accept' => 'boolean',
            'free' => 'boolean',
            'meeting_duration_minutes' => 'integer',
            'participant_capacity' => 'integer',
            'meeting_price' => 'integer',
            'placement_days' => 'integer',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_warning_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<DictionaryItem, $this> */
    public function format(): BelongsTo
    {
        return $this->belongsTo(DictionaryItem::class, 'format_id');
    }

    /** @return BelongsTo<DictionaryItem, $this> */
    public function gender(): BelongsTo
    {
        return $this->belongsTo(DictionaryItem::class, 'gender_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(GroupApplication::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<GroupStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(GroupStatusHistory::class);
    }
}
