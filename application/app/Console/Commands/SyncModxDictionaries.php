<?php

namespace App\Console\Commands;

use App\Exceptions\ModxDictionarySyncException;
use App\Services\ModxDictionarySyncService;
use Illuminate\Console\Command;

class SyncModxDictionaries extends Command
{
    protected $signature = 'modx:sync-dictionaries';

    protected $description = 'Synchronize MODX-managed dictionary options';

    public function handle(ModxDictionarySyncService $sync): int
    {
        try {
            $counts = $sync->sync();
            $this->info('Справочников: '.$counts['dictionaries'].'; вариантов: '.$counts['options']
                .'; создано: '.$counts['created'].'; связано: '.$counts['linked'].'; деактивировано: '.$counts['deactivated'].'.');

            return self::SUCCESS;
        } catch (ModxDictionarySyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
