<?php

namespace App\Services;

use App\Models\Group;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class GroupCovers
{
    public static function rules(): array
    {
        return ['file', 'mimetypes:'.implode(',', config('groups.cover_mime_types')), 'max:'.config('groups.cover_max_kb'),
            function (string $attribute, mixed $file, Closure $fail): void {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $image = @getimagesize($file->getPathname());
                    if ($image === false || ! in_array($image['mime'], config('groups.cover_mime_types'), true)) {
                        $fail('Загрузите корректное изображение JPEG, PNG или WebP.');
                    }
                }
            }];
    }

    /** @param Closure(?array): array{Group, ?string} $write */
    public function persist(?UploadedFile $file, Closure $write): Group
    {
        $metadata = $file ? $this->store($file) : null;
        try {
            [$group, $oldPath] = DB::transaction(fn () => $write($metadata));
        } catch (Throwable $exception) {
            if ($metadata) {
                $this->remove($metadata['cover_path'], 'Не удалось удалить новую обложку после отмены сохранения.');
            }
            throw $exception;
        }
        if ($metadata && $oldPath) {
            // Callback errors propagate, so failed cleanup is never reported as success.
            DB::afterCommit(fn () => $this->remove($oldPath, 'Изменения сохранены, но не удалось удалить предыдущую обложку.'));
        }

        return $group;
    }

    private function store(UploadedFile $file): array
    {
        Validator::make(['cover' => $file], ['cover' => ['required', ...self::rules()]])->validate();
        $disk = Storage::disk(config('groups.cover_disk'));
        $name = Str::random(40).'.'.match ($file->getMimeType()) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['cover' => 'Недопустимый тип изображения.']),
        };
        $path = 'group-covers/'.$name;
        try {
            if ($disk->putFileAs('group-covers', $file, $name) === false) {
                throw ValidationException::withMessages(['cover' => 'Не удалось сохранить обложку.']);
            }
        } catch (Throwable) {
            $this->remove($path, 'Не удалось очистить незавершённую загрузку обложки.');
            throw ValidationException::withMessages(['cover' => 'Не удалось сохранить обложку.']);
        }

        return ['cover_path' => $path, 'cover_original_name' => $this->safeName($file->getClientOriginalName()),
            'cover_mime_type' => $file->getMimeType(), 'cover_size' => $file->getSize()];
    }

    private function remove(string $path, string $message): void
    {
        try {
            $disk = Storage::disk(config('groups.cover_disk'));
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw ValidationException::withMessages(['cover' => $message]);
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['cover' => $message]);
        }
    }

    public function readVerified(#[\SensitiveParameter] Group $group): string
    {
        try {
            $disk = Storage::disk(config('groups.cover_disk'));
            $limit = min(5242880, (int) config('groups.cover_max_kb') * 1024);
            if (! $group->cover_path || ! $group->cover_original_name || ! $disk->exists($group->cover_path)
                || $group->cover_size < 1 || $group->cover_size > $limit
                || $disk->size($group->cover_path) !== $group->cover_size) {
                throw new \RuntimeException;
            }
            $bytes = $disk->get($group->cover_path);
            $image = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || strlen($bytes) !== $group->cover_size || $image === false
                || ! in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
                || ! in_array($image['mime'], config('groups.cover_mime_types'), true)
                || $image['mime'] !== $group->cover_mime_type
                || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $group->cover_mime_type) {
                throw new \RuntimeException;
            }

            return $bytes;
        } catch (Throwable) {
            throw ValidationException::withMessages(['cover' => 'Обложка отсутствует или повреждена. Загрузите JPEG, PNG или WebP до 5 МиБ.']);
        }
    }

    public function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';

        return mb_substr($name !== '' ? $name : 'cover', 0, 255);
    }

    public function response(Group $group): StreamedResponse
    {
        $disk = Storage::disk(config('groups.cover_disk'));
        abort_unless($group->cover_path && $disk->exists($group->cover_path), 404);
        abort_unless(in_array($group->cover_mime_type, config('groups.cover_mime_types'), true), 404);

        return $disk->response($group->cover_path, $this->safeName($group->cover_original_name), [
            'Content-Type' => $group->cover_mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'",
        ], 'inline');
    }
}
