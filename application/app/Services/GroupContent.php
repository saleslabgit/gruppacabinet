<?php

namespace App\Services;

use App\Models\DictionaryItem;
use App\Models\Group;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GroupContent
{
    public const DAYS = ['mon' => 'Понедельник', 'tue' => 'Вторник', 'wed' => 'Среда', 'thu' => 'Четверг',
        'fri' => 'Пятница', 'sat' => 'Суббота', 'sun' => 'Воскресенье'];

    public const FIELDS = ['full_description_html', 'meeting_days', 'start_time', 'frequency', 'city', 'group_type_id', 'approach_ids', 'tag_ids', 'cover'];

    public function rules(?Group $group, bool $complete): array
    {
        $scalar = $complete ? ['required'] : ['sometimes', 'nullable'];
        $rules = [
            'full_description_html' => [...$scalar, 'string', 'max:'.config('groups.html_max_characters')],
            'meeting_days' => [$complete ? 'required' : 'sometimes', 'array', 'max:7', ...($complete ? ['min:1'] : [])],
            'meeting_days.*' => ['required', 'string', 'distinct', Rule::in(array_keys(self::DAYS))],
            'start_time' => [...$scalar, 'string', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/'],
            'frequency' => [...$scalar, 'string', 'max:255'],
            'city' => [...$scalar, 'string', 'max:255'],
            'cover' => [$complete && ! $group?->cover_path ? 'required' : 'nullable', ...GroupCovers::rules()],
        ];
        $rules['group_type_id'] = [...$scalar, 'integer', Rule::in($this->choices('group_type', $group?->group_type_id ? [$group->group_type_id] : []))];
        foreach (['approach_ids' => ['approaches', 'group_approach'], 'tag_ids' => ['tags', 'group_tag']] as $field => [$relation, $code]) {
            $current = $group?->exists ? $group->$relation()->pluck('gp_dictionary_items.id')->all() : [];
            $rules[$field] = [$complete ? 'required' : 'sometimes', 'array', ...($complete ? ['min:1'] : [])];
            $rules[$field.'.*'] = ['required', 'integer', 'distinct', Rule::in($this->choices($code, $current))];
        }

        return $rules;
    }

    public function prepare(array $data, ?Group $group, bool $complete): array
    {
        $content = Arr::only($data, self::FIELDS);
        foreach (['frequency', 'city'] as $field) {
            if (isset($content[$field]) && is_string($content[$field])) {
                $content[$field] = trim($content[$field]);
            }
        }
        $content = Validator::make($content, $this->rules($group, $complete), [
            'required' => 'Заполните это поле.', 'in' => 'Выберите доступное значение.',
            'distinct' => 'Значения не должны повторяться.', 'min' => 'Выберите хотя бы одно значение.',
            'start_time.regex' => 'Введите время в формате ЧЧ:ММ.',
        ])->validate();
        if (isset($content['full_description_html'])) {
            $content['full_description_html'] = app(GroupHtmlSanitizer::class)->sanitize($content['full_description_html']);
        }
        if (isset($content['meeting_days'])) {
            $content['meeting_days'] = array_values(array_intersect(array_keys(self::DAYS), $content['meeting_days']));
        }

        return array_replace($data, $content);
    }

    private function choices(string $code, array $current): array
    {
        return DictionaryItem::query()->whereHas('dictionary', fn ($q) => $q->where('code', $code))
            ->where(fn ($q) => $q->where('active', true)->orWhereIn('id', $current))->pluck('id')->all();
    }
}
