@extends('layouts.public')
@section('content')
<div class="auth-wrap">
<x-panel>
<p class="eyebrow">Кабинет психолога</p>
<h1 class="mb-3">Рады видеть вас</h1>
<p class="mb-4">Войдите, чтобы управлять группами и заявками участников.</p>
@if($variant === 'error')
<x-alert tone="danger">Не удалось войти. Проверьте email и пароль.</x-alert>
@endif
@if($variant === 'rate-limit')
<x-alert tone="warning">Слишком много попыток входа. Попробуйте позже.</x-alert>
@endif
@if($variant === 'disabled')
<x-alert tone="warning">Доступ к кабинету ограничен. Обратитесь к администратору.</x-alert>
@endif
<form data-prototype-form>
<x-validation-summary :errors="array_intersect_key($errors, array_flip(['email','password']))" />
<x-input name="email" label="Email" type="email" autocomplete="username" :required="true" :error="$errors['email'] ?? null" />
<x-input name="password" label="Пароль" type="password" autocomplete="current-password" :required="true" :error="$errors['password'] ?? null" />
<x-button class="w-100" data-noop :disabled="$variant === 'disabled'">Войти</x-button>
</form>
</x-panel>
</div>
@endsection
