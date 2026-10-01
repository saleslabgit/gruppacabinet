<?php

namespace App\Services;

use App\Jobs\SyncGroupToModx;
use App\Models\Group;
use Illuminate\Support\Facades\DB;
use Throwable;

class GroupModxSyncScheduler
{
    // Caller owns the business transaction and group row lock.
    public function schedule(Group $group): void
    {
        $group->forceFill(['modx_sync_revision' => $group->modx_sync_revision + 1,
            'modx_sync_status' => 'pending', 'modx_sync_requested_at' => now(),
            'modx_sync_started_at' => null, 'modx_sync_failed_at' => null, 'modx_sync_error_code' => null])->save();
        $id = $group->id;
        $revision = $group->modx_sync_revision;
        DB::afterCommit(function () use ($id, $revision): void {
            try {
                SyncGroupToModx::dispatch($id, $revision)->onConnection('database');
            } catch (Throwable) {
                // Approval is committed; a queue outage is recoverable through the admin action.
                Group::query()->whereKey($id)->where('modx_sync_revision', $revision)
                    ->update(['modx_sync_status' => 'failed', 'modx_sync_failed_at' => now(), 'modx_sync_error_code' => 'queue_unavailable']);
            }
        });
    }
}
