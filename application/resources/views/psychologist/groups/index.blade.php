@extends('layouts.psychologist')
@section('actions')
@if($realGroups ?? false)
<form method="POST" action="{{ route('psychologist.groups.store') }}">@csrf<x-button type="submit">Добавить группу</x-button></form>
@else
<x-button :href="$links['group-form'] ?? null" :disabled="!($canCreateGroup ?? true)">Добавить группу</x-button>
@endif
@endsection
@section('content')
@if($empty)
<x-empty title="Здесь будут ваши группы" :text="($realGroups ?? false) ? 'Добавьте первую группу, чтобы отправить её на модерацию.' : (($canCreateGroup ?? true) ? 'Добавьте первую группу, чтобы отправить её на модерацию и получать заявки.' : 'Создание и просмотр групп пока недоступны.')">
@if($realGroups ?? false)
<form method="POST" action="{{ route('psychologist.groups.store') }}">@csrf<x-button type="submit">Добавить группу</x-button></form>
@else
<x-button :href="$links['group-form'] ?? null" :disabled="!($canCreateGroup ?? true)">Добавить группу</x-button>
@endif
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
@if($realGroups ?? false)
@include('shared.application-counters')
<a href="{{ route('psychologist.groups.applications.index', $group['id']) }}">Открыть заявки</a>
@else
@include('shared.application-counters')
<a href="{{ $links['applications'] }}">Открыть заявки</a>
@endif
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
