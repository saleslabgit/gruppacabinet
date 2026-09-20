@extends('layouts.public')
@section('content')
<x-page-header title="Уведомления и подтверждения" />
<x-panel title="Действие с группой">
@if($variant === 'validation')
<x-validation-summary :errors="['title' => 'Заполните название группы.']" />
<x-input name="title" label="Название" error="Заполните название группы." />
@else
<x-alert :tone="in_array($variant, ['success','warning','danger']) ? $variant : 'warning'">{{ $variant === 'success' ? 'Изменения сохранены.' : 'Проверьте информацию перед продолжением.' }} @if($long){{ str_repeat('Демонстрационное длинное уведомление. ', 30) }}@endif
</x-alert>
@endif
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#remove">Удалить черновик</x-button>
<x-confirmation id="remove" title="Удалить черновик?" action="Удалить черновик" :open="$variant === 'confirmation'">
<p>Группа исчезнет из списка. Платежи и история сохранятся.</p>
</x-confirmation>
</x-panel>
@endsection
