<?php

namespace App\Services;

use App\Models\Dictionary;
use App\Models\Group;
use App\Services\Modx\DictionaryClient;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GroupModxReadiness
{
    public function validate(Group $group): void
    {
        $group->load(['owner', 'format.dictionary', 'gender.dictionary', 'groupType.dictionary', 'approaches.dictionary', 'tags.dictionary']);
        $data = $group->getAttributes();
        $data['meeting_days'] = $group->meeting_days;
        $data['approach_ids'] = $group->approaches->modelKeys();
        $data['tag_ids'] = $group->tags->modelKeys();
        app(GroupContent::class)->prepare($data, $group, true);
        Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:16000'],
            'meeting_duration_minutes' => ['required', 'integer', 'between:1,4294967295'],
            'participant_capacity' => ['required', 'integer', 'between:1,4294967295'],
            'meeting_price' => ['required', 'integer', 'min:0'],
        ], ['required' => 'Заполните это поле.', 'integer' => 'Введите целое число.'])->validate();
        $errors = [];
        if ($group->meeting_price % 100 !== 0) {
            $errors['meeting_price'] = 'Для синхронизации укажите стоимость в целых BYN, без копеек.';
        }
        foreach (['first_name', 'last_name'] as $field) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $group->owner->$field ?? ''));
            if ($name === '') {
                $errors['owner_id'] = 'Заполните имя и фамилию психолога перед отправкой группы.';
            }
        }
        foreach (['format' => ['format_id', 'group_format'], 'gender' => ['gender_id', 'gender'],
            'groupType' => ['group_type_id', 'group_type'], 'approaches' => ['approach_ids', 'group_approach'],
            'tags' => ['tag_ids', 'group_tag']] as $relation => [$field, $code]) {
            $items = in_array($relation, ['approaches', 'tags'], true) ? $group->$relation : collect([$group->$relation]);
            foreach ($items as $item) {
                /** @var Dictionary|null $dictionary */
                $dictionary = $item?->dictionary;
                if (! $item || ! $item->active || $item->modx_value === null || trim($item->modx_value) === ''
                    || ($dictionary->code ?? null) !== $code
                    || ($dictionary->modx_tv_name ?? null) !== DictionaryClient::DEFINITIONS[$code]['tv']) {
                    $label = ['format_id' => 'Формат', 'gender_id' => 'Пол участников', 'group_type_id' => 'Тип группы',
                        'approach_ids' => 'Подходы', 'tag_ids' => 'Теги'][$field];
                    $errors[$field] = $label.': выберите доступное значение справочника, связанное с MODX.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        app(GroupCovers::class)->readVerified($group);
    }
}
