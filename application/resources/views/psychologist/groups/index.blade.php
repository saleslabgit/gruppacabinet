@extends('layouts.psychologist')
@section('actions')
<x-button :href="$links['group-form']">Добавить группу</x-button>
@endsection
@section('content')
@if($empty)
<x-empty title="Здесь будут ваши группы" text="Добавьте первую группу, чтобы отправить её на модерацию и получать заявки.">
<x-button :href="$links['group-form']">Добавить группу</x-button>
</x-empty>
@else
@foreach($groups as $group)
<x-panel>
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
</x-panel>
@endforeach
<x-pagination :pages="$pages" :current="$currentPage" />
@include('shared.group-delete')@endif
@endsection
