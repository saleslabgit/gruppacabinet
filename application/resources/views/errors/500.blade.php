@extends('layouts.public')
@section('content')
<div class="auth-wrap">
<x-panel>
<p class="eyebrow">Ошибка 500</p>
<h1 class="mb-4">Не удалось открыть страницу</h1>
<p class="mb-4">Попробуйте позже. Если ошибка повторяется, сообщите администратору.</p>
<x-cabinet-return />
</x-panel>
</div>
@endsection
