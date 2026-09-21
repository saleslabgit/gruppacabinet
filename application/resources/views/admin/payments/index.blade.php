@extends('layouts.admin')
@section('content')
<x-panel title="Поиск платежей" :compact="true" class="panel-compact">
<form data-prototype-form>
<x-input name="search" label="Номер заказа или транзакции" />
<div class="row">
<div class="col-md-6">
<x-select name="status" label="Статус" :options="[''=>'Все','created'=>'Создан','pending'=>'Ожидает подтверждения','succeeded'=>'Подтверждён','failed'=>'Неуспешен','cancelled'=>'Отменён','refunded'=>'Возврат учтён']" :value="$variant === 'normal' ? '' : $payment['status']" />
</div>
<div class="col-md-6">
<x-select name="type" label="Тип платежа" :options="[''=>'Все','placement'=>'Размещение','extension'=>'Продление']" />
</div>
<div class="col-md-6">
<x-select name="owner_id" label="Психолог" :options="[''=>'Все','demo'=>$user['name']]" />
</div>
<div class="col-md-3">
<x-input name="from" label="Период с, Минск" type="date" />
</div>
<div class="col-md-3">
<x-input name="to" label="Период до, Минск" type="date" />
</div>
</div>
<x-button kind="secondary" data-noop>Применить</x-button>
</form>
</x-panel>
@if($variant === 'pre-webpay')
<x-empty title="Платежи ещё не подключены" text="До подключения WEBPAY новые группы создаются по бесплатному сценарию. Здесь появятся платежи после запуска интеграции." />
@elseif($empty)
<x-empty title="Платежи не найдены" text="Проверьте выбранный период и фильтры." />
@else
@if($payment['manual_review'])
<x-alert tone="warning">Требуется ручная проверка. Достоверный ответ не получен; платёж остаётся в ожидании подтверждения.</x-alert>
@endif
<x-table :headers="['Заказ и дата','Психолог и группа','Сумма и состояние','Действия']">
<tr>
<x-cell label="Заказ и дата">
<strong>{{ $payment['order_number'] }}</strong>
<p>Транзакция: {{ $payment['transaction_id'] ?? 'Не получена' }}</p>
<p>
<x-date :value="$payment['created_at']" />
</p>
</x-cell>
<x-cell label="Психолог и группа">
<a href="{{ $links['admin-user'] }}">{{ $user['name'] }}</a>
<p>
<a href="{{ $links['admin-group'] }}">{{ $group['title'] }}</a>
</p>
</x-cell>
<x-cell label="Сумма и состояние">
<p>
<x-money :value="$payment['amount']" />
</p>
<p>{{ $payment['type'] === 'placement' ? 'Размещение' : 'Продление' }}</p>
<x-status domain="payment" :value="$payment['status']" />
</x-cell>
<x-cell label="Действия">
<a href="{{ route('prototype.admin-payment',['variant'=>$payment['manual_review'] ? 'manual-review' : $payment['status']]) }}">Открыть</a>
</x-cell>
</tr>
</x-table>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
@endsection
