@extends('layouts.admin')
@section('content')
<form data-prototype-form>
<x-validation-summary :errors="array_intersect_key($errors, array_flip(['placement_price','warning_days']))" />
<x-panel title="Стоимость операций">
<p class="mb-4">Цены пока не настроены. Укажите стоимость в BYN при подключении платных операций.</p>
<div class="row">
<div class="col-md-6">
<x-input name="placement_price" label="Стоимость размещения, BYN" inputmode="decimal" help="Например, 50,00. Не настроена." :error="$errors['placement_price'] ?? null" />
</div>
<div class="col-md-6">
<x-input name="extension_price" label="Стоимость продления, BYN" inputmode="decimal" help="Стоимость одной операции продления. Не настроена." />
</div>
</div>
</x-panel>
<x-panel title="Сроки">
<div class="row">
@foreach([
['placement_days','Срок размещения, дней',30,'Начинается после ручной публикации.'],
['warning_days','Предупредить за, дней',3,'До даты окончания размещения.'],
['expired_extension_window_days','Окно продления, дней',30,'После окончания размещения.'],
['application_retention_months','Хранить заявки, месяцев',12,'С даты создания заявки.'],
['password_setup_ttl_hours','Ссылка установки пароля, часов',72,'Срок действия одноразовой ссылки.']
] as [$name,$label,$value,$help])
<div class="col-md-6">
<x-input :name="$name" :label="$label" :value="$value" type="number" min="1" step="1" :help="$help" :required="true" :error="$errors[$name] ?? null" />
</div>
@endforeach
</div>
</x-panel>
<x-button data-bs-toggle="modal" data-bs-target="#settings-confirm">Сохранить настройки</x-button>
</form>
<x-confirmation id="settings-confirm" title="Изменить цены и сроки?" action="Сохранить настройки" kind="primary" :open="$variant === 'confirmation'">
<p>Новые значения применяются к новым операциям. Уже зафиксированные сроки размещения не изменяются.</p>
</x-confirmation>
@endsection
