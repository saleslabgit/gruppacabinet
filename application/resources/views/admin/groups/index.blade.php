@extends('layouts.admin')
@section('actions')
<x-button :href="$links['admin-group-form']">Создать группу</x-button>
@endsection
@section('content')
<x-panel title="Поиск и фильтры">
<form data-prototype-form>
<x-input name="search" label="ID, название или психолог" :value="$variant === 'no-results' ? 'Нет совпадений' : ''" />
<div class="row">
<div class="col-md-6">
<x-select name="status" label="Статус" :options="[''=>'Все','awaiting_payment'=>'Ожидает оплаты','draft'=>'Черновик','moderation'=>'На модерации','revision'=>'На доработке','rejected'=>'Отклонена','approved'=>'Ожидает публикации','active'=>'Активная','expired'=>'Закончена']" :value="in_array($variant,['normal','empty','long','pagination']) ? '' : $group['status']" />
</div>
<div class="col-md-6">
<x-select name="free" label="Тариф группы" :options="[''=>'Все','free'=>'Бесплатная','paid'=>'Платная']" :value="$variant" />
</div>
<div class="col-md-6">
<x-select name="successful_payment" label="Успешный платёж" :options="[''=>'Все','yes'=>'Есть','no'=>'Нет']" :value="$variant === 'successful-payment' ? 'yes' : ''" />
</div>
<div class="col-md-6">
<x-select name="sort" label="Сортировка" :options="['created_at'=>'Дата создания','published_at'=>'Дата публикации','expires_at'=>'Дата окончания']" />
</div>
<div class="col-md-6">
<x-input name="created_before" label="Созданы до, Минск" type="date" :value="$variant === 'abandoned' ? '2026-08-20' : ''" />
</div>
</div>
<x-button kind="secondary" data-noop>Применить</x-button>
</form>
<div class="actions mt-4">
@foreach(['approved'=>'Ожидают публикации','expired'=>'Снять с публикации','abandoned'=>'Брошенные черновики'] as $state=>$label)
<a href="{{ route('prototype.admin-groups',['variant'=>$state]) }}">{{ $label }}</a>
@endforeach
</div>
</x-panel>
@if($empty)
<x-empty title="Группы не найдены" text="Попробуйте изменить фильтры." />
@else
@foreach($groups as $group)
<x-panel>
<div class="group-row">
<div>
<p class="eyebrow">Внутренний ID {{ $group['id'] }}</p>
<h2>{{ $group['title'] }}</h2>
<p>
<a href="{{ $links['admin-user'] }}">{{ $user['name'] }}</a>
</p>
@include('shared.group-summary')
</div>
<div>
<h3>Действия</h3>
<x-button :href="route('prototype.admin-group',['variant'=>$group['status']])">Открыть группу</x-button>
</div>
</div>
</x-panel>
@endforeach
<x-pagination :pages="$pages" :current="$currentPage" />
@endif
@endsection
