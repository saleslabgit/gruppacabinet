@extends('layouts.psychologist')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Мои группы' , 'url' => $links['groups']],['label' => $group['title'] , 'url' => $links['group']],['label' => $title]]" />
@endsection
@section('content')
<x-panel title="Размещение новой группы">
<h3>{{ $group['title'] }}</h3>
<p class="meta">Демонстрационная стоимость</p>
<p class="mb-4">
<x-money :value="$payment['amount']" />
</p>
<x-alert>Форма группы откроется после подтверждения оплаты WEBPAY. Срок размещения начнётся после ручной публикации администратором.</x-alert>
<div class="actions">
<x-button data-noop :disabled="$variant === 'disabled'">{{ $variant === 'retry' ? 'Повторить оплату через WEBPAY' : 'Оплатить через WEBPAY' }}</x-button>
<x-button icon="arrow-left" kind="ghost" :href="$links['groups']">К моим группам</x-button>
</div>
</x-panel>
@endsection
