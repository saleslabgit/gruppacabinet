<dl class="detail-grid">
<div>
<dt>Внутренний ID</dt>
<dd>{{ $payment['id'] }}</dd>
</div>
<div>
<dt>Номер заказа WEBPAY</dt>
<dd>{{ $payment['order_number'] }}</dd>
</div>
<div>
<dt>Транзакция WEBPAY</dt>
<dd>{{ $payment['transaction_id'] ?? 'Ещё не получена' }}</dd>
</div>
<div>
<dt>Сумма и валюта{{ ($realPayments ?? false) ? '' : ' · пример' }}</dt>
<dd>
<x-money :value="$payment['amount']" :currency="$payment['currency']" />
</dd>
</div>
<div>
<dt>Тип операции</dt>
<dd>{{ $payment['type'] === 'placement' ? 'Размещение' : 'Продление' }}</dd>
</div>
<div>
<dt>Статус</dt>
<dd>
<x-status domain="payment" :value="$payment['status']" />
</dd>
</div>
<div>
<dt>Группа</dt>
<dd>
<a href="{{ $links['admin-group'] }}">{{ $group['title'] }}</a>
</dd>
</div>
<div>
<dt>Психолог</dt>
<dd>
<a href="{{ $links['admin-user'] }}">{{ $user['name'] }}</a>
</dd>
</div>
<div>
<dt>Создан</dt>
<dd>
<x-date :value="$payment['created_at']" />
</dd>
</div>
<div>
<dt>Оплата подтверждена</dt>
<dd>
<x-date :value="$payment['paid_at']" />
</dd>
</div>
<div>
<dt>Возврат учтён</dt>
<dd>
<x-date :value="$payment['refunded_at']" />
</dd>
</div>
<div>
<dt>Последняя проверка</dt>
<dd>
<x-date :value="$payment['last_status_check_at']" />
</dd>
</div>
<div>
<dt>Число проверок</dt>
<dd>{{ $payment['status_check_attempts'] }}</dd>
</div>
</dl>
