<?php

namespace Tests\Support;

use App\Models\Dictionary;
use App\Models\DictionaryItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class GroupContentFixture
{
    // Synthetic one-pixel images, decoded in the test temporary directory only.
    public static function cover(string $type = 'png'): UploadedFile
    {
        $bytes = match ($type) {
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOC8AAAAASUVORK5CYII=',
            'jpg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=',
            'webp' => 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
        };

        return UploadedFile::fake()->createWithContent('synthetic.'.$type, base64_decode($bytes));
    }

    public static function item(string $code, bool $active = true): DictionaryItem
    {
        $dictionary = Dictionary::firstOrCreate(['code' => $code], ['name' => $code]);

        return $dictionary->items()->create(['code' => (string) Str::uuid(), 'name' => 'Synthetic '.$code, 'active' => $active, 'modx_value' => (string) Str::uuid()]);
    }

    public static function fields(): array
    {
        return ['full_description_html' => '<p>Полное описание <strong>группы</strong>.</p>',
            'meeting_days' => ['wed', 'mon'], 'start_time' => '19:00', 'frequency' => ' Еженедельно ', 'city' => ' Минск ',
            'group_type_id' => self::item('group_type')->id,
            'approach_ids' => [self::item('group_approach')->id], 'tag_ids' => [self::item('group_tag')->id],
            'cover' => self::cover()];
    }
}
