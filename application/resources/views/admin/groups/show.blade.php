@extends('layouts.admin')
@php
$placementActions = [];
foreach (['withdraw' => ['withdraw', 'Снять с размещения'], 'restorePlacement' => ['restore-placement', 'Вернуть в размещение'], 'retryRenewal' => ['retry-renewal', 'Повторить публикацию']] as $ability => $action) {
    $allowed = ($realGroups ?? false) ? auth()->user()->can($ability, $groupModel)
        : ($group['status'] === 'active' && $ability === ($group['disabled'] ? 'restorePlacement' : 'withdraw'));
    if ($allowed) {
        $placementActions[$ability] = $action;
    }
}
@endphp
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Группы' , 'url' => $links['admin-groups']],['label' => $group['title']]]" />
@endsection
@section('group-actions')
<div class="detail-actions" aria-label="Модерация и действия">
<div class="actions">
@if($group['status'] === 'moderation')
@if($realGroups ?? false)
<x-button icon="check-lg" data-bs-toggle="modal" data-bs-target="#approve">Одобрить</x-button>
@else
<x-button icon="check-lg" data-noop>Одобрить</x-button>
@endif
<x-button icon="arrow-counterclockwise" kind="secondary" data-bs-toggle="modal" data-bs-target="#revision">На доработку</x-button>
<x-button icon="x-lg" kind="danger" data-bs-toggle="modal" data-bs-target="#reject">Отклонить</x-button>
@endif
@if($group['status'] === 'approved' && (!($realGroups ?? false) || auth()->user()->can('activate', $groupModel)))
<x-button icon="check-circle" data-bs-toggle="modal" data-bs-target="#activate">Отметить активной</x-button>
@endif
@foreach($placementActions as $ability => [$action, $label])
<x-button kind="secondary" data-bs-toggle="modal" :data-bs-target="'#'.$ability">{{ $label }}</x-button>
@endforeach
<x-button icon="pencil" kind="secondary" :href="($realGroups ?? false) ? route('admin.groups.edit', $group['id']) : route('prototype.admin-group-form',['variant'=>'edit'])">Редактировать</x-button>
@if(!($realGroups ?? false) || $canDelete)
<x-button icon="trash" kind="danger" data-bs-toggle="modal" data-bs-target="#delete-group">Удалить</x-button>
@endif
</div>
</div>
@endsection
@section('content')
@if($realGroups ?? false)<x-validation-summary :errors="$errors" />@endif
@if($republication ?? false)
<x-alert>{{ !empty($group['public_site_resource_id']) ? 'Продление принято. Повторная публикация выполняется через очередь. Новый срок начнётся после подтверждения публикации.' : 'Продление принято, но ID ресурса отсутствует. Администратору нужно восстановить синхронизацию и публикацию вручную.' }}</x-alert>
@endif

@include('shared.group-data')
<x-panel title="Интеграция с gruppa.info">
<p class="meta">ID группы для gruppa.info</p>
<div class="integration-id">
<p id="public_uuid">{{ $group['public_uuid'] }}</p>
<x-button icon="copy" kind="secondary" data-copy="public_uuid">Скопировать ID</x-button>
</div>
<p id="copy-feedback" role="status" class="mt-3">
</p>
<p class="small mt-3">При синхронизации этот ID передаётся на основной сайт. Первичная публикация выполняется вручную: перед первой активацией убедитесь, что группа опубликована. После продления синхронизированной группы публикацию подтверждает очередь.</p>
</x-panel>
@if($realGroups ?? false)
@include('admin.groups._modx-sync')
@if($group['modx_publication_desired'])<x-panel title="Состояние публикации"><p>{{ match($group['modx_publication_status']) { 'pending', 'syncing' => 'Ожидается подтверждение публикации основным сайтом.', 'failed' => 'Публикация не выполнена. Можно повторить действие.', 'conflict' => 'Требуется проверка конфликта публикации.', 'published' => 'Публикация подтверждена.', 'unpublished' => 'Снятие с публикации подтверждено.', default => 'Состояние ещё не подтверждено.' } }}</p></x-panel>@endif
@endif
<x-panel title="Психолог">
<a href="{{ $links['admin-user'] }}">{{ $user['name'] }}</a>
<p>{{ $user['email'] }} · {{ $user['phone'] }}</p>
</x-panel>

