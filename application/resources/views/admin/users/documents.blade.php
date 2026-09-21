@extends('layouts.admin')
@section('content')
<x-validation-summary :errors="$errors" />
<p class="mb-4">{{ $user['name'] }}</p>
@if($variant === 'error')
<x-alert tone="danger">Не удалось открыть документ. Попробуйте позже.</x-alert>
@endif
@include('shared.documents')
<x-panel title="Добавить документ">
<form @if($prototype) data-prototype-form @else method="POST" enctype="multipart/form-data" action="{{ route('admin.psychologists.documents.store', $user['id']) }}" @endif>
@if(!$prototype) @csrf @endif
<x-select name="type" :error="$errors['type'] ?? null" label="Тип документа" :required="true" :options="$prototype ? ['diploma'=>'Диплом','certificate'=>'Сертификат','license'=>'Лицензия / членство','registration'=>'Свидетельство о государственной регистрации'] : config('psychologist_documents.types')" :value="$prototype ? '' : old('type')" />
<x-input name="file" label="Файл" type="file" accept=".pdf,.jpeg,.jpg,.png" :required="true" :help="$prototype ? 'PDF, JPEG или PNG. Допустимый размер будет указан при подключении загрузки.' : 'PDF, JPEG или PNG. Максимум: '.config('psychologist_documents.max_kb').' КБ.'" :error="$errors['file'] ?? null" />
<x-button :type="$prototype ? 'button' : 'submit'" :data-noop="$prototype">Загрузить документ</x-button>
</form>
</x-panel>
@if($prototype)
<x-confirmation id="delete-document" title="Удалить документ?" action="Удалить документ" :open="$variant === 'confirmation'">
<p>Документ перестанет быть доступен психологу и администратору.</p>
</x-confirmation>
@endif
@endsection
