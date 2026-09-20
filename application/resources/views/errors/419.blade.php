@extends('layouts.public')
@section('content')
<div class="auth-wrap">
<x-panel>
<p class="eyebrow">Ошибка 419</p>
<h1 class="mb-4">Время сессии истекло</h1>
<p class="mb-4">Откройте форму заново и повторите действие.</p>
<x-button :href="url('/')">Вернуться в кабинет</x-button>
</x-panel>
</div>
@endsection
