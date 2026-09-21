@extends('layouts.public')
@section('content')
<div class="auth-wrap">
<x-panel>
<p class="eyebrow">Ошибка 403</p>
<h1 class="mb-4">Нет доступа</h1>
<p class="mb-4">У вас нет доступа к этой странице.</p>
<x-cabinet-return />
</x-panel>
</div>
@endsection
