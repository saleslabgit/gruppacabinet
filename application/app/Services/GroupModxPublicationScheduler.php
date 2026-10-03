<?php

namespace App\Services;

use App\Jobs\SetGroupModxPublication;
use App\Models\Group;
use Illuminate\Support\Facades\DB;
use Throwable;

class GroupModxPublicationScheduler
{
    // Caller owns the transaction and group row lock, including for soft-deleted rows.
    public function schedule(Group $group, bool $published): void
    {
        if ($group->public_site_resource_id === null || $group->public_site_resource_id < 1) {
            return;
        }
        $group->forceFill(['modx_publication_revision' => $group->modx_publication_revision + 1,
            'modx_publication_desired' => $published ? 'published' : 'unpublished',
            'modx_publication_status' => 'pending', 'modx_publication_requested_at' => now(),
            'modx_publication_started_at' => null, 'modx_publication_failed_at' => null,
            'modx_publication_error_code' => null])->save();
        $id = $group->id;
        $revision = $group->modx_publication_revision;
        $resourceId = $group->public_site_resource_id;
        $mode = $group->renewalHistoryId() !== null ? 'renewal' : ($group->disabled ? 'admin_restore' : 'resume');
        $identity = SetGroupModxPublication::identity($group);
        DB::afterCommit(function () use ($id, $revision, $resourceId, $mode, $identity): void {
            try {
                SetGroupModxPublication::dispatch($id, $revision, $resourceId, $mode, $identity)->onConnection('database');
            } catch (Throwable) {
                Group::withTrashed()->whereKey($id)->where('modx_publication_revision', $revision)
                    ->update(['modx_publication_status' => 'failed', 'modx_publication_failed_at' => now(),
                        'modx_publication_error_code' => 'queue_unavailable']);
            }
        });
    }
}
