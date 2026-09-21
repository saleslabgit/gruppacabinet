@if($empty || $variant === 'no-documents')
<x-empty title="Документов пока нет" text="Загруженные документы появятся здесь." />
@else
<x-table :headers="['Документ','Размер и дата','Действия']">
@foreach(($prototype ?? true) ? ['Диплом','Сертификат','Лицензия / членство','Свидетельство о государственной регистрации'] : $documents as $document)
@php($type = ($prototype ?? true) ? $document : config('psychologist_documents.types')[$document->type])
<tr>
<x-cell label="Документ">
<strong>{{ $type }}</strong>
<p>{{ ($prototype ?? true) ? (($long ? str_repeat('Демонстрационный-документ-', 8) : 'DEMO-document-'.$loop->iteration).'.pdf') : $document->original_name }}</p>
</x-cell>
<x-cell label="Размер и дата">{{ ($prototype ?? true) ? '240' : (int) ceil($document->size / 1024) }} КБ<p>
<x-date :value="($prototype ?? true) ? $user['created_at'] : $document->created_at" />
</p>
</x-cell>
<x-cell label="Действия">
<div class="actions">
@if($prototype ?? true)
<x-button kind="ghost" data-noop>Просмотр</x-button>
<x-button kind="ghost" data-noop>Скачать</x-button>
@if($admin)
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#delete-document">Удалить</x-button>
@endif
@else
<x-button kind="ghost" :href="$documentActions[$document->id]['view']">Просмотр</x-button>
<x-button kind="ghost" :href="$documentActions[$document->id]['download']">Скачать</x-button>
@if(isset($documentActions[$document->id]['delete']))
<x-button kind="danger" data-bs-toggle="modal" :data-bs-target="'#delete-document-'.$document->id">Удалить</x-button>
@endif
@endif
</div>
</x-cell>
</tr>
@endforeach
</x-table>
@endif

@if(!($prototype ?? true))
@foreach($documents as $document)
@if(isset($documentActions[$document->id]['delete']))
<x-confirmation :id="'delete-document-'.$document->id" title="Удалить документ?" action="Удалить документ" :url="$documentActions[$document->id]['delete']" method="DELETE">
<p>Документ «{{ $document->original_name }}» будет удалён.</p>
</x-confirmation>
@endif
@endforeach
@endif
