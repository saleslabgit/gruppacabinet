@extends('layouts.public')
@section('content')
<div class="auth-wrap">
<x-panel>
<p class="eyebrow">Ошибка 429</p>
<h1 class="mb-4">Слишком много запросов</h1>
<p class="mb-4">Подождите немного и повторите попытку.</p>
<x-cabinet-return />
</x-panel>
</div>
@endsection
