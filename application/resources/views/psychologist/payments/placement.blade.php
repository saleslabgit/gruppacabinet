@extends('layouts.psychologist')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Мои группы' , 'url' => $links['groups']],['label' => $group['title'] , 'url' => $links['group']],['label' => $title]]" />
@endsection
@section('content')
<x-panel :title="$payment['type'] === 'extension' ? 'Продление размещения' : 'Размещение новой группы'">
@if($realPayments ?? false)<x-validation-summary :errors="$errors" />@endif
<h3>{{ $group['title'] }}</h3>
<p class="meta">{{ ($realPayments ?? false) ? 'Стоимость' : 'Демонстрационная стоимость' }}</p>
<p class="mb-4">
<x-money :value="$payment['amount']" />
</p>
@if(($realPayments ?? false) && isset($providerForm))
<p role="status">Переходим к оплате…</p>
<p class="meta">Если переход не произошёл, нажмите «Перейти к оплате».</p>
@elseif($payment['type'] === 'extension')
<x-alert>Продление применяется только после подтверждения оплаты платёжным сервисом.</x-alert>
@else
<x-alert>Форма группы откроется после подтверждения оплаты платёжным сервисом. Срок размещения начнётся после ручной публикации администратором.</x-alert>
@endif
<div class="actions">
@if($realPayments ?? false)
@if(isset($providerForm))
<form method="POST" action="{{ $providerForm['url'] }}" data-webpay-auto-submit>
@foreach($providerForm['fields'] as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
<x-button type="submit">Перейти к оплате</x-button>
</form>
@else
<form method="POST" action="{{ route('psychologist.payments.start', $payment['id']) }}">@csrf<x-button type="submit">Оплатить картой</x-button></form>
@endif
@else
<x-button data-noop :disabled="$variant === 'disabled'">{{ $variant === 'retry' ? 'Повторить оплату' : 'Оплатить картой' }}</x-button>
@endif
<x-button icon="arrow-left" kind="ghost" :href="$links['groups']">К моим группам</x-button>
</div>
</x-panel>
@endsection
