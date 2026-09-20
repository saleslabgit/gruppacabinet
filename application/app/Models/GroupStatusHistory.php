<?php

namespace App\Models;

use App\Enums\GroupStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'gp_group_status_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_status' => GroupStatus::class,
            'to_status' => GroupStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
