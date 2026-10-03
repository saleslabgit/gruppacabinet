@php
$real = $realGroups ?? false;
$complete = $admin && ($creating ?? false);
$weekdayOptions = $weekdayOptions ?? \App\Services\GroupContent::DAYS;
@endphp
@if($variant === 'revision')
<x-alert tone="warning" title="Доработайте описание">
<p>{{ $group['moderator_comment'] }}</p>
</x-alert>
@endif
@if($admin)
<x-alert tone="warning">Администратор может редактировать группу независимо от статуса. Изменения содержимого одобренной группы или группы с MODX Resource ID синхронизируются через очередь. Статус синхронизации доступен в карточке группы.</x-alert>
@endif
<form class="editor-form" enctype="multipart/form-data" @if($realGroups ?? false) method="POST" action="{{ $formAction }}" @else data-prototype-form @endif>
@if($realGroups ?? false)
@csrf
@endif
<x-validation-summary :errors="$errors" />
<x-panel title="Основная информация">
@if($admin)
@if(($realGroups ?? false) && ! $creating)
<p>Психолог: {{ $user['name'] }}</p>
@else
<x-select name="owner_id" label="Психолог" :options="($realGroups ?? false) ? ['' => 'Выберите психолога'] + $ownerOptions : ['demo' => $user['name']]" :value="old('owner_id')" :required="true" :error="$errors['owner_id'] ?? null" />
@endif
@endif
<x-input name="title" label="Название группы" :value="($realGroups ?? false) ? old('title', $group['title']) : ($variant === 'create' ? '' : $group['title'])" :required="true" help="Короткое название, которое увидят участники." :error="$errors['title'] ?? null" />
<x-textarea name="description" label="Краткое описание" :value="($realGroups ?? false) ? old('description', $group['description']) : ($variant === 'create' ? '' : $group['description'])" :required="true" help="Для кого группа и с какими темами вы работаете." :error="$errors['description'] ?? null" />
<x-rich-text name="full_description_html" label="Полное описание" :value="$real ? old('full_description_html', $group['full_description_html']) : ($group['full_description_html'] ?? '')" :required="$complete" :error="$errors['full_description_html'] ?? null" />
<x-input name="cover" label="Обложка группы" type="file" accept="image/jpeg,image/png,image/webp" :required="$complete" :help="'JPEG, PNG или WebP, до '.config('groups.cover_max_kb').' КБ. Повторная загрузка заменяет обложку.'" :error="$errors['cover'] ?? null" />
@if($coverUrl ?? null)<a href="{{ $coverUrl }}"><img class="group-cover" src="{{ $coverUrl }}" alt="Текущая обложка группы" loading="lazy"></a>@endif
<fieldset class="field">
<legend class="form-label">Дни недели</legend>
<input type="hidden" name="meeting_days" value="">
<div class="weekday-options">
@foreach($weekdayOptions as $code => $label)
<label class="form-check"><input class="form-check-input" type="checkbox" name="meeting_days[]" value="{{ $code }}" @checked(in_array($code, $real ? (array) old('meeting_days', $group['meeting_days'] ?? []) : ($group['meeting_days'] ?? []), true))> {{ $label }}</label>
@endforeach
</div>
<x-validation-error name="meeting_days" :error="$errors['meeting_days'] ?? null" />
</fieldset>
<div class="row">
<div class="col-md-6"><x-input name="start_time" label="Время начала, Минск" type="time" step="60" :value="$real ? old('start_time', $group['start_time']) : ($group['start_time'] ?? '')" :required="$complete" :error="$errors['start_time'] ?? null" /></div>
<div class="col-md-6"><x-input name="frequency" label="Периодичность" :value="$real ? old('frequency', $group['frequency']) : ($group['frequency'] ?? '')" :required="$complete" :error="$errors['frequency'] ?? null" /></div>
<div class="col-md-6"><x-input name="city" label="Город" :value="$real ? old('city', $group['city']) : ($group['city'] ?? '')" :required="$complete" :error="$errors['city'] ?? null" /></div>
</div>
@if(empty($group['meeting_days']) && empty($group['start_time']) && !empty($group['schedule']))
<p class="meta">Прежнее расписание: {{ $group['schedule'] }}. Перед отправкой на модерацию заполните дни и время выше.</p>
@endif
</x-panel>
<x-panel title="Условия участия">
@if(($realGroups ?? false) && (count($formatOptions) === 1 || count($genderOptions) === 1 || count($groupTypeOptions) === 1 || !$approachesOptions || !$tagsOptions))
<x-alert tone="warning">Отправка на модерацию или создание полной группы невозможны: нужные варианты справочников отсутствуют в Cabinet. Обратитесь к администратору для обновления справочников. Сохранение доступных полей черновика остаётся возможным.</x-alert>
@endif
<div class="row">
<div class="col-md-6">
<x-select name="format_id" label="Формат" :options="$formatOptions ?? ['' => 'Выберите формат', 'demo' => $group['format']]" :value="($realGroups ?? false) ? old('format_id', $group['format_id']) : $group['format_id']" :required="true" :error="$errors['format_id'] ?? null" />
</div>
<div class="col-md-6">
<x-select name="gender_id" label="Пол участников" :options="$genderOptions ?? ['' => 'Выберите значение', 'demo' => $group['gender']]" :value="($realGroups ?? false) ? old('gender_id', $group['gender_id']) : $group['gender_id']" :required="true" :error="$errors['gender_id'] ?? null" />
</div>
<div class="col-md-6">
<x-select name="group_type_id" label="Тип группы" :options="$groupTypeOptions ?? ['' => 'Выберите тип', 'demo' => 'Тип · пример']" :value="$real ? old('group_type_id', $group['group_type_id']) : ($group['group_type_id'] ?? '')" :required="$complete" :error="$errors['group_type_id'] ?? null" />
</div>
<div class="col-12">
<x-multi-select name="approach_ids" label="Подходы" :options="$approachesOptions ?? ['demo' => 'Подход · пример']" :values="$real ? (array) old('approach_ids', $group['approach_ids']) : ($group['approach_ids'] ?? [])" :required="$complete" :error="$errors['approach_ids'] ?? null" />
<x-multi-select name="tag_ids" label="Теги" :options="$tagsOptions ?? ['demo' => 'Отношения · пример']" :values="$real ? (array) old('tag_ids', $group['tag_ids']) : ($group['tag_ids'] ?? [])" :required="$complete" :error="$errors['tag_ids'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="meeting_duration_minutes" label="Длительность встречи, минут" type="number" min="1" step="1" :value="($realGroups ?? false) ? old('meeting_duration_minutes', $group['meeting_duration_minutes']) : $group['meeting_duration_minutes']" :required="true" :error="$errors['meeting_duration_minutes'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="participant_capacity" label="Количество участников" type="number" min="1" step="1" :value="($realGroups ?? false) ? old('participant_capacity', $group['participant_capacity']) : $group['participant_capacity']" :required="true" :error="$errors['participant_capacity'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="meeting_price" label="Стоимость встречи, BYN" inputmode="decimal" :value="($realGroups ?? false) ? old('meeting_price', $priceInput) : '35,00'" :required="true" :help="($realGroups ?? false) ? 'Цена одной встречи для участника.' : 'Цена одной встречи для участника. Демонстрационная сумма.'" :error="$errors['meeting_price'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="meeting_price_currency" label="Стоимость встречи (В валюте)" maxlength="255" :value="$real ? old('meeting_price_currency', $group['meeting_price_currency']) : ($group['meeting_price_currency'] ?? '')" help="Цена одной встречи с указанием валюты" :error="$errors['meeting_price_currency'] ?? null" />
</div>
</div>
</x-panel>
@if($admin && ($realGroups ?? false) && !$creating)
<x-panel title="Даты размещения">
<p>Время по Минску (Europe/Minsk). Коррекция дат не меняет статус и длительность продления. Оставьте поле пустым, если дата не задана.</p>
<div class="row">
<div class="col-md-6">
<x-input name="published_at" label="Дата публикации, Минск" type="datetime-local" step="1" :value="old('published_at', $published_atInput)" :error="$errors['published_at'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="expires_at" label="Дата окончания, Минск" type="datetime-local" step="1" :value="old('expires_at', $expires_atInput)" :error="$errors['expires_at'] ?? null" />
</div>
</div>
</x-panel>
@endif
<div class="actions">
@if($realGroups ?? false)
@unless($admin)
<x-button icon="send" type="submit" :formaction="route('psychologist.groups.submit', $group['id'])">Отправить на модерацию</x-button>
@endunless
<x-button icon="check-lg" type="submit" :kind="$admin ? 'primary' : 'secondary'" :name="$creating ? null : '_method'" :value="$creating ? null : 'PUT'">{{ $admin ? 'Сохранить' : 'Сохранить изменения' }}</x-button>
@else
<x-button :icon="$admin ? 'check-lg' : 'send'" data-noop :disabled="$variant === 'disabled'">{{ $admin ? 'Сохранить' : 'Отправить на модерацию' }}</x-button>
@unless($admin)
<x-button icon="check-lg" kind="secondary" data-noop :disabled="$variant === 'disabled'">Сохранить черновик</x-button>
@endunless
@endif
<x-button icon="arrow-left" kind="ghost" :href="(($creating ?? false) || $variant === 'create') ? $links[$admin ? 'admin-groups' : 'groups'] : (($realGroups ?? false) ? route($admin ? 'admin.groups.show' : 'psychologist.groups.show', $group['id']) : $links[$admin ? 'admin-group' : 'group'])">Отмена</x-button>
</div>
</form>

@if($variant === 'revision')@include('shared.group-history')@endif
