@extends('layouts.admin')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Платежи' , 'url' => $links['admin-payments']],['label' => $payment['order_number']]]" />
@endsection
@section('actions')
<x-button icon="arrow-left" kind="ghost" :href="$links['admin-payments']">К платежам</x-button>
@endsection
@section('content')
@if($payment['manual_review'])
<x-alert tone="warning">Требуется ручная проверка. Автоматические попытки завершены, результат остаётся неизвестным.</x-alert>
@endif
<x-panel title="Платёж">
@include('shared.payment-data')
</x-panel>
<x-panel title="Уведомления о платеже">
<p>{{ in_array($payment['status'], ['created','pending']) ? 'Подтверждающее уведомление ещё не получено.' : 'Демонстрационный итог: подтверждение обработано один раз.' }}</p>
<p class="meta">Вымышленная сводка. Секреты, подписи и полные ответы провайдера не отображаются.</p>
</x-panel>
@if($payment['status'] === 'refunded')
<x-panel title="Возврат учтён">
<p>{{ $payment['refund_comment'] }}</p>
<x-date :value="$payment['refunded_at']" />
</x-panel>
@elseif($payment['status'] === 'succeeded')
<x-panel title="Учёт выполненного возврата">
<x-alert tone="warning">«Отметить возврат выполненным в WEBPAY» только фиксирует уже выполненный возврат. Это действие не отправляет деньги и не вызывает API возврата. Сначала выполните возврат вручную в кабинете WEBPAY.</x-alert>
<form data-prototype-form>
<x-textarea name="refund_comment" label="Комментарий к возврату" :required="true" :error="$errors['refund_comment'] ?? null" />
<x-button kind="danger" data-bs-toggle="modal" data-bs-target="#refund">Отметить возврат выполненным в WEBPAY</x-button>
</form>
</x-panel>
<x-confirmation id="refund" title="Зафиксировать выполненный возврат?" action="Отметить возврат выполненным в WEBPAY" :open="$variant === 'confirmation'">
<p>Подтвердите, что возврат по заказу {{ $payment['order_number'] }} уже выполнен в WEBPAY. Деньги этим действием не отправляются.</p>
</x-confirmation>
@else
<x-alert>Учёт возврата доступен только для подтверждённой успешной оплаты.</x-alert>
@endif
@endsection
