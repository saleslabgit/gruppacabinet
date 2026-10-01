<?php

namespace App\Services;

use App\Exceptions\ModxDictionarySyncException;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Services\Modx\DictionaryClient;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ModxDictionarySyncService
{
    public const LOCK = 'modx:sync-dictionaries';

    public function __construct(private DictionaryClient $client) {}

    /** @return array{dictionaries: int, options: int, created: int, linked: int, deactivated: int} */
    public function sync(): array
    {
        try {
            $lock = Cache::lock(self::LOCK, 600);
            if (! $lock->get()) {
                throw new ModxDictionarySyncException('Синхронизация MODX уже выполняется.');
            }
            try {
                $data = $this->client->fetch();

                return DB::transaction(function () use ($data, $lock): array {
                    $counts = ['dictionaries' => 0, 'options' => 0, 'created' => 0, 'linked' => 0, 'deactivated' => 0];
                    $syncedAt = now();
                    foreach ($data as $code => $options) {
                        $dictionary = Dictionary::query()->where('code', $code)->lockForUpdate()->firstOrFail();
                        if ($dictionary->modx_tv_name !== DictionaryClient::DEFINITIONS[$code]['tv']) {
                            throw new ModxDictionarySyncException('Нарушена привязка справочника MODX.');
                        }
                        $items = $dictionary->items()->lockForUpdate()->get();
                        $present = [];
                        foreach ($options as $option) {
                            $item = $items->first(fn (DictionaryItem $item): bool => $item->modx_value === $option['value']);
                            if ($item === null) {
                                $legacy = $items->filter(fn (DictionaryItem $item): bool => $item->modx_value === null
                                    && $this->normalize($item->name) === $this->normalize($option['label']));
                                if ($legacy->count() > 1) {
                                    throw new ModxDictionarySyncException('Неоднозначное соответствие старых значений MODX.');
                                }
                                $item = $legacy->first();
                                if ($item !== null) {
                                    $counts['linked']++;
                                } else {
                                    $localCode = 'modx_'.substr(hash('sha256', $option['value']), 0, 56);
                                    if ($items->contains('code', $localCode)) {
                                        throw new ModxDictionarySyncException('Конфликт стабильного кода элемента MODX.');
                                    }
                                    $item = new DictionaryItem(['dictionary_id' => $dictionary->id, 'code' => $localCode]);
                                    $items->push($item);
                                    $counts['created']++;
                                }
                            }
                            $item->fill(['modx_value' => $option['value'], 'name' => $option['label'],
                                'sort_order' => $option['position'], 'active' => true, 'last_synced_at' => $syncedAt])->save();
                            $present[] = $item->id;
                            $counts['options']++;
                        }
                        foreach ($items as $item) {
                            if (! in_array($item->id, $present, true) && $item->active) {
                                $item->update(['active' => false]);
                                $counts['deactivated']++;
                            }
                        }
                        $dictionary->update(['last_synced_at' => $syncedAt]);
                        $counts['dictionaries']++;
                    }
                    if (! $lock instanceof Lock || ! $lock->isOwnedByCurrentProcess()) {
                        throw new ModxDictionarySyncException('Истекла блокировка синхронизации MODX.');
                    }

                    return $counts;
                });
            } finally {
                $lock->release();
            }
        } catch (ModxDictionarySyncException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ModxDictionarySyncException('Не удалось сохранить справочники MODX.');
        }
    }

    private function normalize(string $label): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)), 'UTF-8');
    }
}
