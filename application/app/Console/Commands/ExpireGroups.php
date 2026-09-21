<?php

namespace App\Console\Commands;

use App\Services\GroupLifecycleService;
use Illuminate\Console\Command;

class ExpireGroups extends Command
{
    protected $signature = 'groups:expire';

    protected $description = 'Expire due group placements';

    public function handle(GroupLifecycleService $lifecycle): int
    {
        $this->info('Expired groups: '.$lifecycle->expireDueGroups());

        return self::SUCCESS;
    }
}
