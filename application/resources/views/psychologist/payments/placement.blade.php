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
@if($payment['type'] === 'extension')
<x-alert>Продление применяется только после подтверждения оплаты WEBPAY.</x-alert>
@else
<x-alert>Форма группы откроется после подтверждения оплаты WEBPAY. Срок размещения начнётся после ручной публикации администратором.</x-alert>
@endif
<div class="actions">
@if($realPayments ?? false)
@if(isset($providerForm))
<form method="POST" action="{{ $providerForm['url'] }}">
@foreach($providerForm['fields'] as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
<x-button type="submit">Перейти в WEBPAY</x-button>
</form>
@else
<form method="POST" action="{{ route('psychologist.payments.start', $payment['id']) }}">@csrf<x-button type="submit">Оплатить через WEBPAY</x-button></form>
@endif
@else
<x-button data-noop :disabled="$variant === 'disabled'">{{ $variant === 'retry' ? 'Повторить оплату через WEBPAY' : 'Оплатить через WEBPAY' }}</x-button>
@endif
<x-button icon="arrow-left" kind="ghost" :href="$links['groups']">К моим группам</x-button>
</div>
</x-panel>
@endsection
