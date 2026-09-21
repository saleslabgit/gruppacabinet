<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PsychologistDocuments
{
    public function response(UserDocument $document, string $disposition): StreamedResponse
    {
        $disk = Storage::disk(config('psychologist_documents.disk'));
        abort_unless($disk->exists($document->path), 404);
        abort_unless(in_array($document->mime_type, config('psychologist_documents.mime_types'), true), 404);

        return $disk->response($document->path, $this->safeName($document->original_name), [
            'Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'",
        ], $disposition);
    }

    public function upload(User $psychologist, UploadedFile $file, string $type, ?string $originalName = null): UserDocument
    {
        $disk = Storage::disk(config('psychologist_documents.disk'));
        // Know the random target before writing so even a partial write can be cleaned.
        $directory = 'psychologists/'.$psychologist->id;
        $name = $file->hashName();
        $path = $directory.'/'.$name;
        try {
            if ($disk->putFileAs($directory, $file, $name) === false) {
                throw new RuntimeException('Private document storage failed.');
            }

            return $psychologist->documents()->create([
                'type' => $type, 'path' => $path, 'original_name' => $this->safeName($originalName ?? $file->getClientOriginalName()),
                'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException('Private document cleanup failed.', 0, $exception);
            }
            throw $exception;
        }
    }

    public function delete(UserDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $locked = UserDocument::query()->lockForUpdate()->findOrFail($document->id);
            $disk = Storage::disk(config('psychologist_documents.disk'));
            // Keep the row available for retry if storage fails. An already absent file is safe to remove from the index.
            if ($disk->exists($locked->path) && ! $disk->delete($locked->path)) {
                throw new RuntimeException('Private document deletion failed.');
            }
            $locked->delete();
        });
    }

    public function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return mb_substr($name !== '' ? $name : 'document', 0, 255);
    }
}
