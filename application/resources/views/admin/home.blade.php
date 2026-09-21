@extends('layouts.admin')
@section('content')
@if($workQueueAvailable ?? true)
<p class="page-description mb-3">Задачи, которые требуют вашего внимания.</p>
<div class="catalog-grid work-queue">
@foreach([
['Новые анкеты','admin-users','pending',3],['Группы на модерации','admin-groups','moderation',4],['Ожидают ручной публикации','admin-groups','approved',2],['Снять с публикации вручную','admin-groups','expired',1],['Платежи требуют проверки','admin-payments','manual-review',1]] as [$label,$target,$state,$count])
<x-panel :title="$label" :compact="true" class="panel-compact">
<p>{{ $empty ? 0 : $count }} · {{ $empty ? 'Нет задач' : 'Требуют внимания' }}</p>
<x-button kind="secondary" icon="arrow-up-right" :href="route('prototype.'.$target, ['variant'=>$state])">Открыть список</x-button>
</x-panel>
@endforeach
</div>
@else
<p class="page-description">Управляйте анкетами, группами и заявками участников.</p>
<div class="catalog-grid work-queue">
@foreach([
['Психологи', 'Анкеты, доступ и документы.', 'admin.psychologists.index', 'people'],
['Группы', 'Модерация и ручная публикация.', 'admin.groups.index', 'collection'],
['Заявки', 'Контакты участников и состояние обработки.', 'admin.applications.index', 'inbox'],
['Настройки', 'Стоимость операций и сроки.', 'admin.settings.index', 'gear']
] as [$label, $description, $route, $icon])
<x-panel :title="$label" :compact="true">
<p>{{ $description }}</p>
<x-button kind="secondary" :icon="$icon" :href="route($route)">Открыть раздел</x-button>
</x-panel>
@endforeach
</div>
@endif
@endsection
