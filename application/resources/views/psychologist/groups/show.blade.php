@extends('layouts.psychologist')
@section('content')
@if($group['disabled'])
<x-alert tone="warning">Группа отключена администратором. Действия временно недоступны.</x-alert>
@endif
@if(in_array($group['status'], ['moderation','approved']))
<x-alert>{{ $group['status'] === 'moderation' ? 'Анкета проверяется администратором. Редактирование недоступно.' : 'Группа одобрена и ожидает ручной публикации. Срок размещения начнётся после публикации.' }}</x-alert>
@endif
@if($variant === 'paid-delete-blocked')
<x-alert tone="warning">Удаление недоступно: есть успешный платёж без отметки возврата. Обратитесь к администратору.</x-alert>
@endif
@include('shared.group-data')
<div class="mb-4">
@include('psychologist.groups._actions')
</div>
<x-panel title="Заявки участников">
@if($realGroups ?? false)
@include('shared.application-counters')
@if($latestApplication)
<p>{{ $latestApplication->last_name }} {{ $latestApplication->first_name }} · {{ $latestApplication->phone }}</p>
@else
<p>Заявок пока нет.</p>
@endif
<a href="{{ route('psychologist.groups.applications.index', $group['id']) }}">Все заявки группы</a>
@else
@include('shared.application-counters')@if($group['all_count'])
<p>{{ $application['name'] }} · {{ $application['phone'] }}</p>
@else
<p>Заявок пока нет.</p>
@endif
<a href="{{ $links['applications'] }}">Все заявки группы</a>
@endif
</x-panel>
@include('shared.group-history')@include('shared.group-delete')
@endsection
