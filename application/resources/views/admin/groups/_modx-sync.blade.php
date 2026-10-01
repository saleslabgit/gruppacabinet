<x-panel title="Синхронизация MODX">
<dl class="detail-grid">
<div><dt>Состояние</dt><dd>{{ ['pending' => 'В очереди', 'syncing' => 'Выполняется', 'synced' => 'Синхронизировано', 'failed' => 'Ошибка', 'conflict' => 'Конфликт'][$groupModel->modx_sync_status] ?? 'Не запрашивалась' }}</dd></div>
<div><dt>MODX Resource ID</dt><dd>{{ $groupModel->public_site_resource_id ?? 'Ещё не получен' }}</dd></div>
@foreach(['modx_sync_requested_at' => 'Запрошено', 'modx_sync_started_at' => 'Начало попытки', 'modx_synced_at' => 'Успешно', 'modx_sync_failed_at' => 'Ошибка'] as $field => $label)
@if($groupModel->$field)<div><dt>{{ $label }}</dt><dd><x-date :value="$groupModel->$field" /></dd></div>@endif
@endforeach
</dl>
@if($groupModel->modx_sync_error_code)
<x-alert tone="warning">{{ \App\Support\GroupModxStatus::error($groupModel->modx_sync_error_code) }}</x-alert>
@endif
@if($groupModel->modx_cover_cleanup_warning)
<x-alert tone="warning">Содержимое сохранено. На основном сайте не удалось удалить предыдущую обложку; требуется проверка администратора MODX.</x-alert>
@endif
@can('syncModx', $groupModel)
<form method="post" action="{{ route('admin.groups.sync-modx', $groupModel) }}">
@csrf
<x-button type="submit" kind="secondary" icon="arrow-repeat">Синхронизировать повторно</x-button>
</form>
@endcan
<p class="small mt-3">Синхронизация обновляет содержимое. Первоначальная публикация и публикация после продления законченной группы выполняются вручную.</p>
<h3>Публикация на основном сайте</h3>
<dl class="detail-grid">
<div><dt>Запрошено состояние</dt><dd>{{ ['published' => 'Опубликована', 'unpublished' => 'Снята с публикации'][$groupModel->modx_publication_desired] ?? 'Не запрашивалось' }}</dd></div>
<div><dt>Состояние публикации</dt><dd>{{ ['pending' => 'В очереди', 'syncing' => 'Выполняется', 'published' => 'Опубликована', 'unpublished' => 'Снята с публикации', 'failed' => 'Ошибка', 'conflict' => 'Конфликт'][$groupModel->modx_publication_status] ?? 'Не подтверждено' }}</dd></div>
@foreach(['requested' => 'Запрошено', 'started' => 'Начало попытки', 'synced' => 'Подтверждено', 'failed' => 'Ошибка'] as $event => $label)
@php($field = 'modx_publication_'.$event.'_at')
@if($groupModel->$field)<div><dt>{{ $label }}</dt><dd><x-date :value="$groupModel->$field" /></dd></div>@endif
@endforeach
</dl>
@if($groupModel->modx_publication_error_code)
<x-alert tone="warning">{{ \App\Support\GroupModxStatus::error($groupModel->modx_publication_error_code) }}</x-alert>
@endif
</x-panel>
