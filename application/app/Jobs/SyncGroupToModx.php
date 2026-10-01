<?php

namespace App\Jobs;

use App\Enums\GroupStatus;
use App\Exceptions\ModxGroupSyncException;
use App\Models\Group;
use App\Services\GroupModxPayloadBuilder;
use App\Services\Modx\GroupClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncGroupToModx implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(public int $groupId, public int $revision) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('modx-group:'.$this->groupId))->shared()->releaseAfter(60)->expireAfter(120)];
    }

    public function handle(GroupModxPayloadBuilder $builder, GroupClient $client): void
    {
        try {
            $request = DB::transaction(function () use ($builder): ?array {
                $group = Group::query()->lockForUpdate()->find($this->groupId);
                if (! $group || $group->modx_sync_revision !== $this->revision
                    || ($group->status === GroupStatus::Paused && $group->public_site_resource_id === null)
                    || ! in_array($group->status, [GroupStatus::Approved, GroupStatus::Active, GroupStatus::Paused, GroupStatus::Expired], true)
                    || in_array($group->modx_sync_status, ['synced', 'failed', 'conflict'], true)) {
                    return null;
                }
                $payload = $builder->build($group);
                $key = $group->public_site_resource_id === null ? 'group-create:'.$group->public_uuid
                    : 'group-update:'.$group->public_uuid.':'.$this->revision;
                // Rebuild from current data, but never send different bytes for an attempted key.
                if ($group->modx_sync_request_key === $key && $group->modx_sync_payload_hash !== $payload['hash']) {
                    throw new ModxGroupSyncException('idempotency_conflict');
                }
                $group->forceFill(['modx_sync_status' => 'syncing', 'modx_sync_started_at' => now(),
                    'modx_sync_request_key' => $key, 'modx_sync_payload_hash' => $payload['hash']])->save();

                return $payload + ['key' => $key];
            });
            if ($request === null) {
                return;
            }
            // No local transaction or row lock crosses the network boundary.
            $result = $client->sync($request['body'], $request['key']);
            DB::transaction(function () use ($request, $result): void {
                $group = Group::withTrashed()->lockForUpdate()->find($this->groupId);
                if (! $group) {
                    return;
                }
                if ($group->public_site_resource_id !== null && $group->public_site_resource_id !== $result['resource_id']) {
                    throw new ModxGroupSyncException('resource_id_conflict');
                }
                $group->public_site_resource_id = $result['resource_id'];
                if ($request['cover_source'] !== null && ! empty($result['cover_path'])) {
                    $group->modx_remote_cover_path = $result['cover_path'];
                    $group->modx_remote_cover_source_path = $request['cover_source'];
                }
                $group->modx_cover_cleanup_warning = $result['cover_cleanup_warning'];
                $group->modx_sync_payload_hash = $request['hash'];
                if ($group->modx_sync_revision === $this->revision) {
                    $group->forceFill(['modx_sync_status' => 'synced', 'modx_synced_at' => now(),
                        'modx_sync_failed_at' => null, 'modx_sync_error_code' => null]);
                } else {
                    $group->modx_sync_status = 'pending';
                }
                $group->save();
            });
        } catch (ValidationException) {
            $this->markFailed('not_sync_ready');
        } catch (ModxGroupSyncException $exception) {
            if ($exception->retryable) {
                $this->markPending($exception->safeCode);
                throw $exception;
            }
            $this->markFailed($exception->safeCode);
        } catch (Throwable) {
            // SQL/storage exceptions can contain private values. Never retain their trace chain.
            $this->markPending('local_failure');
            throw new ModxGroupSyncException('local_failure', true);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception instanceof ModxGroupSyncException ? $exception->safeCode : 'worker_failed');
    }

    private function markPending(string $code): void
    {
        Group::query()->whereKey($this->groupId)->where('modx_sync_revision', $this->revision)->where('modx_sync_status', '!=', 'synced')
            ->update(['modx_sync_status' => 'pending', 'modx_sync_error_code' => $code]);
    }

    private function markFailed(string $code): void
    {
        Group::query()->whereKey($this->groupId)->where('modx_sync_revision', $this->revision)->where('modx_sync_status', '!=', 'synced')
            ->update(['modx_sync_status' => in_array($code, ['idempotency_conflict', 'resource_id_conflict'], true) ? 'conflict' : 'failed',
                'modx_sync_error_code' => $code, 'modx_sync_failed_at' => now()]);
    }
}
