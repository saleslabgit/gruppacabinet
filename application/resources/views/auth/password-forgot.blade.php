@extends('layouts.public')
@section('title', 'Восстановление пароля')
@section('content')
<div class="auth-wrap">
<x-panel>
<h1 class="mb-4">Восстановление пароля</h1>
@if($variant === 'success')
<x-alert tone="success">Если аккаунт с таким email доступен для восстановления, мы отправили ссылку для установки нового пароля.</x-alert>
@else
<p>Укажите email аккаунта, чтобы получить ссылку для нового пароля.</p>
@if($variant === 'rate-limit')
<x-alert tone="warning">Слишком много запросов. Попробуйте через минуту.</x-alert>
@endif
<form @if($prototype) data-prototype-form @else method="POST" action="{{ route('password.forgot.store') }}" @endif>
@unless($prototype) @csrf @endunless
<x-validation-summary :errors="array_intersect_key($errors, array_flip(['email']))" />
<x-input name="email" label="Email" type="email" autocomplete="username" :value="$email ?? ''" :required="true" :error="$errors['email'] ?? null" />
<x-button class="w-100" :data-noop="$prototype" :type="$prototype ? 'button' : 'submit'">Отправить ссылку</x-button>
</form>
@endif
<p class="mt-4"><a href="{{ $links['login'] }}">Вернуться ко входу</a></p>
</x-panel>
</div>
@endsection
