<?php

namespace App\Models;

use App\Domain\Compatibility\AcceptFromStatus;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * @property UserStatus $status
 * @property Carbon|null $license_expires_at
 * @property Carbon|null $personal_data_consent_at
 * @property-read DictionaryItem|null $educationType
 */
class User extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'gp_users';

    protected $guarded = ['id', 'active_email'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            $user->accept = AcceptFromStatus::forUser($user->status);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'accept' => 'boolean',
            'disabled' => 'boolean',
            'free' => 'boolean',
            'admin' => 'boolean',
            'documents_confirmed' => 'boolean',
            'education_confirmed' => 'boolean',
            'live_session_ready' => 'boolean',
            'license_expires_at' => 'date',
            'personal_data_consent_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<DictionaryItem, $this> */
    public function educationType(): BelongsTo
    {
        return $this->belongsTo(DictionaryItem::class, 'education_type_id');
    }

    /** @return HasMany<UserDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'owner_id');
    }
}
