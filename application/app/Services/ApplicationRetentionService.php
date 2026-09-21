<?php

namespace App\Services;

use App\Models\GroupApplication;

class ApplicationRetentionService
{
    public function __construct(private SettingService $settings) {}

    public function cleanup(): int
    {
        $cutoff = now('UTC')->subMonthsNoOverflow($this->settings->participantApplicationRetentionMonths());
        $deleted = 0;
        GroupApplication::query()->where('created_at', '<', $cutoff)->select('id')->chunkById(500, function ($rows) use ($cutoff, &$deleted): void {
            $deleted += GroupApplication::query()->whereKey($rows->modelKeys())->where('created_at', '<', $cutoff)->delete();
        });

        return $deleted;
    }
}