<x-panel title="Оплата размещения">
@if($group['free'])
<p>Бесплатная группа. Платёж не требуется.</p>
@elseif(($realGroups ?? false) && !$placementPayment)
<p>Платёж не найден.</p>
@else
<p>
<x-money :value="$payment['amount']" /> · <x-status domain="payment" :value="$payment['status']" />
</p>
<p>Заказ {{ $payment['order_number'] }}</p>
<p>Транзакция {{ $payment['transaction_id'] ?? 'Ещё не получена' }}</p>
<p>
<x-date :value="$payment['paid_at']" />
</p>
<a href="{{ ($realGroups ?? false) ? route('admin.payments.show', $placementPayment) : $links['admin-payment'] }}">Открыть платёж</a>
@endif
</x-panel>
@if(($realGroups ?? false) && $placementPayment?->status === \App\Enums\PaymentStatus::Succeeded && $group['status'] === 'rejected')<x-alert tone="warning">Сначала выполните возврат вручную в платёжном сервисе, затем отметьте его в кабинете.</x-alert>@endif
@include('admin.groups._applications')
@include('shared.group-history')@include('shared.group-delete')
@if(($realGroups ?? false) && $group['status'] === 'moderation')
<x-confirmation id="approve" title="Одобрить группу?" action="Одобрить" kind="primary" :url="route('admin.groups.approve', $group['id'])"><p class="confirmation-object">{{ $group['title'] }}</p>
<p>Группа будет ожидать ручной публикации.</p></x-confirmation>
@endif
@if(!($realGroups ?? false) || $group['status'] === 'moderation')
<x-confirmation id="revision" :url="($realGroups ?? false) ? route('admin.groups.revision', $group['id']) : null" title="Отправить на доработку" action="Отправить" kind="primary" :open="$variant === 'validation' || isset($errors['moderator_comment'])">
<p class="confirmation-object">{{ $group['title'] }}</p>
<x-textarea :form="($realGroups ?? false) ? 'revision-form' : null" :value="($realGroups ?? false) ? old('moderator_comment') : null" name="moderator_comment" label="Комментарий психологу" :required="true" :error="$errors['moderator_comment'] ?? null" />
</x-confirmation>
<x-confirmation id="reject" :url="($realGroups ?? false) ? route('admin.groups.reject', $group['id']) : null" :open="($realGroups ?? false) && isset($errors['rejection_reason'])" title="Отклонить группу" action="Отклонить">
<p class="confirmation-object">{{ $group['title'] }}</p>
<x-textarea :form="($realGroups ?? false) ? 'reject-form' : null" :value="($realGroups ?? false) ? old('rejection_reason') : null" name="rejection_reason" label="Причина отклонения" :required="true" :error="$errors['rejection_reason'] ?? null" />
@if(!($realGroups ?? false) && !$group['free'])
<x-alert tone="warning">После отклонения выполните возврат вручную в платёжном сервисе. Заказ: {{ $payment['order_number'] }}; <x-money :value="$payment['amount']" />.</x-alert>
@endif
</x-confirmation>
@endif
@if(!($realGroups ?? false) || auth()->user()->can('activate', $groupModel))
<x-confirmation id="activate" :url="($realGroups ?? false) ? route('admin.groups.activate', $group['id']) : null" title="Подтвердить публикацию" action="Отметить активной" kind="primary">
<p class="confirmation-object">{{ $group['title'] }}</p>
<p>Убедитесь, что группа опубликована на gruppa.info и ID {{ $group['public_uuid'] }} сохранён у этой группы. Срок размещения начнётся с активации.</p>
</x-confirmation>
@endif
@foreach($placementActions as $ability => [$action, $label])
<x-confirmation :id="$ability" :title="$label.'?'" :action="$label" kind="primary" :url="($realGroups ?? false) ? route('admin.groups.'.$action, $group['id']) : null"><p>{{ $group['title'] }}</p><p>{{ $ability === 'retryRenewal' ? 'Новый срок начнётся только после подтверждения публикации.' : 'Дата окончания размещения не изменится. При возврате приём заявок возобновится только после подтверждения публикации.' }}</p></x-confirmation>
@endforeach
@endsection
