<?php

namespace App\Services;

use App\Models\Dictionary;
use App\Models\DictionaryItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DictionaryManagement
{
    public function __construct(private DictionaryUsage $usage) {}

    public function delete(Dictionary $dictionary): void
    {
        DB::transaction(function () use ($dictionary): void {
            $locked = Dictionary::query()->lockForUpdate()->findOrFail($dictionary->id);
            if (in_array($locked->code, DictionaryUsage::CORE_CODES, true) || $locked->items()->exists()) {
                throw ValidationException::withMessages(['dictionary' => 'Системный или непустой справочник нельзя удалить.']);
            }
            $locked->delete();
        });
    }

    public function saveItem(Dictionary $dictionary, ?DictionaryItem $item, array $data): DictionaryItem
    {
        return DB::transaction(function () use ($dictionary, $item, $data): DictionaryItem {
            $parent = Dictionary::query()->lockForUpdate()->findOrFail($dictionary->id);
            if ($item === null) {
                return $parent->items()->create(Arr::only($data, ['code', 'name', 'sort_order', 'active']) + ['active' => true]);
            }
            $locked = $parent->items()->lockForUpdate()->findOrFail($item->id);
            if ($locked->active && array_key_exists('active', $data) && ! $data['active'] && ! ($data['confirmed'] ?? false)) {
                throw ValidationException::withMessages(['active' => 'Подтвердите деактивацию элемента.']);
            }
            $locked->update(Arr::only($data, ['name', 'sort_order', 'active']));

            return $locked;
        });
    }

    public function deleteItem(Dictionary $dictionary, DictionaryItem $item): void
    {
        DB::transaction(function () use ($dictionary, $item): void {
            $parent = Dictionary::query()->lockForUpdate()->findOrFail($dictionary->id);
            $locked = $parent->items()->lockForUpdate()->findOrFail($item->id);
            if ($this->usage->used($parent, $locked)) {
                throw ValidationException::withMessages(['item' => 'Элемент используется. Деактивируйте его вместо удаления.']);
            }
            $locked->delete();
        });
    }
}
