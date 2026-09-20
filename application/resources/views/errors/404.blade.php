@extends('layouts.public')
@section('content')
<div class="auth-wrap">
<x-panel>
<p class="eyebrow">Ошибка 404</p>
<h1 class="mb-4">Страница не найдена</h1>
<p class="mb-4">Проверьте адрес или вернитесь в кабинет.</p>
<x-button :href="url('/')">Вернуться в кабинет</x-button>
</x-panel>
</div>
@endsection
