<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dictionary extends Model
{
    protected $table = 'gp_dictionaries';

    protected $guarded = ['id'];

    /** @return HasMany<DictionaryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DictionaryItem::class);
    }
}
