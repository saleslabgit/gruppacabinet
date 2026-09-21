<?php

namespace App\Services;

use App\Models\Dictionary;
use App\Models\DictionaryItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DictionaryUsage
{
    public const CORE_CODES = ['education_type', 'group_format', 'gender'];

    public function references(Dictionary $dictionary): ?Builder
    {
        return match ($dictionary->code) {
            'education_type' => DB::table('gp_users')->select('education_type_id as item_id')->whereNotNull('education_type_id'),
            'group_format' => DB::table('gp_groups')->select('format_id as item_id')->whereNotNull('format_id'),
            'gender' => DB::table('gp_groups')->select('gender_id as item_id')->whereNotNull('gender_id'),
            default => null,
        };
    }

    public function used(Dictionary $dictionary, DictionaryItem $item): bool
    {
        $references = $this->references($dictionary);

        return $references !== null && DB::query()->fromSub($references, 'usage')->where('item_id', $item->id)->exists();
    }
}
