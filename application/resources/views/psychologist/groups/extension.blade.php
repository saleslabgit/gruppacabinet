@extends('layouts.psychologist')
@section('content')
<x-panel title="Продлить размещение">
<h3>{{ $group['title'] }}</h3>
<p>Тариф продления определяется по вашему текущему тарифу.</p>
@if($variant === 'outside-window')
<x-alert tone="warning">Срок продления закончился. Создайте новую группу.</x-alert>
<x-button :href="$links['group-form']">Создать группу</x-button>
@elseif($variant === 'pending')
<x-alert tone="warning">Оплата подтверждается WEBPAY. Даты размещения пока не изменены.</x-alert>
<x-button :href="$links['payment-pending']">Посмотреть состояние</x-button>
@else
<p>
<strong>{{ str_starts_with($variant, 'free') ? 'Бесплатное продление' : 'Платное продление' }}</strong>
</p>
@if(str_starts_with($variant, 'paid'))
<p>Демонстрационная стоимость: <x-money :value="5000" />
</p>
@endif
<x-alert>{{ str_ends_with($variant, 'expired') ? 'После продления группа ожидает ручной повторной публикации администратором. Даты будут установлены при публикации; повторная модерация не нужна.' : 'К текущей дате окончания добавятся 30 дней. Группа останется активной; действия администратора не требуются.' }}</x-alert>
<x-button data-noop>{{ str_starts_with($variant, 'free') ? 'Продлить бесплатно' : 'Оплатить продление через WEBPAY' }}</x-button>
@endif
<p class="mt-4">
<a href="{{ $links['group'] }}">Вернуться к группе</a>
</p>
</x-panel>
@endsection
