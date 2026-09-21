<?php

namespace App\Console\Commands;

use App\Services\ApplicationRetentionService;
use Illuminate\Console\Command;

class CleanupApplications extends Command
{
    protected $signature = 'applications:cleanup';

    protected $description = 'Permanently delete participant applications past retention';

    public function handle(ApplicationRetentionService $retention): int
    {
        $this->info('Deleted applications: '.$retention->cleanup());

        return self::SUCCESS;
    }
}
