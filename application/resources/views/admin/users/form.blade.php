@extends('layouts.admin')
@section('content')
<form data-prototype-form>
<x-validation-summary :errors="array_intersect_key($errors, array_flip(['email','graduation_year','training_hours','license_expires_at','education_type_id','documents_confirmed']))" />
<x-panel title="Анкета">
<p class="meta mb-4">Email обязателен. Остальные поля могут быть не заполнены.</p>
<div class="row">
<div class="col-md-6">
<x-input name="last_name" label="Фамилия" type="text" :value="$variant === 'create' ? '' : $user['last_name']" :required="false" :error="$errors['last_name'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="first_name" label="Имя" type="text" :value="$variant === 'create' ? '' : $user['first_name']" :required="false" :error="$errors['first_name'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="middle_name" label="Отчество" type="text" :value="$variant === 'create' ? '' : $user['middle_name']" :required="false" :error="$errors['middle_name'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="phone" label="Телефон" type="tel" :value="$variant === 'create' ? '' : $user['phone']" :required="false" :error="$errors['phone'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="email" label="Email" type="email" :value="$variant === 'create' ? '' : $user['email']" :required="true" :error="$errors['email'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="other_education" label="Другое образование" type="text" :value="$variant === 'create' ? '' : $user['other_education']" :required="false" :error="$errors['other_education'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="modality_program" label="Модальность / программа" type="text" :value="$variant === 'create' ? '' : $user['modality_program']" :required="false" :error="$errors['modality_program'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="training_center" label="Учебный центр" type="text" :value="$variant === 'create' ? '' : $user['training_center']" :required="false" :error="$errors['training_center'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="graduation_year" label="Год окончания" type="number" :value="$variant === 'create' ? '' : $user['graduation_year']" :required="false" :error="$errors['graduation_year'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="training_hours" label="Количество часов" type="number" :value="$variant === 'create' ? '' : $user['training_hours']" :required="false" :error="$errors['training_hours'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="license_number" label="Номер лицензии" type="text" :value="$variant === 'create' ? '' : $user['license_number']" :required="false" :error="$errors['license_number'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="license_expires_at" label="Лицензия действительна до" type="date" :value="$variant === 'create' ? '' : $user['license_expires_at']" :required="false" :error="$errors['license_expires_at'] ?? null" />
</div>
<div class="col-md-6">
<x-textarea name="group_leading_experience" label="Опыт ведения групп" :value="$variant === 'create' ? '' : $user['group_leading_experience']" :required="false" :error="$errors['group_leading_experience'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="groups_conducted_count" label="Количество проведённых групп" type="number" :value="$variant === 'create' ? '' : $user['groups_conducted_count']" :required="false" :error="$errors['groups_conducted_count'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="personal_data_consent_version" label="Версия согласия" type="text" :value="$variant === 'create' ? '' : $user['personal_data_consent_version']" :required="false" :error="$errors['personal_data_consent_version'] ?? null" />
</div>
<div class="col-md-6">
<x-select name="education_type_id" label="Тип образования" :error="$errors['education_type_id'] ?? null" :options="[''=>'Не указан','demo'=>$user['education_type']]" value="demo" />
</div>
<div class="col-md-6">
<x-input name="personal_data_consent_at" label="Дата согласия, Минск" type="datetime-local" value="2026-08-11T12:00" />
</div>
</div>
<x-checkbox name="documents_confirmed" :error="$errors['documents_confirmed'] ?? null" label="Достоверность документов подтверждена" :checked="true" />
<x-checkbox name="education_confirmed" label="Соответствие образования подтверждено" :checked="true" />
<x-checkbox name="live_session_ready" label="Готовность провести вебинар или эфир" />
</x-panel>
<x-panel title="Тариф и доступ">
<p>Статус анкеты меняется действиями модерации в карточке психолога.</p>
<x-status domain="user" :value="$variant === 'create' ? 'pending' : $user['status']" />
<fieldset class="mt-4">
<legend class="h3">Тариф</legend>
<x-radio name="free" value="1" label="Бесплатный" :checked="true" />
<x-radio name="free" value="0" label="Платный" />
</fieldset>
<x-checkbox name="disabled" label="Отключить доступ к кабинету" :checked="$user['disabled']" />
</x-panel>
<div class="actions">
<x-button data-noop :disabled="$variant === 'disabled'">Сохранить</x-button>
<x-button kind="ghost" :href="$links['admin-users']">Отмена</x-button>
</div>
</form>
@endsection
