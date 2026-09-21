@extends('layouts.admin')
@section('content')
@php $real = $realSettings ?? false; @endphp
<form id="settings-form" @if($real) method="POST" action="{{ route('admin.settings.update') }}" @else data-prototype-form @endif>
@if($real) @csrf @method('PUT') @endif
<x-validation-summary :errors="$real ? $errors : array_intersect_key($errors, array_flip(['placement_price','warning_days']))" />
<x-panel title="Стоимость операций">
@if($real)<p class="mb-4">Укажите стоимость в BYN. Пустое поле означает, что цена не настроена. Платежи ещё не подключены.</p>@else
<p class="mb-4">Цены пока не настроены. Укажите стоимость в BYN при подключении платных операций.</p>@endif
<div class="row">
<div class="col-md-6">
<x-input name="placement_price" label="Стоимость размещения, BYN" inputmode="decimal" :value="$real ? old('placement_price', $values['placement_price']) : ''" :help="$real ? 'Например, 50,00.' : 'Например, 50,00. Не настроена.'" :error="$errors['placement_price'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="extension_price" label="Стоимость продления, BYN" inputmode="decimal" :value="$real ? old('extension_price', $values['extension_price']) : ''" :error="$errors['extension_price'] ?? null" :help="$real ? 'Стоимость одной операции продления.' : 'Стоимость одной операции продления. Не настроена.'" />
</div>
</div>
</x-panel>
<x-panel title="Сроки">
<div class="row">
@foreach([
[$real ? 'placement_duration_days' : 'placement_days','Срок размещения, дней',30,'Начинается после ручной публикации.'],
[$real ? 'expiry_warning_days' : 'warning_days','Предупредить за, дней',3,'До даты окончания размещения.'],
['expired_extension_window_days','Окно продления, дней',30,'После окончания размещения.'],
[$real ? 'participant_application_retention_months' : 'application_retention_months','Хранить заявки, месяцев',12,'С даты создания заявки.'],
[$real ? 'password_setup_link_ttl_hours' : 'password_setup_ttl_hours','Ссылка установки пароля, часов',72,'Срок действия одноразовой ссылки.']
] as [$name,$label,$value,$help])
<div class="col-md-6">
<x-input :name="$name" :label="$label" :value="$real ? old($name, $values[$name]) : $value" type="number" min="1" step="1" :help="$help" :required="true" :error="$errors[$name] ?? null" />
</div>
@endforeach
</div>
</x-panel>
<x-button data-bs-toggle="modal" data-bs-target="#settings-confirm">Сохранить настройки</x-button>
</form>
<x-confirmation id="settings-confirm" title="Изменить цены и сроки?" action="Сохранить настройки" kind="primary" :form="$real ? 'settings-form' : null" :open="$variant === 'confirmation'">
<p>Новые значения применяются к новым операциям. Уже зафиксированные сроки размещения не изменяются.</p>
</x-confirmation>
@endsection
