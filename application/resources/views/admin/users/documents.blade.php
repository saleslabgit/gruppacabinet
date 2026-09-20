@extends('layouts.admin')
@section('content')
<p class="mb-4">{{ $user['name'] }}</p>
@if($variant === 'error')
<x-alert tone="danger">Не удалось открыть документ. Попробуйте позже.</x-alert>
@endif
@include('shared.documents')
<x-panel title="Добавить документ">
<form data-prototype-form>
<x-select name="type" label="Тип документа" :required="true" :options="['diploma'=>'Диплом','certificate'=>'Сертификат','license'=>'Лицензия / членство','registration'=>'Свидетельство о государственной регистрации']" />
<x-input name="file" label="Файл" type="file" accept=".pdf,.jpeg,.jpg,.png" :required="true" help="PDF, JPEG или PNG. Допустимый размер будет указан при подключении загрузки." :error="$errors['file'] ?? null" />
<x-button data-noop>Загрузить документ</x-button>
</form>
</x-panel>
<x-confirmation id="delete-document" title="Удалить документ?" action="Удалить документ" :open="$variant === 'confirmation'">
<p>Документ перестанет быть доступен психологу и администратору.</p>
</x-confirmation>
@endsection
