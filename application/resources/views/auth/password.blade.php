@extends('layouts.public')
@section('content')
<div class="auth-wrap">
<x-panel>
<h1 class="mb-4">Установка пароля</h1>
@if(in_array($variant, ['expired','invalid']))
<x-alert tone="warning">Ссылка недействительна или срок её действия истёк. Запросите новую ссылку на странице восстановления пароля.</x-alert>
<p><a href="{{ $prototype ? $links['password-forgot'] : route('password.forgot') }}">Восстановить пароль</a></p>
@elseif($variant === 'success')
<x-alert tone="success">Пароль установлен. Теперь можно войти в кабинет.</x-alert>
@else
<form @if($prototype) data-prototype-form @else method="POST" action="{{ route('password.store') }}" @endif>
@if(!$prototype) @csrf @endif
<input type="hidden" name="token" value="{{ $prototype ? 'DEMO-NONFUNCTIONAL-TOKEN' : $setupToken }}">
<p>Создайте новый пароль для входа в кабинет.</p>
<x-validation-summary :errors="array_intersect_key($errors, array_flip(['email','password','password_confirmation']))" />
<x-input name="email" :error="$errors['email'] ?? null" label="Email" type="email" :value="$user['email']" readonly autocomplete="username" :required="true" />
<x-input name="password" label="Новый пароль" type="password" autocomplete="new-password" :required="true" help="Не менее 8 символов. Не используйте пароль от других сервисов." :error="$errors['password'] ?? null" />
<x-input name="password_confirmation" label="Повторите пароль" type="password" autocomplete="new-password" :required="true" :error="$errors['password_confirmation'] ?? null" />
@if($prototype)<x-button data-noop>Установить пароль</x-button>@else<x-button type="submit">Установить пароль</x-button>@endif
</form>
@endif
<p class="mt-4">
<a href="{{ $links['login'] }}">Вернуться ко входу</a>
</p>
</x-panel>
</div>
@endsection
