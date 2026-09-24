@extends('layouts.admin')
@section('breadcrumbs')
<x-breadcrumbs :items="array_merge([['label' => 'Психологи' , 'url' => $links['admin-users']]], (($creating ?? false) || $variant === 'create') ? [] : [['label' => $user['name'] , 'url' => $prototype ? $links['admin-user'] : route('admin.psychologists.show', $user['id'])]], [['label' => (($creating ?? false) || $variant === 'create') ? 'Создание' : 'Редактирование']])" />
@endsection
@section('content')
<form class="editor-form" @if($prototype) data-prototype-form @else method="POST" action="{{ $formAction }}" @endif>
@if(!$prototype)
@csrf
@if(!$creating) @method('PUT') @endif
@endif
@php
    $summaryErrors = [];
    foreach ($prototype ? array_intersect_key($errors, array_flip(['last_name', 'first_name', 'middle_name', 'phone', 'email', 'education_type_id', 'other_education', 'trainings.0.graduation_year', 'trainings.0.training_hours', 'license_number', 'license_expires_at', 'group_leading_experience', 'groups_conducted_count', 'personal_data_consent_version', 'personal_data_consent_at', 'documents_confirmed', 'education_confirmed', 'live_session_ready', 'disabled'])) : $errors as $key => $message) {
        $target = str_starts_with($key, 'trainings.') ? preg_replace('/\.([^.]+)/', '[$1]', $key) : $key;
        if (str_starts_with($key, 'trainings.') && str_ends_with($key, '.id')) {
            $target = substr($target, 0, -4);
        }
        $summaryErrors[$target] = $message;
    }
@endphp
<x-validation-summary :errors="$summaryErrors" />
<x-panel title="Анкета">
<p class="meta mb-4">Email обязателен. Остальные поля могут быть не заполнены.</p>
@if(!($creating ?? false) && $variant !== 'create')
<div class="actions mb-3"><x-status domain="user" :value="$user['status']" /><x-status domain="access" :value="$user['disabled'] ? 'disabled' : 'enabled'" /><x-tariff :free="$user['free']" suffix="тариф" /></div>
<p class="meta mb-4">Статус анкеты, тариф и доступ меняются отдельными действиями в <a href="{{ $prototype ? $links['admin-user'] : route('admin.psychologists.show', $user['id']) }}">карточке психолога</a>.</p>
@endif
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
<section class="detail-section" id="trainings" tabindex="-1" data-trainings-editor>
<h3>Дополнительное обучение</h3>
<p class="meta">Добавьте программы в нужном порядке. При удалении обучения загруженные документы сохраняются.</p>
@php
    $trainings = $prototype ? ($variant === 'create' ? [] : $user['trainings']) : (session()->hasOldInput() ? old('trainings', []) : $user['trainings']);
    $trainings = is_array($trainings) ? $trainings : [];
@endphp
@if(isset($errors['trainings']))<p class="text-danger" role="alert">{{ $errors['trainings'] }}</p>@endif
<div data-training-list>
@foreach($trainings as $index => $training)
@include('shared.training-fields', ['training' => is_array($training) ? $training : [], 'index' => $index])
@endforeach
</div>
<template data-training-template>
@include('shared.training-fields', ['training' => [], 'index' => '__INDEX__', 'errors' => []])
</template>
<button type="button" class="btn btn-secondary" data-training-add>Добавить обучение</button>
<noscript><p class="meta">Для добавления, удаления и перестановки обучений включите JavaScript.</p></noscript>
</section>
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

@if($prototype || $creating)
<fieldset class="mt-4">
<legend class="h3">Тариф</legend>
<x-radio name="free" value="1" label="Бесплатный" :checked="$prototype || (string) old('free', $user['free']) === '1'" />
<x-radio name="free" value="0" label="Платный" :checked="!$prototype && !(bool) old('free', $user['free'])" />
</fieldset>
@endif
@if($prototype)
<x-checkbox name="disabled" label="Отключить доступ к кабинету" :checked="$user['disabled']" />
@endif
</x-panel>
<div class="actions">
<x-button icon="check-lg" :type="$prototype ? 'button' : 'submit'" :data-noop="$prototype" :disabled="$variant === 'disabled'">Сохранить</x-button>
<x-button icon="arrow-left" kind="ghost" :href="(($creating ?? false) || $variant === 'create') ? $links['admin-users'] : ($prototype ? $links['admin-user'] : route('admin.psychologists.show', $user['id']))">Отмена</x-button>
</div>
</form>
@endsection
