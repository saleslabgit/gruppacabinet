@extends('layouts.psychologist')
@section('actions')
<x-button :href="$links['group-form'] ?? null" :disabled="!($canCreateGroup ?? true)">Добавить группу</x-button>
@endsection
@section('content')
@if($empty)
<x-empty title="Здесь будут ваши группы" :text="($canCreateGroup ?? true) ? 'Добавьте первую группу, чтобы отправить её на модерацию и получать заявки.' : 'Создание и просмотр групп пока недоступны.'">
<x-button :href="$links['group-form'] ?? null" :disabled="!($canCreateGroup ?? true)">Добавить группу</x-button>
</x-empty>
@else
<div class="group-list">
@foreach($groups as $group)
<article class="group-row">
<div>
<p class="eyebrow">Группа № {{ $group['id'] }}</p>
<h2>{{ $group['title'] }}</h2>
@include('shared.group-summary')
</div>
<div>
<h3>Заявки участников</h3>
@include('shared.application-counters')
<a href="{{ $links['applications'] }}">Открыть заявки</a>
</div>
<div class="wide">
@include('psychologist.groups._actions')
</div>
</article>
@endforeach
</div>
<x-pagination :pages="$pages" :current="$currentPage" />
@include('shared.group-delete')@endif
@endsection
