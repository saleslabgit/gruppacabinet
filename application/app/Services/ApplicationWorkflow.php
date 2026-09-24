<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApplicationWorkflow
{
    public function setProcessed(string $groupId, string $applicationId, User $actor, bool $processed): GroupApplication
    {
        return DB::transaction(function () use ($groupId, $applicationId, $actor, $processed): GroupApplication {
            $group = Group::query()->visibleToPsychologist($actor->id)->lockForUpdate()->findOrFail($groupId);
            $application = $group->applications()->lockForUpdate()->findOrFail($applicationId);
            $application->setRelation('group', $group);
            Gate::forUser($actor)->authorize('process', $application);
            if (($application->processed_at !== null) !== $processed) {
                $application->update(['processed_at' => $processed ? now('UTC') : null]);
            }

            return $application;
        });
    }
}
