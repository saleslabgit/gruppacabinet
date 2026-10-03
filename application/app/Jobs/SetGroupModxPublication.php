<?php

namespace App\Jobs;

use App\Enums\GroupStatus;
use App\Exceptions\ModxGroupSyncException;
use App\Models\Group;
use App\Services\GroupLifecycleService;
use App\Services\GroupModxPublicationScheduler;
use App\Services\GroupStatusTransitionService;
use App\Services\Modx\GroupClient;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class SetGroupModxPublication implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    // Class defaults also apply when an older queued payload is unserialized.
    public string $mode = 'resume';

    public ?array $expectedIdentity = null;

    public function __construct(public int $groupId, public int $revision, public int $expectedResourceId, string $mode = 'resume', ?array $expectedIdentity = null)
    {
        $this->mode = $mode;
        $this->expectedIdentity = $expectedIdentity;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('modx-group:'.$this->groupId))->shared()->releaseAfter(60)->expireAfter(120)];
    }

    public function handle(GroupClient $client, GroupStatusTransitionService $transitions): void
    {
        try {
            $request = DB::transaction(function (): ?array {
                $group = Group::withTrashed()->lockForUpdate()->find($this->groupId);
                if (! $this->current($group)) {
                    return null;
                }
                $published = $group->modx_publication_desired === 'published';
                if ($published && (! $this->publishable($group) || ($this->expectedIdentity !== null && self::identity($group) !== $this->expectedIdentity))) {
                    if (app(GroupLifecycleService::class)->expireOne($group->id)) {
                        return null;
                    }
                    throw new ModxGroupSyncException('resume_unavailable');
                }
                $group->forceFill(['modx_publication_status' => 'syncing', 'modx_publication_started_at' => now()])->save();

                // Desired state is immutable within a revision; retries reproduce identical bytes.
                return ['body' => json_encode(['resource_id' => $this->expectedResourceId, 'published' => $published], JSON_THROW_ON_ERROR),
                    'key' => 'group-publication:'.$group->public_uuid.':'.$this->expectedResourceId.':'.$this->revision,
                    'published' => $published, 'identity' => self::identity($group)];
            });
            if ($request === null) {
                return;
            }
            $client->publication($request['body'], $request['key'], $this->expectedResourceId, $request['published']);
            DB::transaction(function () use ($request, $transitions): void {
                $group = Group::withTrashed()->lockForUpdate()->find($this->groupId);
                if (! $this->current($group) || ($group->modx_publication_desired === 'published') !== $request['published']) {
                    return;
                }
                if ($request['published']) {
                    if (! $this->publishable($group) || self::identity($group) !== $request['identity']) {
                        // Expiration schedules a newer unpublish revision after commit.
                        // A late publish response must not reactivate an expired placement.
                        if (app(GroupLifecycleService::class)->expireOne($group->id)) {
                            return;
                        }
                        app(GroupModxPublicationScheduler::class)->schedule($group, false);

                        return;
                    }
                    if ($this->mode === 'admin_restore') {
                        $group->update(['disabled' => false]);
                    } else {
                        if ($this->mode === 'renewal') {
                            $publishedAt = now()->utc();
                            $days = app(SettingService::class)->placementDurationDays();
                            $group->update(['published_at' => $publishedAt, 'placement_days' => $days,
                                'expires_at' => $publishedAt->copy()->addDays($days), 'expiry_warning_sent_at' => null]);
                        }
                        $group->forceFill(['paused_at' => null])->save();
                        // Only the current successful remote publication starts/resumes placement.
                        $group = $transitions->transition($group, GroupStatus::Active);
                    }
                }
                // The publication marker and lifecycle transition commit atomically.
                $group->forceFill(['modx_publication_status' => $group->modx_publication_desired,
                    'modx_publication_synced_at' => now(), 'modx_publication_failed_at' => null,
                    'modx_publication_error_code' => null])->save();
            });
        } catch (ModxGroupSyncException $exception) {
            $this->mark($exception->safeCode, $exception->retryable);
            if ($exception->retryable) {
                throw $exception;
            }
        } catch (Throwable) {
            $this->mark('local_failure', true);
            throw new ModxGroupSyncException('local_failure', true);
        }
    }

    private function current(?Group $group): bool
    {
        return $group !== null && $group->modx_publication_revision === $this->revision
            && $group->public_site_resource_id === $this->expectedResourceId
            && in_array($group->modx_publication_desired, ['published', 'unpublished'], true)
            && in_array($group->modx_publication_status, ['pending', 'syncing'], true);
    }

    public static function identity(Group $group): array
    {
        return [$group->status->value, $group->disabled, $group->renewalHistoryId(),
            $group->getRawOriginal('published_at'), $group->getRawOriginal('expires_at'),
            $group->placement_days, $group->getRawOriginal('paused_at')];
    }

    private function publishable(Group $group): bool
    {
        if ($group->trashed() || $group->psychologist_deleted_at !== null) {
            return false;
        }
        if ($this->mode === 'renewal') {
            return ! $group->disabled && $group->renewalHistoryId() !== null;
        }
        if ($group->expires_at === null || ! $group->expires_at->isFuture()) {
            return false;
        }

        return $this->mode === 'admin_restore'
            ? $group->status === GroupStatus::Active && $group->disabled
            : $group->status === GroupStatus::Paused && ! $group->disabled && $group->paused_at !== null;
    }

    public function failed(?Throwable $exception): void
    {
        $this->mark($exception instanceof ModxGroupSyncException ? $exception->safeCode : 'worker_failed', false);
    }

    private function mark(string $code, bool $retryable): void
    {
        $status = $retryable ? 'pending' : (in_array($code, ['idempotency_conflict', 'resource_id_conflict'], true) ? 'conflict' : 'failed');
        Group::withTrashed()->whereKey($this->groupId)->where('modx_publication_revision', $this->revision)
            ->where('public_site_resource_id', $this->expectedResourceId)->whereIn('modx_publication_status', ['pending', 'syncing'])
            ->update(['modx_publication_status' => $status, 'modx_publication_error_code' => $code,
                'modx_publication_failed_at' => $retryable ? null : now()]);
    }
}
