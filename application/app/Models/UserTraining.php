<?php

namespace App\Models;

use App\Support\TrainingData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class UserTraining extends Model
{
    protected $table = 'gp_user_trainings';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $training): void {
            if (! TrainingData::hasValue($training->getAttributes())) {
                throw new InvalidArgumentException('A training must contain at least one value.');
            }
        });
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'graduation_year' => 'integer', 'training_hours' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<UserDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class)->where('type', 'certificate');
    }
}
