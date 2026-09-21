@extends('layouts.admin')
@section('content')
<form @if($prototype) data-prototype-form @else method="POST" action="{{ $formAction }}" @endif>
@if(!$prototype)
@csrf
@if(!$creating) @method('PUT') @endif
@endif
<x-validation-summary :errors="$errors" />
<x-panel title="Анкета">
<p class="meta mb-4">Email обязателен. Остальные поля могут быть не заполнены.</p>
<h3>Контактные данные</h3>
<div class="row">
<div class="col-md-6">
<x-input name="last_name" label="Фамилия" type="text" :value="$prototype ? ($variant === 'create' ? '' : $user['last_name']) : old('last_name', $user['last_name'])" :required="false" :error="$errors['last_name'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="first_name" label="Имя" type="text" :value="$prototype ? ($variant === 'create' ? '' : $user['first_name']) : old('first_name', $user['first_name'])" :required="false" :error="$errors['first_name'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="middle_name" label="Отчество" type="text" :value="$prototype ? ($variant === 'create' ? '' : $user['middle_name']) : old('middle_name', $user['middle_name'])" :required="false" :error="$errors['middle_name'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="phone" label="Телефон" type="tel" :value="$prototype ? ($variant === 'create' ? '' : $user['phone']) : old('phone', $user['phone'])" :required="false" :error="$errors['phone'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="email" label="Email" type="email" :value="$prototype ? ($variant === 'create' ? '' : $user['email']) : old('email', $user['email'])" :required="true" :error="$errors['email'] ?? null" />
</div>
</div>
<h3 class="mt-3">Образование и опыт</h3>
<div class="row">
<div class="col-md-6">
<x-select name="education_type_id" label="Тип образования" :error="$errors['education_type_id'] ?? null" :options="$prototype ? [''=>'Не указан','demo'=>$user['education_type']] : $educationOptions" :value="$prototype ? 'demo' : old('education_type_id', $user['education_type_id'])" />
</div>
<div class="col-md-6">
<x-input name="other_education" label="Другое образование" type="text" :value="$prototype ? ($variant === 'create' ? '' : $user['other_education']) : old('other_education', $user['other_education'])" :required="false" :error="$errors['other_education'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="modality_program" label="Модальность / программа" type="text" :value="$prototype ? ($variant === 'create' ? '' : $user['modality_program']) : old('modality_program', $user['modality_program'])" :required="false" :error="$errors['modality_program'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="training_center" label="Учебный центр" type="text" :value="$prototype ? ($variant === 'create' ? '' : $user['training_center']) : old('training_center', $user['training_center'])" :required="false" :error="$errors['training_center'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="graduation_year" label="Год окончания" type="number" :value="$prototype ? ($variant === 'create' ? '' : $user['graduation_year']) : old('graduation_year', $user['graduation_year'])" :required="false" :error="$errors['graduation_year'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="training_hours" label="Количество часов" type="number" :value="$prototype ? ($variant === 'create' ? '' : $user['training_hours']) : old('training_hours', $user['training_hours'])" :required="false" :error="$errors['training_hours'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="license_number" label="Номер лицензии" type="text" :value="$prototype ? ($variant === 'create' ? '' : $user['license_number']) : old('license_number', $user['license_number'])" :required="false" :error="$errors['license_number'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="license_expires_at" label="Лицензия действительна до" type="date" :value="$prototype ? ($variant === 'create' ? '' : $user['license_expires_at']) : old('license_expires_at', $user['license_expires_at'])" :required="false" :error="$errors['license_expires_at'] ?? null" />
</div>
<div class="col-md-6">
<x-textarea name="group_leading_experience" label="Опыт ведения групп" :value="$prototype ? ($variant === 'create' ? '' : $user['group_leading_experience']) : old('group_leading_experience', $user['group_leading_experience'])" :required="false" :error="$errors['group_leading_experience'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="groups_conducted_count" label="Количество проведённых групп" type="number" :value="$prototype ? ($variant === 'create' ? '' : $user['groups_conducted_count']) : old('groups_conducted_count', $user['groups_conducted_count'])" :required="false" :error="$errors['groups_conducted_count'] ?? null" />
</div>
</div>
<h3 class="mt-3">Подтверждения и согласие</h3>
<div class="row">
<div class="col-md-6">
<x-input name="personal_data_consent_version" label="Версия согласия" type="text" :value="$prototype ? ($variant === 'create' ? '' : $user['personal_data_consent_version']) : old('personal_data_consent_version', $user['personal_data_consent_version'])" :required="false" :error="$errors['personal_data_consent_version'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="personal_data_consent_at" label="Дата согласия, Минск" type="datetime-local" :value="$prototype ? '2026-08-11T12:00' : old('personal_data_consent_at', $user['personal_data_consent_at'])" :error="$errors['personal_data_consent_at'] ?? null" />
</div>
</div>
@if(!$prototype)<input type="hidden" name="documents_confirmed" value="0">@endif
<x-checkbox name="documents_confirmed" :error="$errors['documents_confirmed'] ?? null" label="Достоверность документов подтверждена" :checked="$prototype ? true : (bool) old('documents_confirmed', $user['documents_confirmed'])" />
@if(!$prototype)<input type="hidden" name="education_confirmed" value="0">@endif
<x-checkbox name="education_confirmed" label="Соответствие образования подтверждено" :checked="$prototype ? true : (bool) old('education_confirmed', $user['education_confirmed'])" />
@if(!$prototype)<input type="hidden" name="live_session_ready" value="0">@endif
<x-checkbox name="live_session_ready" label="Готовность провести вебинар или эфир" :checked="$prototype ? false : (bool) old('live_session_ready', $user['live_session_ready'])" />
</x-panel>
<x-panel title="Тариф и доступ">
<p>Статус анкеты меняется действиями модерации в карточке психолога.</p>
<x-status domain="user" :value="$variant === 'create' ? 'pending' : $user['status']" />
@if($prototype || $creating)
<fieldset class="mt-4">
<legend class="h3">Тариф</legend>
<x-radio name="free" value="1" label="Бесплатный" :checked="$prototype || (string) old('free', $user['free']) === '1'" />
<x-radio name="free" value="0" label="Платный" :checked="!$prototype && !(bool) old('free', $user['free'])" />
</fieldset>
@else
<p>Тариф и доступ меняются отдельными действиями в карточке психолога.</p>
@endif
@if($prototype)
<x-checkbox name="disabled" label="Отключить доступ к кабинету" :checked="$user['disabled']" />
@endif
</x-panel>
<div class="actions">
<x-button :type="$prototype ? 'button' : 'submit'" :data-noop="$prototype" :disabled="$variant === 'disabled'">Сохранить</x-button>
<x-button kind="ghost" :href="$links['admin-users']">Отмена</x-button>
</div>
</form>
@endsection
