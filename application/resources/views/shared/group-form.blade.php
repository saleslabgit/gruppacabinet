@if($variant === 'revision')
<x-alert tone="warning" title="Доработайте описание">
<p>{{ $group['moderator_comment'] }}</p>
</x-alert>
@include('shared.group-history')@endif
@if($admin)
<x-alert tone="warning">Администратор может редактировать группу независимо от статуса. Изменения опубликованной группы необходимо вручную перенести в каталог.</x-alert>
@endif
<form data-prototype-form>
<x-validation-summary :errors="array_intersect_key($errors, array_flip(['title','description','schedule','format_id','meeting_duration_minutes','participant_capacity','gender_id','meeting_price']))" />
<x-panel title="Основная информация">
@if($admin)
<x-select name="owner_id" label="Психолог" :options="['demo' => $user['name']]" :required="true" />
@endif
<x-input name="title" label="Название группы" :value="$variant === 'create' ? '' : $group['title']" :required="true" help="Короткое название, которое увидят участники." :error="$errors['title'] ?? null" />
<x-textarea name="description" label="Описание" :value="$variant === 'create' ? '' : $group['description']" :required="true" help="Для кого группа и с какими темами вы работаете." :error="$errors['description'] ?? null" />
<x-textarea name="schedule" label="Расписание" :value="$group['schedule']" :required="true" help="Дни недели и время встреч по Минску." :error="$errors['schedule'] ?? null" />
</x-panel>
<x-panel title="Условия участия">
<div class="row">
<div class="col-md-6">
<x-select name="format_id" label="Формат" :options="['' => 'Выберите формат', 'demo' => $group['format']]" :value="$group['format_id']" :required="true" :error="$errors['format_id'] ?? null" />
</div>
<div class="col-md-6">
<x-select name="gender_id" label="Пол участников" :options="['' => 'Выберите значение', 'demo' => $group['gender']]" :value="$group['gender_id']" :required="true" :error="$errors['gender_id'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="meeting_duration_minutes" label="Длительность встречи, минут" type="number" min="1" step="1" :value="$group['meeting_duration_minutes']" :required="true" :error="$errors['meeting_duration_minutes'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="participant_capacity" label="Количество участников" type="number" min="1" step="1" :value="$group['participant_capacity']" :required="true" :error="$errors['participant_capacity'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="meeting_price" label="Стоимость встречи, BYN" inputmode="decimal" value="35,00" :required="true" help="Цена одной встречи для участника. Демонстрационная сумма." :error="$errors['meeting_price'] ?? null" />
</div>
</div>
</x-panel>
<div class="actions">
<x-button data-noop :disabled="$variant === 'disabled'">{{ $admin ? 'Сохранить' : 'Отправить на модерацию' }}</x-button>
@unless($admin)
<x-button kind="secondary" data-noop :disabled="$variant === 'disabled'">Сохранить черновик</x-button>
@endunless
<x-button kind="ghost" :href="$links[$admin ? 'admin-groups' : 'groups']">Отмена</x-button>
</div>
</form>
