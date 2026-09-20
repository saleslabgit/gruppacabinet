@extends('layouts.admin')
@section('content')
<x-panel :title="$user['name']">
<div class="actions mb-4">
<x-status domain="user" :value="$user['status']" />
<x-status domain="access" :value="$user['disabled'] ? 'disabled' : 'enabled'" />
<span>{{ $user['free'] ? 'Бесплатный тариф' : 'Платный тариф' }}</span>
</div>
<div class="actions">
@if($user['status'] === 'pending')
<x-button data-bs-toggle="modal" data-bs-target="#approve-user">Принять анкету</x-button>
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#reject-user">Отклонить</x-button>
@endif
<x-button kind="secondary" :href="route('prototype.admin-user-form',['variant'=>'edit'])">Редактировать</x-button>
<x-dropdown id="user-actions">
<li>
<button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#access-user">{{ $user['disabled'] ? 'Включить доступ' : 'Отключить доступ' }}</button>
</li>
<li>
<button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#tariff-user">{{ $user['free'] ? 'Назначить платный тариф' : 'Назначить бесплатный тариф' }}</button>
</li>
@if($user['status'] === 'approved')
<li>
<button type="button" class="dropdown-item" data-noop>Повторно отправить установку пароля</button>
</li>
@endif
<li>
<button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#delete-user">Удалить психолога</button>
</li>
</x-dropdown>
</div>
</x-panel>
@include('shared.profile-data')
<x-panel title="Документы">
<a href="{{ $links['admin-documents'] }}">Просмотреть 4 документа</a>
</x-panel>
<x-panel title="Группы психолога">
<p>1 группа · {{ $group['title'] }}</p>
<a href="{{ $links['admin-group'] }}">Открыть группу</a>
</x-panel>
<x-panel title="История действий">
<p>
<x-date :value="$user['created_at']" /> · Анкета получена</p>
@if($user['status'] === 'approved')
<p>
<x-date :value="$date" /> · Администратор принял анкету</p>
@endif
</x-panel>
<x-confirmation id="approve-user" title="Принять анкету?" action="Принять" kind="primary">
<p>Психолог сможет установить пароль и войти после подключения отправки писем.</p>
</x-confirmation>
<x-confirmation id="reject-user" title="Отклонить анкету?" action="Отклонить">
<p>Вход в кабинет будет недоступен.</p>
</x-confirmation>
<x-confirmation id="access-user" title="Изменить доступ?" action="Подтвердить">
<p>Отключение доступа завершает открытые сессии психолога.</p>
</x-confirmation>
<x-confirmation id="tariff-user" title="Изменить тариф?" action="Изменить тариф" kind="primary">
<p>Новый тариф применяется к новым группам и следующим попыткам продления. Исторический тариф групп не меняется.</p>
</x-confirmation>
<x-confirmation id="delete-user" title="Удалить психолога?" action="Удалить" :open="$variant === 'confirmation'">
<p>Доступ будет закрыт. Связанные платежи и история сохранятся.</p>
</x-confirmation>
@endsection
