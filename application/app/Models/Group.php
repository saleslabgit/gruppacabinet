<?php

namespace App\Models;

use App\Domain\Compatibility\AcceptFromStatus;
use App\Enums\GroupStatus;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property list<string>|null $meeting_days
 * @property GroupStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $expires_at
 * @property int|null $all_count
 * @property int|null $new_count
 * @property int|null $processed_count
 */
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

            if ($group->exists && $group->getOriginal('public_site_resource_id') !== null && $group->isDirty('public_site_resource_id')) {
                throw new DomainException('A group MODX Resource ID cannot be changed.');
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
            'meeting_days' => 'array',
            'cover_size' => 'integer',
            'public_site_resource_id' => 'integer',
            'paused_at' => 'datetime',
            'modx_publication_revision' => 'integer',
            'modx_publication_requested_at' => 'datetime',
            'modx_publication_started_at' => 'datetime',
            'modx_publication_synced_at' => 'datetime',
            'modx_publication_failed_at' => 'datetime',
            'modx_sync_revision' => 'integer',
            'modx_cover_cleanup_warning' => 'boolean',
            'modx_sync_requested_at' => 'datetime',
            'modx_sync_started_at' => 'datetime',
            'modx_synced_at' => 'datetime',
            'modx_sync_failed_at' => 'datetime',
            'placement_days' => 'integer',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_warning_sent_at' => 'datetime',
            'psychologist_deleted_at' => 'datetime',
        ];
    }

    /** @param Builder<Group> $query */
    public function scopeVisibleToPsychologist(Builder $query, int $ownerId): void
    {
        $query->where('owner_id', $ownerId)->whereNull('psychologist_deleted_at');
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

    /** @return BelongsTo<DictionaryItem, $this> */
    public function groupType(): BelongsTo
    {
        return $this->belongsTo(DictionaryItem::class, 'group_type_id');
    }

    /** @return BelongsToMany<DictionaryItem, $this> */
    public function approaches(): BelongsToMany
    {
        return $this->belongsToMany(DictionaryItem::class, 'gp_group_approaches')->orderBy('sort_order')->orderBy('gp_dictionary_items.id');
    }

    /** @return BelongsToMany<DictionaryItem, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(DictionaryItem::class, 'gp_group_tags')->orderBy('sort_order')->orderBy('gp_dictionary_items.id');
    }

    /** @return HasMany<GroupApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(GroupApplication::class);
    }

    public function renewalHistoryId(): ?int
    {
        if ($this->status !== GroupStatus::Approved) {
            return null;
        }
        $history = $this->statusHistory()->latest('id')->first();

        return $history?->from_status === GroupStatus::Expired && $history->to_status === GroupStatus::Approved ? $history->id : null;
    }

    public static function applicationCounts(bool $includeHidden = false): array
    {
        return [
            'applications as all_count' => fn ($query) => $query->when(! $includeHidden, fn ($q) => $q->whereNull('psychologist_deleted_at')),
            'applications as new_count' => fn ($query) => $query->whereNull('processed_at')->when(! $includeHidden, fn ($q) => $q->whereNull('psychologist_deleted_at')),
            'applications as processed_count' => fn ($query) => $query->whereNotNull('processed_at')->when(! $includeHidden, fn ($q) => $q->whereNull('psychologist_deleted_at')),
        ];
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
