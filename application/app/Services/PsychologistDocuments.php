<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PsychologistDocuments
{
    public function upload(User $psychologist, UploadedFile $file, string $type): UserDocument
    {
        $disk = Storage::disk(config('psychologist_documents.disk'));
        $path = $disk->putFile('psychologists/'.$psychologist->id, $file);
        if ($path === false) {
            throw new RuntimeException('Private document storage failed.');
        }
        try {
            return $psychologist->documents()->create([
                'type' => $type, 'path' => $path, 'original_name' => $this->safeName($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            if (! $disk->delete($path)) {
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
