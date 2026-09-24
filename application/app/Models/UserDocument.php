<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDocument extends Model
{
    protected $table = 'gp_user_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<UserTraining, $this> */
    public function training(): BelongsTo
    {
        return $this->belongsTo(UserTraining::class, 'user_training_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
