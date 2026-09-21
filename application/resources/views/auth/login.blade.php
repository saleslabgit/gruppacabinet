@extends('layouts.public')
@section('content')
@php
    $prototype = $prototype ?? false;
    $variant = $prototype ? $variant : session('login_state', 'normal');
    $fieldErrors = $prototype ? $errors : $errors->getMessages();
    if (!$prototype) {
        $fieldErrors = array_map(fn ($messages) => $messages[0], $fieldErrors);
    }
@endphp
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
@if(!$prototype && session('access_revoked'))
<x-alert tone="warning">{{ session('access_revoked') }}</x-alert>
@endif
<form @if($prototype) data-prototype-form @else method="POST" action="{{ route('login.store') }}" @endif>
@unless($prototype) @csrf @endunless
<x-validation-summary :errors="array_intersect_key($fieldErrors, array_flip(['email','password']))" />
<x-input name="email" label="Email" type="email" autocomplete="username" :value="$prototype ? '' : old('email', '')" :required="true" :error="$fieldErrors['email'] ?? null" />
<x-input name="password" label="Пароль" type="password" autocomplete="current-password" :required="true" :error="$fieldErrors['password'] ?? null" />
<x-button class="w-100" :data-noop="$prototype" :type="$prototype ? 'button' : 'submit'" :disabled="$variant === 'disabled'">Войти</x-button>
</form>
</x-panel>
</div>
@endsection
