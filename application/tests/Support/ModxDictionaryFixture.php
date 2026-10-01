<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

class ModxDictionaryFixture
{
    public static function configure(): void
    {
        config(['services.modx.base_url' => 'https://modx.example.test/api/v1',
            'services.modx.token' => 'synthetic-modx-secret', 'services.modx.connect_timeout' => 3, 'services.modx.timeout' => 10]);
        Http::preventStrayRequests();
    }

    public static function document(): array
    {
        $dictionaries = [];
        foreach ([
            'format' => [35, 'listbox', 'Офлайн', 'Офлайн'],
            'gender' => [39, 'listbox', 'Смешанная', 'Смешанная'],
            'groupType' => [37, 'listbox', '17', 'Тестовый тип'],
            'approaches' => [36, 'listbox-multiple', '2', 'Тестовый подход'],
            'tags' => [30, 'listbox-multiple', '77', 'Тестовая метка'],
        ] as $tv => [$id, $type, $value, $label]) {
            $dictionaries[$tv] = ['tv_id' => $id, 'tv_name' => $tv, 'type' => $type,
                'options' => [['value' => $value, 'label' => $label, 'position' => 8]]];
        }

        return ['data' => ['complete' => true, 'dictionaries' => $dictionaries], 'meta' => []];
    }

    public static function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public static function fake(?array $document = null): void
    {
        self::resetHttp();
        Http::fake(['https://modx.example.test/api/v1/cabinet/dictionaries' => Http::response($document ?? self::document())]);
    }
}
