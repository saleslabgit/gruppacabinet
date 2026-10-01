<?php

namespace App\Services\Modx;

use App\Exceptions\ModxDictionarySyncException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class DictionaryClient
{
    public const DEFINITIONS = [
        'group_format' => ['tv' => 'format', 'type' => 'listbox', 'name' => 'Group format'],
        'gender' => ['tv' => 'gender', 'type' => 'listbox', 'name' => 'Gender'],
        'group_type' => ['tv' => 'groupType', 'type' => 'listbox', 'name' => 'Group type'],
        'group_approach' => ['tv' => 'approaches', 'type' => 'listbox-multiple', 'name' => 'Approaches'],
        'group_tag' => ['tv' => 'tags', 'type' => 'listbox-multiple', 'name' => 'Tags'],
    ];

    /** @return array<string, list<array{value: string, label: string, position: int}>> */
    public function fetch(): array
    {
        $base = config('services.modx.base_url');
        $token = config('services.modx.token');
        $connect = filter_var(config('services.modx.connect_timeout'), FILTER_VALIDATE_INT);
        $timeout = filter_var(config('services.modx.timeout'), FILTER_VALIDATE_INT);
        if (! is_string($base) || ! filter_var($base, FILTER_VALIDATE_URL)
            || parse_url($base, PHP_URL_SCHEME) !== 'https'
            || parse_url($base, PHP_URL_USER) !== null || parse_url($base, PHP_URL_PASS) !== null
            || parse_url($base, PHP_URL_QUERY) !== null || parse_url($base, PHP_URL_FRAGMENT) !== null
            || ! is_string($token) || trim($token) === '' || preg_match('/[\r\n]/', $token)
            || $connect === false || $timeout === false || $connect < 1 || $timeout < $connect || $timeout > 60) {
            throw new ModxDictionarySyncException('Не настроено подключение к MODX.');
        }

        try {
            $response = Http::acceptJson()->withToken($token)->withoutRedirecting()
                ->connectTimeout($connect)->timeout($timeout)
                ->get(rtrim($base, '/').'/cabinet/dictionaries');
        } catch (Throwable) {
            // Do not retain the original exception: it can contain URL, headers or body.
            throw new ModxDictionarySyncException('Не удалось подключиться к MODX.');
        }
        if (! $response->successful()) {
            throw new ModxDictionarySyncException('MODX вернул ошибку HTTP.');
        }
        try {
            $document = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ModxDictionarySyncException('Некорректный JSON от MODX.');
        }
        if (! is_object($document) || ! is_object($document->data ?? null)
            || ($document->data->complete ?? null) !== true || ! is_object($document->data->dictionaries ?? null)) {
            throw new ModxDictionarySyncException('Неполный или некорректный набор справочников MODX.');
        }

        $result = [];
        foreach (self::DEFINITIONS as $code => $definition) {
            $dictionary = $document->data->dictionaries->{$definition['tv']} ?? null;
            if (! is_object($dictionary) || ($dictionary->tv_name ?? null) !== $definition['tv']
                || ($dictionary->type ?? null) !== $definition['type'] || ! is_array($dictionary->options ?? null)) {
                throw new ModxDictionarySyncException('Некорректное определение справочника MODX.');
            }
            $options = [];
            $seen = [];
            foreach ($dictionary->options as $option) {
                if (! is_object($option) || ! is_string($option->value ?? null) || ! is_string($option->label ?? null)
                    || preg_match('/\A\s*\z/u', $option->value) || preg_match('/\A\s*\z/u', $option->label)
                    || mb_strlen($option->value) > 255 || mb_strlen($option->label) > 255
                    || ! is_int($option->position ?? null) || $option->position < 0 || $option->position > 4294967295
                    || in_array($option->value, $seen, true)) {
                    throw new ModxDictionarySyncException('Некорректный или повторяющийся вариант MODX.');
                }
                $seen[] = $option->value;
                $options[] = ['value' => $option->value, 'label' => $option->label, 'position' => $option->position];
            }
            $result[$code] = $options;
        }

        return $result;
    }
}
