@extends('layouts.psychologist')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Мои группы' , 'url' => $links['groups']],['label' => $group['title']]]" />
@endsection
@section('group-actions')
<div class="detail-actions">
@include('psychologist.groups._actions', ['detail' => true])
</div>
@endsection
@section('content')
@if($realGroups ?? false)<x-validation-summary :errors="$errors" />@endif
@if($group['disabled'])
<x-alert tone="warning">Группа отключена администратором. Редактирование, пауза и продление недоступны.</x-alert>
@endif

<x-panel :title="$group['title']">@include('shared.group-summary')</x-panel>
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
@include('shared.group-data', ['omitSummary' => true])
@if(($canPause ?? false) || (!($realGroups ?? false) && $group['status'] === 'active'))
<x-confirmation id="pause-group" title="Поставить группу на паузу?" action="Поставить на паузу" kind="primary" :url="($realGroups ?? false) ? route('psychologist.groups.pause', $group['id']) : null">
<p>Группа будет снята с публикации на основном сайте. Приём заявок прекратится. Дата окончания размещения не изменится.</p>
</x-confirmation>
@endif
@if(($canResume ?? false) || (!($realGroups ?? false) && $group['status'] === 'paused'))
<x-confirmation id="resume-group" title="Возобновить публикацию?" action="Возобновить публикацию" kind="primary" :url="($realGroups ?? false) ? route('psychologist.groups.resume', $group['id']) : null">
<p>После подтверждения публикации на основном сайте группа станет активной. Возобновление доступно только до окончания размещения. Дата окончания не изменится.</p>
</x-confirmation>
@endif
@include('shared.group-history')@include('shared.group-delete')
@endsection
