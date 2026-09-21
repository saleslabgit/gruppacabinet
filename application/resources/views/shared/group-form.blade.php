@if($variant === 'revision')
<x-alert tone="warning" title="Доработайте описание">
<p>{{ $group['moderator_comment'] }}</p>
</x-alert>
@include('shared.group-history')@endif
@if($admin)
<x-alert tone="warning">Администратор может редактировать группу независимо от статуса. Изменения опубликованной группы необходимо вручную перенести в каталог.</x-alert>
@endif
<form class="editor-form" @if($realGroups ?? false) method="POST" action="{{ $formAction }}" @else data-prototype-form @endif>
@if($realGroups ?? false)
@csrf
@endif
<x-validation-summary :errors="array_intersect_key($errors, array_flip(['owner_id','title','description','schedule','format_id','meeting_duration_minutes','participant_capacity','gender_id','meeting_price']))" />
<x-panel title="Основная информация">
@if($admin)
@if(($realGroups ?? false) && ! $creating)
<p>Психолог: {{ $user['name'] }}</p>
@else
<x-select name="owner_id" label="Психолог" :options="($realGroups ?? false) ? ['' => 'Выберите психолога'] + $ownerOptions : ['demo' => $user['name']]" :value="old('owner_id')" :required="true" :error="$errors['owner_id'] ?? null" />
@endif
@endif
<x-input name="title" label="Название группы" :value="($realGroups ?? false) ? old('title', $group['title']) : ($variant === 'create' ? '' : $group['title'])" :required="true" help="Короткое название, которое увидят участники." :error="$errors['title'] ?? null" />
<x-textarea name="description" label="Описание" :value="($realGroups ?? false) ? old('description', $group['description']) : ($variant === 'create' ? '' : $group['description'])" :required="true" help="Для кого группа и с какими темами вы работаете." :error="$errors['description'] ?? null" />
<x-textarea name="schedule" label="Расписание" :value="($realGroups ?? false) ? old('schedule', $group['schedule']) : $group['schedule']" :required="true" help="Дни недели и время встреч по Минску." :error="$errors['schedule'] ?? null" />
</x-panel>
<x-panel title="Условия участия">
@if(($realGroups ?? false) && (count($formatOptions) === 1 || count($genderOptions) === 1))
<x-alert tone="warning">Для заполнения группы нужны доступные значения формата и пола участников. Обратитесь к администратору для настройки справочников.</x-alert>
@endif
<div class="row">
<div class="col-md-6">
<x-select name="format_id" label="Формат" :options="$formatOptions ?? ['' => 'Выберите формат', 'demo' => $group['format']]" :value="($realGroups ?? false) ? old('format_id', $group['format_id']) : $group['format_id']" :required="true" :error="$errors['format_id'] ?? null" />
</div>
<div class="col-md-6">
<x-select name="gender_id" label="Пол участников" :options="$genderOptions ?? ['' => 'Выберите значение', 'demo' => $group['gender']]" :value="($realGroups ?? false) ? old('gender_id', $group['gender_id']) : $group['gender_id']" :required="true" :error="$errors['gender_id'] ?? null" />
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
</div>
</x-panel>
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
