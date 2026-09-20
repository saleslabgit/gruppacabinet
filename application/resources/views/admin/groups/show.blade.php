@extends('layouts.admin')
@section('content')
<x-panel title="Интеграция с gruppa.info">
<p class="meta">ID группы для gruppa.info</p>
<p id="public_uuid" class="mb-4">{{ $group['public_uuid'] }}</p>
<x-button kind="secondary" data-copy="public_uuid">Скопировать ID</x-button>
<p id="copy-feedback" role="status" class="mt-3">
</p>
<p class="mt-3">Сохраните этот ID у соответствующей группы на основном сайте. Перед активацией убедитесь, что группа опубликована вручную и ID сохранён. Автоматическая проверка связи не выполняется.</p>
</x-panel>
<x-panel title="Психолог">
<a href="{{ $links['admin-user'] }}">{{ $user['name'] }}</a>
<p>{{ $user['email'] }} · {{ $user['phone'] }}</p>
</x-panel>
@include('shared.group-data')
<x-panel title="Модерация и действия">
<div class="actions">
@if($group['status'] === 'moderation')
<x-button data-noop>Одобрить</x-button>
<x-button kind="secondary" data-bs-toggle="modal" data-bs-target="#revision">На доработку</x-button>
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#reject">Отклонить</x-button>
@endif
@if($group['status'] === 'approved')
<x-button data-bs-toggle="modal" data-bs-target="#activate">Отметить активной</x-button>
@endif
<x-button kind="secondary" :href="route('prototype.admin-group-form',['variant'=>'edit'])">Редактировать</x-button>
<x-button kind="danger" :disabled="$group['has_unrefunded_payment']" data-bs-toggle="modal" data-bs-target="#delete-group">Удалить</x-button>
</div>
@if($group['has_unrefunded_payment'])
<x-alert tone="warning" class="mt-4">Есть успешный платёж без отметки возврата. Удаление недоступно. @if($group['status'] === 'rejected')Сначала выполните ручной возврат в WEBPAY и отметьте его в кабинете.@endif
</x-alert>
@endif
</x-panel>
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
@include('shared.group-history')@include('shared.group-delete')
<x-confirmation id="revision" title="Отправить на доработку" action="Отправить" kind="primary" :open="$variant === 'validation'">
<x-textarea name="moderator_comment" label="Комментарий психологу" :required="true" :error="$errors['moderator_comment'] ?? null" />
</x-confirmation>
<x-confirmation id="reject" title="Отклонить группу" action="Отклонить">
<x-textarea name="rejection_reason" label="Причина отклонения" :required="true" :error="$errors['rejection_reason'] ?? null" />
@unless($group['free'])
<x-alert tone="warning">После отклонения выполните возврат вручную в WEBPAY. Заказ: {{ $payment['order_number'] }}; <x-money :value="$payment['amount']" />.</x-alert>
@endunless
</x-confirmation>
<x-confirmation id="activate" title="Подтвердить публикацию" action="Отметить активной" kind="primary">
<p>Убедитесь, что группа опубликована на gruppa.info и ID {{ $group['public_uuid'] }} сохранён у этой группы. Срок размещения начнётся с активации.</p>
</x-confirmation>
@endsection
