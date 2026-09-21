@extends('layouts.admin')
@section('content')
@if($realGroups ?? false)<x-validation-summary :errors="$errors" />@endif
@if($republication ?? false)
<x-alert>Продление: группа ожидает ручной повторной публикации. После публикации отметьте её активной — начнётся новый срок размещения.</x-alert>
@endif
<x-panel title="Интеграция с gruppa.info">
<p class="meta">ID группы для gruppa.info</p>
<div class="integration-id">
<p id="public_uuid">{{ $group['public_uuid'] }}</p>
<x-button kind="secondary" data-copy="public_uuid">Скопировать ID</x-button>
</div>
<p id="copy-feedback" role="status" class="mt-3">
</p>
<p class="small mt-3">Сохраните этот ID у соответствующей группы на основном сайте. Перед активацией убедитесь, что группа опубликована вручную и ID сохранён. Автоматическая проверка связи не выполняется.</p>
</x-panel>
@include('shared.group-data')
<x-panel title="Модерация и действия">
<div class="actions">
@if($group['status'] === 'moderation')
@if($realGroups ?? false)
<x-button data-bs-toggle="modal" data-bs-target="#approve">Одобрить</x-button>
@else
<x-button data-noop>Одобрить</x-button>
@endif
<x-button kind="secondary" data-bs-toggle="modal" data-bs-target="#revision">На доработку</x-button>
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#reject">Отклонить</x-button>
@endif
@if($group['status'] === 'approved')
<x-button data-bs-toggle="modal" data-bs-target="#activate">Отметить активной</x-button>
@endif
<x-button kind="secondary" :href="($realGroups ?? false) ? route('admin.groups.edit', $group['id']) : route('prototype.admin-group-form',['variant'=>'edit'])">Редактировать</x-button>
@if(!($realGroups ?? false) || $canDelete)
<x-button kind="danger" :disabled="$group['has_unrefunded_payment'] ?? false" data-bs-toggle="modal" data-bs-target="#delete-group">Удалить</x-button>
@endif
</div>
@if(!($realGroups ?? false) && $group['has_unrefunded_payment'])
<x-alert tone="warning" class="mt-4">Есть успешный платёж без отметки возврата. Удаление недоступно. @if($group['status'] === 'rejected')Сначала выполните ручной возврат в WEBPAY и отметьте его в кабинете.@endif
</x-alert>
@endif
</x-panel>
@unless($realGroups ?? false)
<x-panel title="Оплата размещения">
@if($group['free'])
<p>Бесплатная группа. Платёж не требуется.</p>
@else
<p>
<x-money :value="$payment['amount']" /> · <x-status domain="payment" :value="$payment['status']" />
</p>
<p>Заказ {{ $payment['order_number'] }}</p>
<p>Транзакция {{ $payment['transaction_id'] ?? 'Ещё не получена' }}</p>
<p>
<x-date :value="$payment['paid_at']" />
</p>
<a href="{{ $links['admin-payment'] }}">Открыть платёж</a>
@endif
</x-panel>
@endunless
@include('shared.group-history')@include('shared.group-delete')
@if(($realGroups ?? false) && $group['status'] === 'moderation')
<x-confirmation id="approve" title="Одобрить группу?" action="Одобрить" kind="primary" :url="route('admin.groups.approve', $group['id'])"><p>Группа будет ожидать ручной публикации.</p></x-confirmation>
@endif
@if(!($realGroups ?? false) || $group['status'] === 'moderation')
<x-confirmation id="revision" :url="($realGroups ?? false) ? route('admin.groups.revision', $group['id']) : null" title="Отправить на доработку" action="Отправить" kind="primary" :open="$variant === 'validation' || isset($errors['moderator_comment'])">
<x-textarea :form="($realGroups ?? false) ? 'revision-form' : null" :value="($realGroups ?? false) ? old('moderator_comment') : null" name="moderator_comment" label="Комментарий психологу" :required="true" :error="$errors['moderator_comment'] ?? null" />
</x-confirmation>
<x-confirmation id="reject" :url="($realGroups ?? false) ? route('admin.groups.reject', $group['id']) : null" :open="($realGroups ?? false) && isset($errors['rejection_reason'])" title="Отклонить группу" action="Отклонить">
<x-textarea :form="($realGroups ?? false) ? 'reject-form' : null" :value="($realGroups ?? false) ? old('rejection_reason') : null" name="rejection_reason" label="Причина отклонения" :required="true" :error="$errors['rejection_reason'] ?? null" />
@if(!($realGroups ?? false) && !$group['free'])
<x-alert tone="warning">После отклонения выполните возврат вручную в WEBPAY. Заказ: {{ $payment['order_number'] }}; <x-money :value="$payment['amount']" />.</x-alert>
@endif
</x-confirmation>
@endif
@if(!($realGroups ?? false) || $group['status'] === 'approved')
<x-confirmation id="activate" :url="($realGroups ?? false) ? route('admin.groups.activate', $group['id']) : null" title="Подтвердить публикацию" action="Отметить активной" kind="primary">
<p>Убедитесь, что группа опубликована на gruppa.info и ID {{ $group['public_uuid'] }} сохранён у этой группы. Срок размещения начнётся с активации.</p>
</x-confirmation>
@endif
@endsection
