@if($empty || $variant === 'no-documents')
<x-empty title="Документов пока нет" text="Загруженные документы появятся здесь." />
@else
<x-table :headers="['Документ','Размер и дата','Действия']">
@foreach(['Диплом','Сертификат','Лицензия / членство','Свидетельство о государственной регистрации'] as $type)
<tr>
<x-cell label="Документ">
<strong>{{ $type }}</strong>
<p>{{ $long ? str_repeat('Демонстрационный-документ-', 8) : 'DEMO-document-'.$loop->iteration }}.pdf</p>
</x-cell>
<x-cell label="Размер и дата">240 КБ<p>
<x-date :value="$user['created_at']" />
</p>
</x-cell>
<x-cell label="Действия">
<div class="actions">
<x-button kind="ghost" data-noop>Просмотр</x-button>
<x-button kind="ghost" data-noop>Скачать</x-button>
@if($admin)
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#delete-document">Удалить</x-button>
@endif
</div>
</x-cell>
</tr>
@endforeach
</x-table>
@endif
