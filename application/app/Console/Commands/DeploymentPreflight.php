<?php

namespace App\Console\Commands;

use App\Support\DeploymentPreflight as Checks;
use Illuminate\Console\Command;
use Throwable;

class DeploymentPreflight extends Command
{
    protected $signature = 'deployment:preflight';

    protected $description = 'Check shared-hosting prerequisites without sending mail, provider calls or business writes';

    public function handle(Checks $preflight): int
    {
        try {
            $checks = $preflight->checks();
        } catch (Throwable) {
            // Even setup/configuration exceptions may contain credentials. Do not report their messages.
            $this->error('FAIL Preflight could not complete; inspect private deployment configuration.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($checks as $check) {
            $this->line(($check['ok'] ? 'PASS ' : 'FAIL ').$check['check'].($check['detail'] !== '' ? ': '.$check['detail'] : ''));
            $failed = $failed || ! $check['ok'];
        }
        $this->line('No email/provider request/business data change performed. Temporary cache probes are removed.');
        $this->line('Web PHP, cron limits, routing, SMTP/HTTPS reachability and backups require hosting acceptance.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
