@extends('layouts.psychologist')
@section('content')
<x-panel>
@if($payment['status'] === 'pending')
<x-alert tone="warning" title="Оплата подтверждается WEBPAY">
<p>Подтверждение ещё не получено. Возврат или отмена в браузере не подтверждают финансовый результат. Не повторяйте оплату, пока статус неизвестен.</p>
</x-alert>
@elseif($payment['status'] === 'succeeded')
<x-alert tone="success" title="Оплата подтверждена">
<p>{{ $variant === 'extension-expired' ? 'Группа ожидает повторной ручной публикации администратором. Повторная модерация не требуется.' : ($variant === 'extension-active' ? 'Размещение активной группы продлено. Действия администратора не нужны.' : 'Теперь можно заполнить анкету группы.') }}</p>
</x-alert>
@else
<x-alert tone="warning" :title="$payment['status'] === 'failed' ? 'Оплата не прошла' : 'Отмена оплаты подтверждена'">
<p>Показан пример результата, подтверждённого сервером. Можно начать новую попытку.</p>
</x-alert>
@endif
<dl class="detail-grid mb-4">
<div>
<dt>Номер заказа</dt>
<dd>{{ $payment['order_number'] }}</dd>
</div>
<div>
<dt>Демонстрационная сумма</dt>
<dd>
<x-money :value="$payment['amount']" />
</dd>
</div>
</dl>
<div class="actions">
@if($payment['status'] === 'pending')
<x-button :href="url()->current()">Обновить страницу</x-button>
@elseif($payment['status'] === 'succeeded')
<x-button :href="$links[$payment['type'] === 'extension' ? 'group' : 'group-form']">{{ $payment['type'] === 'extension' ? 'К группе' : 'Заполнить группу' }}</x-button>
@else
<x-button :href="route('prototype.placement', ['variant' => 'retry'])">Повторить оплату</x-button>
@endif
<x-button kind="ghost" :href="$links['groups']">К моим группам</x-button>
</div>
</x-panel>
@endsection
