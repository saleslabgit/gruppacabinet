@extends('layouts.admin')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Психологи' , 'url' => $links['admin-users']],['label' => $user['name']]]" />
@endsection
@section('content')
<x-validation-summary :errors="$errors" />
<x-panel :title="$user['name']">
<div class="actions mb-4">
<x-status domain="user" :value="$user['status']" />
<x-status domain="access" :value="$user['disabled'] ? 'disabled' : 'enabled'" />
<span>{{ $user['free'] ? 'Бесплатный тариф' : 'Платный тариф' }}</span>
</div>
<div class="actions">
@if($user['status'] === 'pending')
<x-button icon="check-lg" data-bs-toggle="modal" data-bs-target="#approve-user">Принять анкету</x-button>
<x-button icon="x-lg" kind="danger" data-bs-toggle="modal" data-bs-target="#reject-user">Отклонить</x-button>
@endif
<x-button icon="pencil" kind="secondary" :href="$prototype ? route('prototype.admin-user-form',['variant'=>'edit']) : route('admin.psychologists.edit', $user['id'])">Редактировать</x-button>
<x-dropdown id="user-actions">
<li>
<button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#access-user">{{ $user['disabled'] ? 'Включить доступ' : 'Отключить доступ' }}</button>
</li>
<li>
<button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#tariff-user">{{ $user['free'] ? 'Назначить платный тариф' : 'Назначить бесплатный тариф' }}</button>
</li>
@if($prototype && $user['status'] === 'approved')
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
<a href="{{ $prototype ? $links['admin-documents'] : route('admin.psychologists.documents.index', $user['id']) }}">Просмотреть документы ({{ $prototype ? 4 : $psychologist->documents_count }})</a>
</x-panel>
<x-panel title="Группы психолога">
@if($prototype)
<p>1 группа · {{ $group['title'] }}</p>
<a href="{{ $links['admin-group'] }}">Открыть группу</a>
@else
<p>Групп: {{ $psychologist->groups_count }}</p>
@endif
</x-panel>
<x-panel class="panel-secondary" title="История действий">
<p>
<x-date :value="$user['created_at']" /> · Анкета получена</p>
@if(!$prototype)
@foreach($history as $entry)
<p><x-date :value="$entry->created_at" /> ·
{{ ['user.approved'=>'Анкета принята','user.rejected'=>'Анкета отклонена','user.enabled'=>'Доступ включён','user.disabled'=>'Доступ отключён','user.tariff_changed'=>'Тариф изменён','user.deleted'=>'Психолог удалён'][$entry->action] ?? 'Действие администратора' }} ·
{{ $entry->actor?->email ?? 'Система' }}
@if(isset($entry->metadata['old_free']))
· {{ $entry->metadata['old_free'] ? 'Бесплатный' : 'Платный' }} → {{ $entry->metadata['new_free'] ? 'Бесплатный' : 'Платный' }}
@endif
@if(isset($entry->metadata['old_status']))
· <x-status domain="user" :value="$entry->metadata['old_status']" /> → <x-status domain="user" :value="$entry->metadata['new_status']" />
@endif
</p>
@endforeach
@endif
@if($prototype && $user['status'] === 'approved')
<p>
<x-date :value="$date" /> · Администратор принял анкету</p>
@endif
</x-panel>
<x-confirmation :url="$prototype ? null : route('admin.psychologists.approve', $user['id'])" id="approve-user" title="Принять анкету?" action="Принять" kind="primary">
<p class="confirmation-object">{{ $user['name'] }}</p>
<p>Психолог сможет установить пароль и войти после подключения отправки писем.</p>
</x-confirmation>
<x-confirmation :url="$prototype ? null : route('admin.psychologists.reject', $user['id'])" id="reject-user" title="Отклонить анкету?" action="Отклонить">
<p class="confirmation-object">{{ $user['name'] }}</p>
<p>Вход в кабинет будет недоступен.</p>
</x-confirmation>
<x-confirmation :url="$prototype ? null : route('admin.psychologists.'.($user['disabled'] ? 'enable' : 'disable'), $user['id'])" id="access-user" title="Изменить доступ?" action="Подтвердить">
<p class="confirmation-object">{{ $user['name'] }}</p>
<p>Отключение доступа завершает открытые сессии психолога.</p>
</x-confirmation>
<x-confirmation :url="$prototype ? null : route('admin.psychologists.tariff', $user['id'])" :fields="['free' => $user['free'] ? 0 : 1]" id="tariff-user" title="Изменить тариф?" action="Изменить тариф" kind="primary">
<p class="confirmation-object">{{ $user['name'] }}</p>
<p>Новый тариф применяется к новым группам и следующим попыткам продления. Исторический тариф групп не меняется.</p>
</x-confirmation>
<x-confirmation :url="$prototype ? null : route('admin.psychologists.destroy', $user['id'])" method="DELETE" id="delete-user" title="Удалить психолога?" action="Удалить" :open="$variant === 'confirmation'">
<p class="confirmation-object">{{ $user['name'] }}</p>
<p>Доступ будет закрыт. Связанные платежи и история сохранятся.</p>
</x-confirmation>
@endsection
