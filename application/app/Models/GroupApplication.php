<?php

namespace App\Models;

use Database\Factories\GroupApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupApplication extends Model
{
    /** @use HasFactory<GroupApplicationFactory> */
    use HasFactory;

    protected $table = 'gp_group_applications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
