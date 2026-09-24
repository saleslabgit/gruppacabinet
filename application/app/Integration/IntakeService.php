<?php

namespace App\Integration;

use App\Enums\GroupStatus;
use App\Enums\UserStatus;
use App\Models\DictionaryItem;
use App\Models\Group;
use App\Models\IntegrationRequest;
use App\Models\User;
use App\Services\PsychologistDocuments;
use App\Services\UserStatusTransitionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class IntakeService
{
    public function submit(string $requestId, string $endpoint, IntakeData $data): Response
    {
        // Retry an insert race for a previously absent email. The active_email index
        // remains authoritative even if another writer does not use this service.
        for ($attempt = 0; ; $attempt++) {
            $paths = [];
            try {
                return DB::transaction(function () use ($requestId, $endpoint, $data, &$paths): Response {
                    $fingerprint = $data->fingerprint($endpoint);
                    // Atomic no-op upsert waits for an in-flight transaction with this ID.
                    DB::table('gp_integration_requests')->upsert([
                        'request_id' => $requestId, 'endpoint' => $endpoint,
                        'request_fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now(),
                    ], ['request_id'], ['request_id']);
                    $journal = IntegrationRequest::query()->where('request_id', $requestId)->lockForUpdate()->firstOrFail();
                    if ($journal->endpoint !== $endpoint || ! hash_equals($journal->request_fingerprint, $fingerprint)) {
                        throw new IntegrationException('idempotency_conflict', 409);
                    }
                    if ($journal->completed_at !== null) {
                        return new Response($journal->response_body, $journal->response_status, ['Content-Type' => 'application/json']);
                    }
                    $status = $endpoint === 'psychologists' ? $this->psychologist($data, $paths) : $this->application($data);
                    $response = response()->json(['data' => ['status' => $endpoint === 'psychologists' ? 'pending' : 'accepted'], 'request_id' => $requestId], $status);
                    $journal->update(['response_status' => $status, 'response_body' => $response->getContent(), 'completed_at' => now()]);

                    return $response;
                });
            } catch (Throwable $exception) {
                $cleanupFailed = false;
                foreach ($paths as $path) {
                    try {
                        if (! Storage::disk(config('psychologist_documents.disk'))->delete($path)) {
                            $cleanupFailed = true;
                        }
                    } catch (Throwable) {
                        $cleanupFailed = true;
                    }
                }
                if ($cleanupFailed) {
                    throw new RuntimeException('Private document rollback cleanup failed.', 0, $exception);
                }
                $retryable = $exception instanceof UniqueConstraintViolationException || ($exception instanceof QueryException && in_array($exception->errorInfo[1] ?? null, [1205, 1213], true));
                if (! $retryable || $attempt >= 4) {
                    throw $exception;
                }
            }
        }
    }

    private function application(IntakeData $data): int
    {
        $fields = $data->fields;
        $group = Group::query()->where('public_uuid', $fields['group_uuid'])->lockForUpdate()->first();
        if ($group === null) {
            throw new IntegrationException('group_not_found', 404);
        }
        if ($group->status !== GroupStatus::Active || $group->disabled) {
            throw new IntegrationException('group_not_accepting_applications', 422);
        }
        unset($fields['group_uuid']);
        $group->applications()->create($fields + ['processed_at' => null]);

        return 201;
    }

    private function psychologist(IntakeData $data, array &$paths): int
    {
        $fields = $data->fields;
        $trainings = $fields['trainings'];
        unset($fields['trainings']);
        $matches = User::query()->where('email', $fields['email'])->orderBy('id')->lockForUpdate()->get();
        foreach ($matches as $match) {
            if ($match->disabled || $match->status === UserStatus::Approved) {
                throw new IntegrationException('psychologist_conflict', 409);
            }
        }
        $user = $matches->first();
        $new = $user === null;
        $code = $fields['education_type_code'];
        unset($fields['education_type_code']);
        $fields['education_type_id'] = null;
        if ($code !== null) {
            $item = DictionaryItem::query()->whereHas('dictionary', fn ($query) => $query->where('code', 'education_type'))->where('code', $code)->sharedLock()->first();
            if ($item === null || (! $item->active && $user?->education_type_id !== $item->id)) {
                throw new IntegrationException('validation_failed', 422, ['education_type_code' => ['Select an available education code.']]);
            }
            $fields['education_type_id'] = $item->id;
        }
        $fields['personal_data_consent_at'] = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $fields['personal_data_consent_at'], 'UTC');
        if ($new) {
            $user = User::query()->create($fields + ['status' => UserStatus::Pending, 'admin' => false, 'disabled' => false, 'free' => false, 'password' => null]);
        } else {
            $user->fill($fields)->save();
            if ($user->status === UserStatus::Rejected) {
                app(UserStatusTransitionService::class)->transition($user, UserStatus::Pending);
            }
        }
        if (! $new) {
            // MySQL 8.0.46 can fail to re-prepare the ordered relationship DELETE.
            // Use direct DML on this transaction's connection, with only an integer ID.
            DB::unprepared('DELETE FROM `gp_user_trainings` WHERE `user_id` = '.(int) $user->id);
        }
        $currentTrainings = $user->trainings()->createMany($trainings)->keyBy('position');
        foreach ($data->documents as $descriptor) {
            $document = app(PsychologistDocuments::class)->upload($user, $data->files[$descriptor['field']], $descriptor['type'], $descriptor['original_name']);
            $paths[] = $document->path;
            if ($descriptor['training_position'] !== null) {
                $document->update(['user_training_id' => $currentTrainings[$descriptor['training_position']]->id]);
            }
        }

        return $new ? 201 : 200;
    }
}
