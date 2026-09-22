@extends('layouts.psychologist')
@section('breadcrumbs')
<x-breadcrumbs :items="[['label' => 'Мои группы' , 'url' => $links['groups']],['label' => $group['title'] ?: 'Новая группа' , 'url' => ($realGroups ?? false) ? route('psychologist.groups.show', $group['id']) : $links['group']],['label' => 'Продление']]" />
@endsection
@section('content')
<x-panel title="Продлить размещение">
<h3>{{ $group['title'] }}</h3>
<p>Тариф продления определяется по вашему текущему тарифу.</p>
@if($realGroups ?? false)
<x-validation-summary :errors="$errors" />
<p><strong>{{ $group['current_owner_free'] ? 'Бесплатное продление' : 'Платное продление' }}</strong></p>
<p>Текущая дата окончания: <x-date :value="$group['expires_at']" /></p>
@if($pendingPayment ?? null)
<x-alert>У вас есть незавершённая попытка продления. Её тариф и сумма сохранены.</x-alert>
<x-button :href="route('psychologist.payments.show', $pendingPayment)">Посмотреть оплату</x-button>
@elseif($group['outside_window'])
<x-alert tone="warning">Срок продления закончился. Создайте новую группу.</x-alert>
<form method="POST" action="{{ route('psychologist.groups.store') }}">@csrf<x-button icon="plus-lg" type="submit">Создать группу</x-button></form>
@elseif(!$group['current_owner_free'])
@if($extensionPrice === null || $extensionPrice <= 0)
<x-alert>Стоимость оплаты не настроена. Обратитесь к администратору.</x-alert>
@elseif($group['expiry_due'])
<x-alert>Срок размещения истёк. Дождитесь обновления статуса и обновите страницу.</x-alert>
@else
<p>Стоимость продления: <x-money :value="$extensionPrice" /></p>
<x-alert>{{ $group['status'] === 'expired' ? 'После оплаты группа будет ожидать повторной ручной публикации.' : 'Размещение будет продлено после подтверждения оплаты WEBPAY.' }}</x-alert>
<form method="POST" action="{{ route('psychologist.groups.extend', $group['id']) }}">@csrf<input type="hidden" name="confirmed" value="1"><x-button type="submit">Оплатить продление через WEBPAY</x-button></form>
@endif
@elseif($group['expiry_due'])
<x-alert tone="warning">Срок размещения истёк. Дождитесь обновления статуса и обновите страницу.</x-alert>
@elseif(!$group['can_extend'])
<x-alert tone="warning">Продление недоступно для текущего срока размещения. Обратитесь к администратору.</x-alert>
@else
@if($group['status'] === 'expired')
<x-alert>После продления группа вернётся в статус «Одобрена, ожидает публикации». Администратор должен вручную опубликовать её повторно и отметить активной. Даты будут установлены при активации; повторная модерация не нужна.</x-alert>
<p>Продление доступно до: <x-date :value="$group['extension_deadline']" /></p>
@else
<x-alert>К текущей дате окончания добавится {{ $group['placement_days'] }} дн. Группа останется активной; действия администратора не требуются.</x-alert>
@endif
<x-button icon="calendar-plus" data-bs-toggle="modal" data-bs-target="#extend-group">Продлить бесплатно</x-button>
<x-confirmation id="extend-group" title="Продлить размещение?" action="Продлить бесплатно" kind="primary" :url="route('psychologist.groups.extend', $group['id'])">
<p>{{ $group['status'] === 'expired' ? 'Группа будет ожидать ручной повторной публикации администратором.' : 'К текущей дате окончания добавится '.$group['placement_days'].' дн.' }}</p>
</x-confirmation>
@endif
@else
@if($variant === 'outside-window')
<x-alert tone="warning">Срок продления закончился. Создайте новую группу.</x-alert>
<x-button icon="plus-lg" :href="$links['group-form']">Создать группу</x-button>
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
@endif
<p class="mt-4">
<a href="{{ ($realGroups ?? false) ? route('psychologist.groups.show', $group['id']) : $links['group'] }}">Вернуться к группе</a>
</p>
</x-panel>
@endsection
