@extends('layouts.admin')
@section('content')
@if($workQueueAvailable ?? true)
<p class="page-description mb-3">Задачи, которые требуют вашего внимания.</p>
<div class="catalog-grid work-queue">
@foreach([
['Новые анкеты','admin-users','pending',3],['Группы на модерации','admin-groups','moderation',4],['Ожидают ручной публикации','admin-groups','approved',2],['Снять с публикации вручную','admin-groups','expired',1],['Платежи требуют проверки','admin-payments','manual-review',1]] as [$label,$target,$state,$count])
<x-panel :title="$label" :compact="true" class="panel-compact">
<p>{{ $empty ? 0 : $count }} · {{ $empty ? 'Нет задач' : 'Требуют внимания' }}</p>
<a href="{{ route('prototype.'.$target, ['variant'=>$state]) }}">Открыть список</a>
</x-panel>
@endforeach
</div>
@else
<x-empty title="Добро пожаловать" text="Доступ к кабинету открыт. Разделы управления пока недоступны." />
@endif
@endsection
