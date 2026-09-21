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
<x-tariff :free="$user['free']" suffix="тариф" />
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
<div class="psychologist-group">
<strong>{{ $group['title'] }}</strong>
<div class="actions"><x-status :value="$group['status']" /><x-button kind="secondary" icon="arrow-up-right" :href="$links['admin-group']">Открыть группу</x-button></div>
</div>
@else
@forelse($psychologistGroups as $ownedGroup)
<div class="psychologist-group">
<strong>{{ $ownedGroup->title }}</strong>
<div class="actions"><x-status :value="$ownedGroup->status->value" /><x-button kind="secondary" icon="arrow-up-right" :href="route('admin.groups.show', $ownedGroup)">Открыть группу</x-button></div>
</div>
@empty
<p>Групп пока нет.</p>
@endforelse
<x-pagination :pages="$psychologistGroups->getUrlRange(max(1, $psychologistGroups->currentPage() - 2), min($psychologistGroups->lastPage(), $psychologistGroups->currentPage() + 2))" :current="$psychologistGroups->currentPage()" />
@endif
</x-panel>
<x-panel class="panel-secondary" title="История действий">
<ol class="timeline">
<li><strong>Анкета получена</strong><p class="meta"><x-date :value="$user['created_at']" /></p></li>
@if(!$prototype)
@foreach($history as $entry)
<li>
<strong>{{ ['user.approved'=>'Анкета принята','user.rejected'=>'Анкета отклонена','user.enabled'=>'Доступ включён','user.disabled'=>'Доступ отключён','user.tariff_changed'=>'Тариф изменён','user.deleted'=>'Психолог удалён'][$entry->action] ?? 'Действие администратора' }}</strong>
@if(isset($entry->metadata['old_free']))
<p class="actions"><x-tariff :free="$entry->metadata['old_free']" /><span aria-label="изменён на">→</span><x-tariff :free="$entry->metadata['new_free']" /></p>
@endif
@if(isset($entry->metadata['old_status']))
<p class="actions"><x-status domain="user" :value="$entry->metadata['old_status']" /><span aria-label="изменён на">→</span><x-status domain="user" :value="$entry->metadata['new_status']" /></p>
@endif
<p class="meta"><x-date :value="$entry->created_at" /> · {{ $entry->actor?->email ?? 'Система' }}</p>
</li>
@endforeach
@endif
@if($prototype && $user['status'] === 'approved')
<li><strong>Администратор принял анкету</strong><p class="meta"><x-date :value="$date" /></p></li>
@endif
</ol>
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
