@extends('layouts.psychologist')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Мои группы' , 'url' => $links['groups']],['label' => $group['title'] , 'url' => $links['group']],['label' => $title]]" />
@endsection
@section('content')
<x-panel>
@if($realPayments ?? false)<x-validation-summary :errors="$errors" />@endif
@if($payment['manual_review'] ?? false)<x-alert tone="warning">Требуется ручная проверка в WEBPAY. Оплата пока не подтверждена. Обратитесь к администратору.</x-alert>@endif
@if($payment['status'] === 'pending')
<x-alert tone="warning" title="Оплата подтверждается WEBPAY">
<p>Подтверждение ещё не получено. Возврат или отмена в браузере не подтверждают финансовый результат. {{ ($realPayments ?? false) ? 'Не создавайте новую попытку, пока статус неизвестен.' : 'Не повторяйте оплату, пока статус неизвестен.' }}</p>
</x-alert>
@elseif($payment['status'] === 'refunded')
<x-alert>Возврат учтён.</x-alert>
@elseif($payment['status'] === 'succeeded')
<x-alert tone="success" title="Оплата подтверждена">
<p>{{ $variant === 'extension-expired' ? 'Группа ожидает повторной ручной публикации администратором. Повторная модерация не требуется.' : ($variant === 'extension-active' ? 'Размещение активной группы продлено. Действия администратора не нужны.' : 'Теперь можно заполнить анкету группы.') }}</p>
</x-alert>
@else
<x-alert tone="warning" :title="$payment['status'] === 'failed' ? 'Оплата не прошла' : 'Отмена оплаты подтверждена'">
<p>{{ ($realPayments ?? false) ? 'Результат подтверждён WEBPAY.' : 'Показан пример результата, подтверждённого сервером. Можно начать новую попытку.' }}</p>
</x-alert>
@endif
<dl class="detail-grid mb-4">
<div>
<dt>Номер заказа</dt>
<dd>{{ $payment['order_number'] }}</dd>
</div>
<div>
<dt>{{ ($realPayments ?? false) ? 'Сумма' : 'Демонстрационная сумма' }}</dt>
<dd>
<x-money :value="$payment['amount']" />
</dd>
</div>
</dl>
<div class="actions">
@if($payment['status'] === 'pending')
<x-button :href="url()->current()">Обновить страницу</x-button>
@if($realPayments ?? false)
<form method="POST" action="{{ route('psychologist.payments.start', $payment['id']) }}">@csrf<x-button type="submit" kind="secondary">Продолжить эту оплату</x-button></form>
@endif
@elseif($payment['status'] === 'succeeded')
<x-button :href="$links[$payment['type'] === 'extension' ? 'group' : 'group-form']">{{ $payment['type'] === 'extension' ? 'К группе' : 'Заполнить группу' }}</x-button>
@else
@if($realPayments ?? false)
@if(in_array($payment['status'], ['failed', 'cancelled']))
<form method="POST" action="{{ route('psychologist.payments.retry', $payment['id']) }}">@csrf<x-button type="submit">Повторить оплату</x-button></form>
@endif
@else
<x-button :href="route('prototype.placement', ['variant' => 'retry'])">Повторить оплату</x-button>
@endif
@endif
<x-button icon="arrow-left" kind="ghost" :href="$links['groups']">К моим группам</x-button>
</div>
</x-panel>
@endsection
