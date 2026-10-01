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
<p class="small mt-3">Синхронизация обновляет содержимое. Публикация на основном сайте выполняется вручную.</p>
</x-panel>
