@extends('layouts.admin')
@section('content')
<p class="mb-4">Задачи, которые требуют вашего внимания.</p>
<div class="catalog-grid">
@foreach([
['Новые анкеты','admin-users','pending',3],['Группы на модерации','admin-groups','moderation',4],['Ожидают ручной публикации','admin-groups','approved',2],['Снять с публикации вручную','admin-groups','expired',1],['Платежи требуют проверки','admin-payments','manual-review',1]] as [$label,$target,$state,$count])
<x-panel :title="$label">
<p>{{ $empty ? 0 : $count }} · {{ $empty ? 'Нет задач' : 'Требуют внимания' }}</p>
<a href="{{ route('prototype.'.$target, ['variant'=>$state]) }}">Открыть список</a>
</x-panel>
@endforeach
</div>
@endsection
