@extends('layouts.admin')
@section('content')
<x-panel title="Поиск платежей" :compact="true" class="panel-compact filter-panel">
<form method="GET" @if(!($realPayments ?? false)) data-prototype-form @endif>
@if($realPayments ?? false)<x-validation-summary :errors="$errors" />@endif
<x-input name="search" :value="$filters['search'] ?? null" label="Номер заказа или транзакции" />
<div class="row">
<div class="col-md-6">
<x-select name="status" label="Статус" :options="[''=>'Все','created'=>'Создан','pending'=>'Ожидает подтверждения','succeeded'=>'Подтверждён','failed'=>'Неуспешен','cancelled'=>'Отменён','refunded'=>'Возврат учтён']" :value="($realPayments ?? false) ? ($filters['status'] ?? '') : ($variant === 'normal' ? '' : $payment['status'])" />
</div>
<div class="col-md-6">
<x-select name="type" :value="$filters['type'] ?? null" label="Тип платежа" :options="[''=>'Все','placement'=>'Размещение','extension'=>'Продление']" />
</div>
<div class="col-md-6">
<x-select name="owner_id" label="Психолог" :options="($realPayments ?? false) ? $ownerOptions : [''=>'Все','demo'=>$user['name']]" :value="$filters['owner_id'] ?? null" />
</div>
<div class="col-md-3">
<x-input name="from" :value="$filters['from'] ?? null" label="Период с, Минск" type="date" />
</div>
<div class="col-md-3">
<x-input name="to" :value="$filters['to'] ?? null" label="Период до, Минск" type="date" />
</div>
</div>
<x-button icon="search" kind="secondary" :type="($realPayments ?? false) ? 'submit' : 'button'">Применить</x-button>
</form>
</x-panel>
@if($variant === 'pre-webpay')
<x-empty title="Платежи ещё не подключены" text="До подключения WEBPAY новые группы создаются по бесплатному сценарию. Здесь появятся платежи после запуска интеграции." />
@elseif($empty)
<x-empty title="Платежи не найдены" text="Проверьте выбранный период и фильтры." />
@else
@if(!($realPayments ?? false) && $payment['manual_review'])
<x-alert tone="warning">Требуется ручная проверка. Достоверный ответ не получен; платёж остаётся в ожидании подтверждения.</x-alert>
@endif
<x-table :headers="['Заказ и дата','Психолог и группа','Сумма и состояние','Действия']">
@foreach(($realPayments ?? false) ? $payments : [$payment] as $payment)
<tr>
<x-cell label="Заказ и дата">
<strong>{{ $payment['order_number'] }}</strong>
<p>Транзакция: {{ $payment['transaction_id'] ?? 'Не получена' }}</p>
<p>
<x-date :value="$payment['created_at']" />
</p>
</x-cell>
<x-cell label="Психолог и группа">
<a href="{{ ($realPayments ?? false) ? route('admin.psychologists.show', $payment['owner_id']) : $links['admin-user'] }}">{{ ($realPayments ?? false) ? $payment['owner_name'] : $user['name'] }}</a>
<p>
<a href="{{ ($realPayments ?? false) ? route('admin.groups.show', $payment['group_id']) : $links['admin-group'] }}">{{ ($realPayments ?? false) ? $payment['group_title'] : $group['title'] }}</a>
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
<a href="{{ ($realPayments ?? false) ? route('admin.payments.show', $payment['id']) : route('prototype.admin-payment',['variant'=>$payment['manual_review'] ? 'manual-review' : $payment['status']]) }}">Открыть</a>
@if(($realPayments ?? false) && $payment['manual_review'])<p>Требуется ручная проверка</p>@endif
</x-cell>
</tr>
@endforeach
</x-table>
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
@endsection
